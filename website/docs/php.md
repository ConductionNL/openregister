## API Testing

### CRITICAL: Local Development API Testing Requirements

For local development environments, API calls **MUST** be made from within the Nextcloud Docker container. External calls will fail due to authentication and routing issues.

#### Common Mistakes to Avoid

1. **❌ DO NOT** make API calls from the host machine to `http://localhost` or `http://nextcloud.local`
   - These will result in 401 Unauthorized errors
   - Authentication cookies and sessions don't work properly from external calls

2. **❌ DO NOT** use standalone PHP server for API testing
   - `php -S localhost:8000` lacks the Nextcloud framework and routing system
   - API routes will return 404 errors
   - Dependency injection and service container won't work

3. **❌ DO NOT** forget authentication headers
   - Always include `-u 'admin:admin'` for basic auth
   - Always include `-H 'OCS-APIREQUEST: true'` header

### Handling Empty Values in API Requests

The OpenRegister API intelligently handles empty values based on schema requirements to prevent cascading errors in related apps while maintaining data integrity.

#### Empty Object Properties

For object properties (type: 'object'):

**✅ Non-required object properties:**
```json
{
  "contactgegevens": {}    // Converted to null automatically
}
// Result: "contactgegevens": null
```

**⚠️ Required object properties:**
```json
{
  "requiredObject": {}     // Kept as {} but will fail validation
}
// Result: Validation error with clear message
```

#### Empty Array Properties

For array properties (type: 'array'):

**✅ Arrays with no minItems constraint:**
```json
{
  "links": []              // Preserved as valid empty array
}
// Result: "links": []
```

**⚠️ Arrays with minItems > 0:**
```json
{
  "requiredItems": []      // Kept as [] but will fail validation
}
// Result: Validation error: "Property 'requiredItems' should have at least 1 items, but has 0"
```

#### Empty String Properties

For string properties (type: 'string'):

**✅ Non-required string properties:**
```json
{
  "optionalField": ""      // Converted to null automatically
}
// Result: "optionalField": null
```

**⚠️ Required string properties:**
```json
{
  "requiredField": ""      // Kept as "" but will fail validation
}
// Result: Validation error with guidance
```

#### Explicit Null Values

Explicit null values are always preserved for clearing fields:

```json
{
  "fieldToClear": null     // Always preserved
}
// Result: "fieldToClear": null
```

#### Best Practices for API Clients

1. **Use explicit null values** when you want to clear a field:
   ```json
   { "contactgegevens": null }  // Clear the field
   ```

2. **Omit properties entirely** if you don't want to change them:
   ```json
   { "naam": "Updated Name" }   // Only update name, leave other fields unchanged
   ```

3. **Provide valid data** for required fields:
   ```json
   { 
     "naam": "Organization Name",           // Required string
     "website": "https://example.com",     // Required string
     "contactgegevens": {                  // Required object with data
       "email": "contact@example.com"
     }
   }
   ```

4. **Handle validation errors** properly by checking the error message:
   ```json
   {
     "status": "error",
     "message": "Validation failed",
     "errors": [{
       "property": "naam",
       "message": "The required property 'naam' is missing. Please provide a value for this property or set it to null if allowed."
     }]
   }
   ```

### Proper API Testing Methods

#### 1. REQUIRED: Test from within the Docker Container
Execute curl commands from inside the Nextcloud Docker container:

**Step 1: Find your Nextcloud container name**
```bash
# List running containers to find Nextcloud container
docker ps | grep nextcloud
```

**Step 2: Test API from within container (REQUIRED for local development)**
```bash
# Execute curl command in the container (replace 'master-nextcloud-1' with your container name)
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' 'http://localhost/index.php/apps/openregister/api/objects/6/35?extend=deelnemers'"

# For statistics endpoint specifically
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' 'http://localhost/index.php/apps/openregister/api/search-trails/statistics'"

# Or get a shell in the container for interactive testing
docker exec -it -u 33 master-nextcloud-1 /bin/bash
```

**Important Notes:**
- Use `-u 33` flag to run as the correct user (www-data)
- Include authentication with `-u 'admin:admin'` or your Nextcloud credentials
- Add `OCS-APIREQUEST: true` header for proper API handling
- Use single quotes to avoid shell interpretation of special characters

