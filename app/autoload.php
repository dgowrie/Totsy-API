<?php

use Doctrine\Common\ClassLoader;
require 'Doctrine/Common/ClassLoader.php';

$classLoader = new ClassLoader(
    'Totsy',
    __DIR__
);
$classLoader->register();
unset($classLoader);

$sonnoLoader = new ClassLoader(
    'Sonno',
    BP . DS . 'lib' . DS . 'sonno' . DS . 'src'
);
$sonnoLoader->register();
unset($sonnoLoader);

$commonLoader = new ClassLoader(
    'Doctrine\Common'
);
$commonLoader->register();
unset($commonLoader);
