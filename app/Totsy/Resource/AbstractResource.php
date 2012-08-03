<?php

/**
 * @category   Totsy
 * @package    Totsy\Resource
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

namespace Totsy\Resource;

use Sonno\Annotation\GET,
    Sonno\Annotation\Path,
    Sonno\Annotation\Produces,
    Sonno\Annotation\Context,
    Sonno\Annotation\PathParam,
    Sonno\Annotation\QueryParam,
    Sonno\Http\Response\Response,

    Totsy\Exception\WebApplicationException,

    Doctrine\Common\Cache\CacheProvider,
    Doctrine\Common\Cache\ApcCache,
    Doctrine\Common\Cache\MemcacheCache,

    Memcache,

    Monolog\Logger,
    Monolog\Handler\StreamHandler,
    Monolog\Handler\NativeMailerHandler,
    Monolog\Processor\WebProcessor,

    Mage;

/**
 * The base class for all supported Totsy resource classes.
 */
abstract class AbstractResource
{
    /**
     * The Magento model group name that this resource represents.
     *
     * @var string
     */
    protected $_modelGroupName;

    /**
     * The Magento model that this resource represents.
     *
     * @var Magento_Core_Model_Abstract
     */
    protected $_model;

    /**
     * The local cache data store.
     *
     * @var Doctrine\Common\Cache\CacheProvider
     */
    protected $_cache;

    /**
     * The default lifetime for cache entries added by this resource class.
     * Expressed in seconds.
     *
     * @var int
     */
    protected $_cacheEntryLifetime = 60;

    /**
     * The incoming HTTP request.
     *
     * @var \Sonno\Http\Request\RequestInterface
     * @Context("Request")
     */
    protected $_request;

    /**
     * Information about the incoming URI.
     *
     * @var \Sonno\Uri\UriInfo
     * @Context("UriInfo")
     */
    protected $_uriInfo;

    /**
     * Logging object.
     *
     * @var \Monolog\Logger;
     */
    protected $_logger;

    public function __construct()
    {
        $this->_model = Mage::getSingleton($this->_modelGroupName);

        $this->_initLogger();
        $this->_initCache();
    }

    /**
     * Construct a response (application/json) of a entity collection from the
     * local model.
     *
     * @param $filters array The set of Magento ORM filters to apply.
     * @return string json-encoded
     */
    public function getCollection($filters = array())
    {
        // hollow items are ID values only
        $hollowItems = $this->_model->getCollection();

        if ($hollowItems instanceof \Mage_Eav_Model_Entity_Collection_Abstract) {
            foreach ($filters as $filterName => $condition) {
                $hollowItems->addAttributeToFilter($filterName, $condition);
            }
        } else {
            foreach ($filters as $filterName => $condition) {
                $hollowItems->addFilter($filterName, $condition);
            }
        }

        $results = array();
        foreach ($hollowItems as $hollowItem) {
            $item = $this->_model->load($hollowItem->getId());
            $results[] = $this->_formatItem($item);
        }

        $response = json_encode($results);
        $this->_addCache($response);

        return $response;
    }

    /**
     * Construct a response (application/json) of a entity from the local model.
     *
     * @param $id int
     * @return string json-encoded
     */
    public function getItem($id)
    {
        $item = $this->_model->load($id);

        if ($item->isObjectNew()) {
            return new Response(404);
        }

        return json_encode($this->_formatItem($item));
    }

    /**
     * @param $item Mage_Core_Model_Abstract
     * @param $fields array|null
     * @param $links array|null
     * @return array
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $sourceData    = ($item instanceof \Mage_Core_Model_Abstract)
            ? $item->getData()
            : array();
        $formattedData = array();

        if (is_null($fields)) {
            $fields = isset($this->_fields) ? $this->_fields : array();
        }
        if (is_null($links)) {
            $links = isset($this->_links) ? $this->_links : array();
        }

        // add selected data from incoming $sourceData to output $formattedData
        foreach ($fields as $outputFieldName => $dataFieldName) {
            if (is_int($outputFieldName)) {
                $outputFieldName = $dataFieldName;
            }

            // data field is an embedded object
            if (is_array($dataFieldName)) {
                $formattedData[$outputFieldName] = $this->_formatItem(
                    $item,
                    $dataFieldName,
                    array()
                );

            // data field is an alias of an existing field
            } else if (is_string($dataFieldName)) {
                $formattedData[$outputFieldName] = isset($sourceData[$dataFieldName])
                    ? $sourceData[$dataFieldName]
                    : NULL;
            }
        }

        // populate hyperlinks if necessary
        if ($links && count($links)) {
            $formattedData['links'] = array();

            foreach ($links as $link) {
                $builder = $this->_uriInfo->getBaseUriBuilder();

                // the link's "href" was provided explicitly
                if (isset($link['href'])) {
                    // as a relative URI
                    if (strpos($link['href'], '://') === false) {
                        $link['href'] = $builder->replaceQuery(null)
                            ->path($link['href'])
                            ->buildFromMap($sourceData);
                    }
                } else if (isset($link['resource'])) {
                    $link['href'] = $builder->replaceQuery(null)
                        ->resourcePath($link['resource']['class'], $link['resource']['method'])
                        ->build();
                    unset($link['resource']);
                }

                $formattedData['links'][] = $link;
            }
        }

        return $formattedData;
    }

    /**
     * Populate a Magento model object with an array of data, and persist the
     * updated object.
     *
     * @param $obj Mage_Core_Model_Abstract
     * @param $data array The data to populate, or NULL which will use the
     *                    incoming request data.
     * @return bool
     * @throws Sonno\Application\WebApplicationException
     */
    protected function _populateModelInstance($obj, $data = NULL)
    {
        if (is_null($data)) {
            $data = json_decode($this->_request->getRequestBody(), true);
            if (is_null($data)) {
                throw new WebApplicationException(
                    400,
                    'Malformed entity representation in request body'
                );
            }
        }

        // rewrite keys in the data array for any aliased keys
        foreach ($this->_fields as $outputFieldName => $dataFieldName) {
            if (is_string($outputFieldName) && isset($data[$outputFieldName])) {
                $data[$dataFieldName] = $data[$outputFieldName];
                unset($data[$outputFieldName]);
            }
        }

        $obj->addData($data);

        if (method_exists($obj, 'validate')) {
            $validationErrors = $obj->validate();
            if (is_array($validationErrors) && count($validationErrors)) {
                throw new WebApplicationException(
                    400,
                    "Entity Validation Error: " . $validationErrors[0]
                );
            }
        }

        try {
            $obj->save();
        } catch(\Mage_Core_Exception $mageException) {
            $this->_logger->err($mageException->getMessage());
            throw new WebApplicationException(400, $mageException->getMessage());
        } catch(\Exception $e) {
            $this->_logger->err($e->getMessage());
            throw new WebApplicationException(500, $e);
        }

        return true;
    }