#### 2. External API Testing (Production/Staging Only)
For external access, use the proper domain:

```bash
# For external access (production/staging environments only)
curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' \
     -H 'Content-Type: application/json' \
     'http://nextcloud.local/index.php/apps/openregister/api/objects/6/35'
```

**Note:** External calls require proper DNS resolution and may not work in all local development environments.

#### 3. Required Headers for API Testing
Always include these headers when testing:
```bash
# Test with authentication headers (REQUIRED)
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     -H 'Content-Type: application/json' \
     'http://localhost/index.php/apps/openregister/api/search-trails/statistics'
```

#### 4. Testing Object Creation and Relationships

**Create Test Objects:**
```bash
# Create a test organisation
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' -H 'Content-Type: application/json' -X POST 'http://localhost/index.php/apps/openregister/api/objects/6/35' -d '{\"naam\": \"Test Organisatie 1\", \"website\": \"https://test1.nl\", \"type\": \"Leverancier\"}'"

# Create another test organisation
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' -H 'Content-Type: application/json' -X POST 'http://localhost/index.php/apps/openregister/api/objects/6/35' -d '{\"naam\": \"Test Organisatie 2\", \"website\": \"https://test2.nl\", \"type\": \"Leverancier\"}'"
```

**Test Inverse Relationships:**
```bash
# Test deelnemers property population
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' 'http://localhost/index.php/apps/openregister/api/objects/6/35?extend=deelnemers'"

# Test specific object with extend
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' 'http://localhost/index.php/apps/openregister/api/objects/6/35/UUID-HERE?extend=deelnemers'"
```

### Schema Access and Inspection

To inspect or modify schema configurations, you can access them directly via the API:

```bash
# View a specific schema (replace 35 with the schema ID)
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' 'http://localhost/index.php/apps/openregister/api/schemas/35'"

# For external access (production/staging environments)
curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' \
  'http://nextcloud.local/index.php/apps/openregister/api/schemas/35'
```

**Schema ID Reference:**
- Organisatie schema: ID 35 (UUID: 94cd5186-b6d3-4d9d-91c8-a109083a7f88)
- Contactgegevens schema: ID 34

### Debugging API Endpoint Issues

#### 1. Check App Status
Ensure the app is enabled in Nextcloud:
```bash
# Check if app is enabled (replace 'master-nextcloud-1' with your container name)
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:list | grep openregister

# Enable the app if needed
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:enable openregister

# Verify app is enabled (should show 'openregister already enabled')
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:enable openregister
```

#### 2. View Debug Logs (CORRECT METHOD)
For local development, debug logs appear in the Docker container's stdout, not in the Nextcloud log file:

```bash
# View real-time debug logs from Docker stdout
docker logs -f master-nextcloud-1

# Or view recent logs
docker logs master-nextcloud-1 | tail -n 100

# Filter for specific debug messages
docker logs master-nextcloud-1 | grep -E '\[SaveObject\]|\[ObjectService\]|\[ObjectsController\]'

# View logs for specific time period
docker logs master-nextcloud-1 --since 10m | grep '\[SaveObject\]'
```

**Important**: Debug logs with `error_log()` calls appear in Docker stdout, not in `/var/www/html/data/nextcloud.log`. The Nextcloud log file only contains framework-level logs and errors.

#### 2. Verify Routes Configuration
Check that routes are properly defined in `appinfo/routes.php`:
```php
// Ensure routes are properly defined
['name' => 'controller#method', 'url' => '/api/endpoint', 'verb' => 'GET'],
```

#### 3. Check Controller Methods
Verify that controller methods have proper annotations:
```php
/**
 * @NoAdminRequired
 * @NoCSRFRequired
 */
public function statistics(): JSONResponse
{
    // Method implementation
}
```

#### 4. Monitor Nextcloud Logs
Check Nextcloud logs for API errors:
```bash
# View live logs (replace 'master-nextcloud-1' with your container name)
docker exec -u 33 master-nextcloud-1 tail -f /var/www/html/data/nextcloud.log

# Check recent errors
docker exec -u 33 master-nextcloud-1 grep -i error /var/www/html/data/nextcloud.log | tail -10
```

