# TOTSY API REFERENCE DOCUMENTATION #

The official Totsy API is a REST web service that exposes a discrete set of resources that can be manipulated by an API client.
Each API client is assigned a secret key/identifier that must be supplied in the HTTP `Authorization` header with each request. A missing or invalid `Authorization` identifier will result in a `401 Unauthorized` response.

An API client is able to interact with the web service completely with just two pieces of information:
1. The API base URL and
2. A set of link relations to identify related resources.

An API client should not define or construct URLs to resources. Instead, the API client should begin interacting with the API using the base URL and then examine the "links" collection in resource representations to discover URLs to related resources in order to make forward progress in carrying out a task.
Link relations (except for "self") are always of the form: http://rel.totsy.com/<type>/<resourceName>, where <type> indicates the type of the expected response (either "collection" or "entity") and "resourceName" is a generic name for the expected resource.

Errors generated on the server are communicated to the client by populating the `X-API-Error` HTTP header in a response. API clients may inspect the value of this header for error information pertaining to an unsuccessful request.


## EVENTS and PRODUCTS ##

Events and Products are a read-only interface to the Totsy product catalog.

### Retrieve Events ###
`GET /event` responds with a collection of Events. The event information contained in this collection includes a link [`rel=http://rel.totsy.com/collection/product`] to a collection of Products that are part of the event.

### Retrieve Products ###
`GET /event/123/product` responds with a collection of Products that are part of some event.
`GET /product/567` responds with a single Product.

### Retrieve Product Quantity ###
`GET /product/567/quantity` responds with the current quantity of a product.


## USERS and ORDERS ##

Users and their corresponding Orders are the primary use case for the Totsy API.

### Authenticate a User ###
`POST /auth` with the e-mail address & plaintext password of a user, which will perform a user login. The client receives an HTTP cookie along with a link [`rel=http://rel.totsy.com/entity/user`] to the User entity.

### End a User session ###
`DELETE /auth` will destroy the current User session and log the user out of the system.

### Retrieve information about a User ###
`GET /user/123` responds with information about a specific user. This URL is provided when a new authorization token is generated, and should not be constructed or created in any other fashion. The user information includes a set of links to the user's addresses [`rel=http://rel.totsy.com/collection/address`], orders [`rel=http://rel.totsy.com/collection/order`], rewards [`rel=http://rel.totsy.com/collection/reward`], and saved credit cards [`rel=http://rel.totsy.com/collection/creditcard`].

### Create a new User ###
`POST /user` with a partial representation of a User.

### Update an existing User ###
`PUT /user/123` with a partial representation of a User.

### Retrieve Addresses stored for a User ###
`GET /user/123/address` responds with a collection of Addresses. Each entry in the collection contains a a full link [`rel=http://rel.totsy.com/entity/address`] to the original Address resource.

### Create a new Address for a User ###
`POST /user/123/address` with a partial representation of an Address.

### Retrieve Credit Cards stored for a User ###
`GET /user/123/creditcard` responds with a collection of Credit Cards. Each entry in the collection contains a a full link [`rel=http://rel.totsy.com/entity/creditcard`] to the original Credit Card resource.

### Create a new Credit Card for a User ###
`POST /user/123/creditcard` with a full representation of a Credit Card.

### Retrieve Rewards stored for a User ###
`GET /user/123/reward` responds with a collection of Rewards. Each entry in the collection contains a a full link [`rel=http://rel.totsy.com/entity/reward`] to the original Reward resource.

### Create a new Reward for a User ###
`POST /user/123/reward` with a partial representation of an Reward.

### Retrieve Orders stored for a User ###
`GET /user/123/order` responds with a collection of Orders. Each entry in the collection contains a a full link [`rel=http://rel.totsy.com/entity/order`] to the original Order resource.

### Create a new Order for a User ###
1. `POST /user/123/order` with a partial representation of an Order, which includes a link [`rel=http://rel.totsy.com/entity/order`] to the full Order resource and the temporary Order expiry time. The server returns with a `202 Accepted` response, indicating that a temporary Order has been created. Initially, this temporary order will only contain order items (while the end user manipulates a client-side shopping cart).
2. `PUT /order/765432` with a partial representation of an Order to update the existing temporary Order at any time, as often as needed. Each request will generate another `202 Accepted` response, with a full Order representation including an updated expiration time.
3. `PUT /order/765432` a final time with a parital representation of an Order that contains address and payment information. The server returns with a `201 Created` response, indicating that a full and permanent Order has been created.