    /**
     * Check the local cache for a copy of a response body that can fulfill the
     * current request.
     *
     * @return bool|mixed
     */
    protected function _inspectCache()
    {
        // ignore cache
        if ('dev' == API_ENV || // in a development environment
            $this->_request->getQueryParam('skipCache')
        ) {
            return false;
        }

        $cacheKey = md5(
            $this->_request->getRequestUri() .
                http_build_query($this->_request->getQueryParams())
        );

        if ($this->_cache->contains($cacheKey)) {
            $this->_logger->info(
                'Delivering content from cache',
                array('key' => $cacheKey)
            );
            return $this->_cache->fetch($cacheKey);
        }

        return false;
    }

    /**
     * Add a new entry to the local cache store.
     *
     * @param mixed $value The object to store.
     * @param int   $lifetime The lifetime of the cache entry (in seconds)
     *
     * @return bool TRUE when the cache entry was added successfully.
     */
    protected function _addCache($value, $lifetime = 0)
    {
        $cacheKey = md5(
            $this->_request->getRequestUri() .
                http_build_query($this->_request->getQueryParams())
        );

        if (0 == $lifetime) {
            $lifetime = $this->_cacheEntryLifetime;
        }

        if ('dev' != API_ENV && !$this->_cache->contains($cacheKey)) {
            $this->_logger->info(
                'New cache entry added',
                array(
                    'key' => $cacheKey,
                    'size' => strlen($value),
                    'lifetime' => $lifetime,
                )
            );

            return $this->_cache->save($cacheKey, $value, $lifetime);
        }
    }

    /**
     * Parse the integer entity ID value from a resource URL.
     *
     * @param $url string The resource URL.
     *
     * @return int
     * @throws \Totsy\Exception\WebApplicationException
     */
    protected function _getEntityIdFromUrl($url)
    {
        $offset = strrpos($url, '/');
        if ($offset === false) {
            throw new WebApplicationException(
                400,
                "Invalid Resource URL $link[href]"
            );
        }

        return intval(substr($url, $offset+1));
    }

    /**
     * Setup the local logger object based on settings in an external file.
     *
     * @param string $configFile The file containing logging settings.
     * @return void
     */
    protected function _initLogger($configFile = 'etc/logger.yaml')
    {
        if (extension_loaded('yaml') && file_exists($configFile)) {
            $config = yaml_parse_file($configFile);
            $config = $config[API_ENV];

            $this->_logger = new Logger('restapi');

            // setup a processor that adds request information to log records
            $request = &$this->_request;
            $this->_logger->pushProcessor(new WebProcessor());

            // add handlers for each specified handler in the config file
            foreach ($config as $confLogger) {
                $level = isset($confLogger['level'])
                    ? constant('\Monolog\Logger::' . $confLogger['level'])
                    : Logger::NOTICE;

                switch ($confLogger['handler']) {
                    case 'file':
                        $this->_logger->pushHandler(
                            new StreamHandler($confLogger['filename']),
                            $level
                        );
                        break;
                    case 'mail':
                        $this->_logger->pushHandler(
                            new NativeMailerHandler(
                                $confLogger['recipient'],
                                $confLogger['subject'],
                                $confLogger['sender']
                            ),
                            $level
                        );
                }
            }
        }
    }

    /**
     * Setup the local cache object based on settings in an external file.
     *
     * @param string $configFile The file containing cache settings.
     * @return void
     */
    protected function _initCache($configFile = 'etc/cache.yaml')
    {
        if (extension_loaded('yaml') && file_exists($configFile)) {
            $config = yaml_parse_file($configFile);
            $config = $config[API_ENV];

            switch ($config['backend']) {
                case 'memcache':
                    $memcache = new Memcache();
                    foreach ($config['servers'] as $server) {
                        $memcache->addServer($server['host'], $server['port']);
                    }

                    $this->_cache = new MemcacheCache();
                    $this->_cache->setMemcache($memcache);

                    if (isset($config['namespace'])) {
                        $this->_cache->setNamespace($config['namespace']);
                    }

                    break;
                default:
                    $this->_cache = new ApcCache();
            }
        }
    }

    /**
     * Get the current time in the Magento-configured local timezone.
     *
     * @return int
     */
    protected function _getCurrentTime()
    {
        // remember the currently configured timezone
        $defaultTimezone = date_default_timezone_get();

        // find Magento's configured timezone, and set that as the date timezone
        date_default_timezone_set(
            Mage::getStoreConfig(
                \Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE
            )
        );

        $time = now();

        // return the default timezone to the originally configured one
        date_default_timezone_set($defaultTimezone);

        return strtotime($time);
    }
}