#### 5. Test Database Connectivity
Verify database queries work properly:
```bash
# Test database connection in container (replace 'master-nextcloud-1' with your container name)
docker exec -u 33 master-nextcloud-1 php -r "
\$config = include '/var/www/html/config/config.php';
\$pdo = new PDO('mysql:host=' . \$config['dbhost'] . ';dbname=' . \$config['dbname'], \$config['dbuser'], \$config['dbpassword']);
var_dump(\$pdo->query('SELECT COUNT(*) FROM oc_search_trails')->fetchColumn());
"
```

### Recent Fixes and Improvements

#### 🔧 IN PROGRESS: Inverse Relations Write-Back Issue
**Problem**: The `deelnemers` property was not triggering write-back to update the `deelnames` property on referenced organizations.

**Root Cause**: The `handleInverseRelationsWriteBack` method was not correctly detecting `writeBack` properties in array configurations. The schema structure has `writeBack` at the array level, but the code was only checking at the items level.

**Schema Structure**:
```json
"deelnemers": {
  "type": "array",
  "items": {
    "type": "object",
    "objectConfiguration": {"handling": "related-object"},
    "$ref": "#/components/schemas/organisatie",
    "inversedBy": "deelnames",
    "writeBack": true,           // ← This is at array level
    "removeAfterWriteBack": true
  }
}
```

**Difference between `writeBack` and `inversedBy`**:
- **`inversedBy`**: Declarative property that defines the relationship direction ("referenced objects have a 'deelnames' property")
- **`writeBack`**: Action property that triggers the actual update ("when I set deelnemers, update the referenced objects' deelnames")

**Solution Implemented**: 
1. **Fixed property detection**: Added logic to check for `writeBack` at both property level and array level
2. **Enhanced configuration extraction**: Updated logic to handle array of objects with `writeBack` at array level
3. **Improved error handling**: Better logging and error handling for write-back operations

**Testing Status**: 
- ✅ Schema configuration is correct
- ✅ API calls work properly
- 🔄 Debug logging needs investigation (logs not appearing in Docker stdout)
- 🔄 Write-back functionality needs verification

#### ✅ RESOLVED: Inverse Relations Population Issue
**Problem**: The `deelnemers` property was not being populated when using `_extend` parameter.

**Root Cause**: The `handleInversedProperties` method in `RenderObject.php` had several bugs:
1. Incorrect handling of inversedBy configuration structure
2. Wrong property access patterns for array vs object properties
3. Improper schema reference resolution

**Solution Implemented**: 
1. **Fixed property configuration extraction**: Properly handle both array items and direct object properties with inversedBy
2. **Improved schema reference resolution**: Added `resolveSchemaReference` method to handle various schema reference formats
3. **Enhanced UUID matching**: Better handling of both array and single value references
4. **Corrected property structure handling**: Distinguish between array properties and single object properties

**Testing Results**:
- ✅ API calls work correctly from within Docker container
- ✅ Object creation successful with proper UUID generation
- ✅ Schema inspection working properly
- ✅ Authentication and headers working as expected

**Example Working Commands**:
```bash
# Test object creation
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' -H 'Content-Type: application/json' -X POST 'http://localhost/index.php/apps/openregister/api/objects/6/35' -d '{\"naam\": \"Test Organisatie\", \"website\": \"https://test.nl\", \"type\": \"Leverancier\"}'"

# Test inverse relations
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' 'http://localhost/index.php/apps/openregister/api/objects/6/35?extend=deelnemers'"
```

# Unit Testing

Unit testing is crucial for maintaining code quality and ensuring that changes don't break existing functionality. The OpenRegister project uses PHPUnit for testing.

## Test Structure

Tests are organized in the 'tests/' directory with the following structure:

```
tests/
├── bootstrap.php          # Test bootstrap file
├── Unit/                  # Unit tests
│   ├── Service/          # Service layer tests
│   │   ├── ObjectHandlers/
│   │   │   └── SaveObjectTest.php
│   │   └── ObjectServiceTest.php
│   └── Db/               # Database layer tests
└── Integration/          # Integration tests (future)
```

## Running Tests

### Prerequisites

1. Ensure you have a running Nextcloud development environment
2. Install dependencies: 'composer install'
3. The tests require access to the Nextcloud framework

