# Tasks: migrate-auth-system

## 1. Database & Entity

### Task 1.1: Create Consumer entity
- **files**: `openregister/lib/Db/Consumer.php`
- **acceptance_criteria**:
  - GIVEN the entity class WHEN instantiated THEN it has all fields: uuid, name, description, domains, ips, authorizationType, authorizationConfiguration, userId, created, updated
  - GIVEN JSON fields (domains, ips, authorizationConfiguration) WHEN accessed THEN they return arrays
  - GIVEN a Consumer WHEN jsonSerialize() is called THEN it returns a complete JSON representation
- [x] Implement
- [x] Test

### Task 1.2: Create ConsumerMapper
- **files**: `openregister/lib/Db/ConsumerMapper.php`
- **acceptance_criteria**:
  - GIVEN the mapper WHEN find(id) is called THEN it returns a Consumer entity
  - GIVEN the mapper WHEN findAll() is called with filters THEN it returns filtered results
  - GIVEN the mapper WHEN createFromArray() is called THEN it creates and inserts a Consumer with UUID
  - GIVEN the mapper WHEN updateFromArray() is called THEN it updates the Consumer
- [x] Implement
- [x] Test

### Task 1.3: Create database migration
- **files**: `openregister/lib/Migration/VersionXDate*.php`
- **acceptance_criteria**:
  - GIVEN the migration WHEN executed THEN openregister_consumers table is created with all columns
  - GIVEN the migration WHEN reversed THEN the table is dropped
- [x] Implement
- [x] Test

## 2. Authentication Services

### Task 2.1: Port AuthorizationService
- **files**: `openregister/lib/Service/AuthorizationService.php`
- **acceptance_criteria**:
  - GIVEN a valid JWT with matching Consumer WHEN authorizeJwt() is called THEN the user session is set
  - GIVEN an expired JWT WHEN authorizeJwt() is called THEN AuthenticationException is thrown
  - GIVEN valid Basic Auth credentials WHEN authorizeBasic() is called THEN the user is authenticated
  - GIVEN a valid API key WHEN authorizeApiKey() is called THEN the request is authorized
  - GIVEN all algorithms (HS256, RS256, PS256, etc.) WHEN used in JWT THEN they are validated correctly
- [x] Implement
- [x] Test

### Task 2.2: Port AuthenticationService
- **files**: `openregister/lib/Service/AuthenticationService.php`
- **acceptance_criteria**:
  - GIVEN OAuth2 client credentials config WHEN fetchOAuthTokens() is called THEN an access token is returned
  - GIVEN JWT config with payload template WHEN fetchJWTToken() is called THEN a signed JWT is returned
  - GIVEN RSA key config WHEN getRSJWK() is called THEN a valid JWK is returned
  - GIVEN HMAC key config WHEN getHSJWK() is called THEN a valid JWK is returned
- [x] Implement
- [x] Test

### Task 2.3: Create AuthenticationException
- **files**: `openregister/lib/Exception/AuthenticationException.php`
- **acceptance_criteria**:
  - GIVEN an auth failure WHEN exception is created THEN it contains message and details array
  - GIVEN the exception WHEN getDetails() is called THEN structured error info is returned
- [x] Implement
- [x] Test

## 3. Twig Extensions

### Task 3.1: Port AuthenticationExtension and Runtime
- **files**: `openregister/lib/Twig/AuthenticationExtension.php`, `openregister/lib/Twig/AuthenticationRuntime.php`
- **acceptance_criteria**:
  - GIVEN a Twig template WHEN oauthToken() function is used THEN it fetches an OAuth token
  - GIVEN a Twig template WHEN jwtToken() function is used THEN it generates a JWT
- [x] Implement
- [x] Test

## 4. Controller & Routes

### Task 4.1: Create ConsumersController
- **files**: `openregister/lib/Controller/ConsumersController.php`
- **acceptance_criteria**:
  - GIVEN admin auth WHEN GET /api/consumers THEN returns list of consumers
  - GIVEN admin auth WHEN POST /api/consumers with valid data THEN creates consumer and returns 201
  - GIVEN admin auth WHEN GET /api/consumers/{id} THEN returns single consumer
  - GIVEN admin auth WHEN PUT /api/consumers/{id} THEN updates consumer
  - GIVEN admin auth WHEN DELETE /api/consumers/{id} THEN deletes consumer
  - GIVEN non-admin auth WHEN any consumer endpoint is called THEN returns 403
- [x] Implement
- [x] Test

### Task 4.2: Register routes
- **files**: `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN routes.php WHEN the app loads THEN /api/consumers CRUD routes are registered
- [x] Implement
- [x] Test

## 5. Composer & Integration

### Task 5.1: Add jwt-framework dependency
- **files**: `openregister/composer.json`
- **acceptance_criteria**:
  - GIVEN composer.json WHEN composer install is run THEN web-token/jwt-framework ^3 is installed
  - GIVEN the dependency WHEN AuthorizationService is instantiated THEN all JWT classes are available
- [x] Implement
- [x] Test

### Task 5.2: Register services in DI container
- **files**: `openregister/lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the app container WHEN AuthorizationService is requested THEN it is properly constructed with dependencies
  - GIVEN the app container WHEN AuthenticationService is requested THEN it is properly constructed
- [x] Implement
- [x] Test