### Running Tests via Docker

For the most reliable test execution, run tests inside the Nextcloud container:

```bash
# Execute tests in the container
docker exec -it -u 33 master-nextcloud-1 bash -c 'cd /var/www/html/apps-extra/openregister && ./vendor/bin/phpunit tests/Unit/Service/ObjectHandlers/SaveObjectTest.php --bootstrap tests/bootstrap.php'

# Run all unit tests
docker exec -it -u 33 master-nextcloud-1 bash -c 'cd /var/www/html/apps-extra/openregister && ./vendor/bin/phpunit tests/Unit/ --bootstrap tests/bootstrap.php'

# Run with verbose output
docker exec -it -u 33 master-nextcloud-1 bash -c 'cd /var/www/html/apps-extra/openregister && ./vendor/bin/phpunit tests/Unit/ --bootstrap tests/bootstrap.php --verbose'
```

### Test Configuration

The project uses 'phpunit.xml' for configuration:

```xml
<?xml version='1.0' encoding='UTF-8'?>
<phpunit xmlns:xsi='http://www.w3.org/2001/XMLSchema-instance'
         xsi:noNamespaceSchemaLocation='https://schema.phpunit.de/9.5/phpunit.xsd'
         bootstrap='tests/bootstrap.php'
         cacheResultFile='.phpunit.result.cache'
         executionOrder='depends,defects'
         forceCoversAnnotation='false'
         beStrictAboutCoversAnnotation='true'
         beStrictAboutOutputDuringTests='true'
         beStrictAboutTodoAnnotatedTests='true'
         failOnRisky='true'
         failOnWarning='true'
         verbose='true'>
    <testsuites>
        <testsuite name='unit'>
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix='.php'>lib</directory>
        </include>
    </coverage>
</phpunit>
```

## Writing Tests

### Test Class Structure

All test classes should follow this structure:

```php
<?php
/**
 * Test class for [ClassName]
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\YourService;

/**
 * Test class for YourService
 */
class YourServiceTest extends TestCase
{
    /**
     * @var YourService The service under test
     */
    private YourService $service;

    /**
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new YourService();
    }

    /**
     * Clean up after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->service);
    }

    /**
     * Test method description
     *
     * @return void
     */
    public function testMethodName(): void
    {
        // Arrange
        $input = 'test input';
        $expected = 'expected output';

        // Act
        $result = $this->service->methodName($input);

        // Assert
        $this->assertEquals($expected, $result);
    }
}
```

### Test Scenarios

#### Testing Object Cascading

Example test for object cascading behavior:

```php
public function testCascadeObjectsWithInversedBy(): void
{
    // Arrange
    $mainObject = new ObjectEntity();
    $schema = $this->createMockSchema();
    $data = [
        'name' => 'Test Organization',
        'contactgegevens' => [
            [
                'voornaam' => 'John',
                'achternaam' => 'Doe',
                'email' => 'john@example.com'
            ]
        ]
    ];

    // Act
    $result = $this->saveObject->cascadeObjects($mainObject, $schema, $data);

    // Assert
    $this->assertArrayHasKey('contactgegevens', $result);
    $this->assertEmpty($result['contactgegevens']); // Should be empty due to inversedBy
}
```

#### Testing UUID Handling

Example test for UUID handling:

```php
public function testSaveObjectWithSpecificUuid(): void
{
    // Arrange
    $uuid = '12345678-1234-5678-9012-123456789012';
    $data = ['name' => 'Test Object'];

    // Act
    $result = $this->saveObject->saveObject(
        registerId: 1,
        schemaId: 1,
        objectData: $data,
        uuid: $uuid
    );

    // Assert
    $this->assertInstanceOf(ObjectEntity::class, $result);
    $this->assertEquals($uuid, $result->getUuid());
}
```

#### Testing Error Scenarios

Example test for error handling:

```php
public function testSaveObjectWithInvalidData(): void
{
    // Arrange
    $invalidData = []; // Missing required fields

    // Assert
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Validation failed');

    // Act
    $this->saveObject->saveObject(
        registerId: 1,
        schemaId: 1,
        objectData: $invalidData
    );
}
```

### Mocking Dependencies

Use PHPUnit's mocking capabilities for dependencies:

```php
protected function setUp(): void
{
    parent::setUp();
    
    // Mock dependencies
    $this->objectMapper = $this->createMock(ObjectMapper::class);
    $this->schemaMapper = $this->createMock(SchemaMapper::class);
    $this->validator = $this->createMock(ValidatorService::class);
    
    // Create service with mocked dependencies
    $this->saveObject = new SaveObject(
        $this->objectMapper,
        $this->schemaMapper,
        $this->validator
    );
}
```

## Test Categories

### Unit Tests

- Test individual methods in isolation
- Mock all dependencies
- Fast execution
- Located in 'tests/Unit/'

### Integration Tests

- Test interaction between components
- Use real database connections
- Slower execution
- Located in 'tests/Integration/' (future)

## Best Practices

1. **Follow AAA Pattern**: Arrange, Act, Assert
2. **One Assertion Per Test**: Each test should verify one specific behavior
3. **Descriptive Test Names**: Use clear, descriptive method names
4. **Mock External Dependencies**: Don't rely on external services
5. **Test Edge Cases**: Include tests for boundary conditions and error scenarios
6. **Use Data Providers**: For testing multiple input scenarios
7. **Clean Up**: Always clean up resources in tearDown()

## Common Test Patterns

### Data Providers

```php
/**
 * @dataProvider validationDataProvider
 */
public function testValidation(array $data, bool $expected): void
{
    $result = $this->validator->validate($data);
    $this->assertEquals($expected, $result);
}

public function validationDataProvider(): array
{
    return [
        'valid data' => [['name' => 'Test'], true],
        'invalid data' => [[], false],
        'missing required field' => [['description' => 'Test'], false],
    ];
}
```

### Exception Testing

```php
public function testExceptionHandling(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid input provided');
    
    $this->service->methodThatThrows('invalid input');
}
```

## Debugging Tests

### Verbose Output

```bash
./vendor/bin/phpunit --verbose
```

### Debug Specific Test

```bash
./vendor/bin/phpunit --filter testMethodName --verbose
```

### Test Coverage

```bash
./vendor/bin/phpunit --coverage-text
```

## Continuous Integration

Tests should be run automatically in CI/CD pipelines:

1. On pull requests
2. Before deployments
3. On scheduled basis

## Troubleshooting

### Common Issues

1. **Missing Dependencies**: Ensure all required packages are installed
2. **Database Connection**: Verify database is accessible
3. **Nextcloud Bootstrap**: Ensure proper Nextcloud environment setup
4. **Memory Limits**: Increase PHP memory limit if needed

### Test Environment Setup

The 'tests/bootstrap.php' file handles:
- Nextcloud framework initialization
- Autoloader setup
- Test environment configuration
- Database connection setup

## Manual Testing Scenarios

For comprehensive testing, also perform manual API tests:

### Test Cascading Between Contactgegevens and Organisatie

```bash
# Test creating Organisatie with nested Contactgegevens
curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' -H 'Content-Type: application/json' \
  -X POST 'http://localhost/index.php/apps/openregister/api/objects/6/35' \
  -d '{
    "naam": "Test Organisatie BV",
    "website": "https://test-organisatie.nl",
    "type": "Leverancier",
    "contactgegevens": [
      {
        "voornaam": "Jan",
        "achternaam": "Jansen",
        "email": "jan.jansen@test-organisatie.nl",
        "telefoon": "06-12345678",
        "functie": "Manager"
      }
    ]
  }'
```

### Validate Response Structure

Check that the response includes:
- Proper UUID generation
- Correct cascading behavior
- Proper inversedBy handling
- Validation compliance

## Future Enhancements

1. **Integration Tests**: Full database integration tests
2. **Performance Tests**: Load and stress testing
3. **API Tests**: Automated API endpoint testing
4. **Mock Services**: More sophisticated mocking framework
5. **Test Data Factories**: Automated test data generation

## Current Testing Status

### Recent Updates and Fixes

#### UI Improvements (EditSchema.vue)
- **Fixed array item object configuration**: Added support for configuring object properties when array items are of type 'object'
- **Updated object handling options**: Added 'nested-object' and 'related-object' options for both regular objects and array items
- **Enhanced inversedBy support**: Added proper support for inversedBy relationships in array items
- **Improved schema reference handling**: Better handling of schema references in array configurations

#### Backend Bug Fixes
- **Fixed RenderObject.php**: Resolved 'str_contains(): Argument #1 ($haystack) must be of type string, array given' error by properly handling both string and object formats for '$ref' fields
- **Fixed ValidateObject.php**: Similar fix for schema reference validation to handle object format '$ref' fields

### Schema Configuration Testing

#### Current Organisatie Schema Structure
The Organisatie schema has been updated with the following key relationships:

1. **deelnemers** (array of objects):
   - Type: array with object items
   - Object handling: 'related-object'
   - Schema reference: '#/components/schemas/organisatie'
   - InversedBy: 'deelnames'

2. **deelnames** (array of objects):
   - Type: array with object items  
   - Object handling: 'related-object'
   - Schema reference: '#/components/schemas/organisatie'
   - No inversedBy (receives the relationship)

#### Testing Scenarios Planned

1. **Create Individual Organisations**: Create test organisations to use as deelnemers
2. **Create Samenwerking Organisation**: Create a 'Samenwerking' type organisation with existing organisation UUIDs in deelnemers array
3. **Verify Cascading Logic**: Check that:
   - deelnemers array gets emptied after cascading
   - Referenced organisations get the parent UUID added to their deelnames array
   - Proper UUID handling and relationship establishment

### Current Issues

#### ✅ RESOLVED: Duplicate Schema ID Error  
**Error**: 'Duplicate schema id: http://localhost/apps/openregister/api/v1/schemas/94cd5186-b6d3-4d9d-91c8-a109083a7f88#'

**Root Cause**: Circular references in the Organisatie schema where `deelnames` and `deelnemers` properties both referenced `#/components/schemas/organisatie`, causing the Opis JSON Schema validator to encounter the same schema ID multiple times.

**Solution Implemented**: Added OpenRegister-specific schema transformation in `ValidateObject.php` that:
1. **Transforms schemas before validation**: Processes schema objects before they reach the Opis JSON Schema validator
2. **Handles related vs nested objects**: 
   - Related objects (`handling: 'related-object'`) → Converted to UUID string validation
   - Nested objects (`handling: 'nested-object'`) → Keeps object structure but prevents circular refs
3. **Prevents circular references**: Removes `$ref` properties that would cause infinite validation loops
4. **Maintains OpenRegister logic**: Ensures related objects expect UUID strings while nested objects expect full objects

**Impact**: Organisation creation now works correctly, enabling testing of cascading relationships between `deelnemers` and `deelnames`.

### Next Steps

1. **Resolve Schema Validation Issues**: Fix the duplicate schema ID error
2. **Complete Cascading Tests**: Once schema is fixed, test the deelnemers/deelnames relationship
3. **Verify InversedBy Logic**: Ensure proper handling of inversedBy relationships
4. **Document Test Results**: Record successful test scenarios and edge cases
5. **Update Documentation**: Document the new cascading behavior and UI improvements

### Test Commands for Manual Verification

Once schema issues are resolved, use these commands:

```bash
# Create test organisations
curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' -H 'Content-Type: application/json' \
  -X POST 'http://localhost/index.php/apps/openregister/api/objects/6/35' \
  -d '{"naam": "Test Organisatie 1", "website": "https://test1.nl", "type": "Leverancier"}'

# Create samenwerking with deelnemers
curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' -H 'Content-Type: application/json' \
  -X POST 'http://localhost/index.php/apps/openregister/api/objects/6/35' \
  -d '{
    "naam": "Test Samenwerking",
    "website": "https://samenwerking.nl", 
    "type": "Samenwerking",
    "deelnemers": [
      "uuid-of-org-1",
      "uuid-of-org-2"
    ]
  }'

# Verify cascading results
curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' \
  'http://localhost/index.php/apps/openregister/api/objects/6/35/uuid-of-org-1'
```

### Code Quality Improvements

- All new code follows PSR-12 standards
- Proper error handling with try-catch blocks
- Comprehensive inline documentation
- Type hints and return types specified
- PHPStan and Psalm compatible annotations