---
title: Testing
sidebar_position: 10
description: Testing strategy and test groups for OpenRegister
keywords:
  - Testing
  - Integration Tests
  - Test Groups
  - PHPUnit
---

# Testing

OpenRegister uses comprehensive integration tests to ensure functionality works correctly across all components.

## Test Strategy

### Integration Testing

Integration tests verify that different parts of the system work together correctly by testing against a running Docker environment with:
- Nextcloud container
- MySQL database
- Real HTTP requests
- Actual file storage

### Location

Integration tests are located in:
```
openregister/tests/Integration/
```

## Core Integration Tests

The `CoreIntegrationTest.php` file contains organized test groups covering all major OpenRegister functionality.

### Test Group 1: File Upload Tests (Tests 1-15)

Tests for file attachment and upload functionality.

#### Covered Functionality

**Single File Uploads**
- Multipart form upload
- Base64 data URI upload
- URL reference upload (external files)

**Multiple File Uploads**
- Multiple files in single request
- File arrays (images[])

**Validation**
- MIME type validation
- File size limits
- Corrupted base64 detection

**File Operations**
- Retrieving file metadata
- Updating files
- Mixed upload methods

#### Example Test

```php
public function testMultipartUploadSinglePdf(): void
{
    $pdfContent = '%PDF-1.4 fake pdf content for testing';
    $tmpFile = tmpfile();
    fwrite($tmpFile, $pdfContent);
    
    $response = $this->client->post(
        "/index.php/apps/openregister/api/objects/{$register}/{$schema}", 
        [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Test Document'],
                ['name' => 'attachment', 'contents' => fopen($tmpPath, 'r'), 
                 'filename' => 'test.pdf', 
                 'headers' => ['Content-Type' => 'application/pdf']
                ],
            ]
        ]
    );
    
    $this->assertEquals(201, $response->getStatusCode());
}
```

### Test Group 2: Cascade Protection Tests (Tests 16-18)

Tests for referential integrity and cascade protection.

#### Covered Functionality

- **Register Protection**: Cannot delete register with objects
- **Schema Protection**: Cannot delete schema with objects  
- **Cleanup Workflow**: Can delete after proper cleanup

#### Why This Matters

Cascade protection prevents accidental data loss by ensuring:
1. Registers cannot be deleted while containing schemas or objects
2. Schemas cannot be deleted while containing objects
3. Proper cleanup order is enforced (objects → schemas → registers)

#### Example Test

```php
public function testCannotDeleteRegisterWithObjects(): void
{
    // Create object
    $response = $this->client->post(
        "/index.php/apps/openregister/api/objects/{$register}/{$schema}", 
        ['json' => ['title' => 'Protection Test']]
    );
    
    // Attempt to delete register
    $deleteResponse = $this->client->delete(
        "/index.php/apps/openregister/api/registers/{$registerId}"
    );
    
    // Should fail with 400 or 409
    $this->assertContains($deleteResponse->getStatusCode(), [400, 409]);
}
```

### Test Group 3: File Publishing Tests (Tests 19-22)

Tests for file sharing, publishing, and metadata.

#### Covered Functionality

**File Access Control**
- Authenticated URLs for non-shared files (`/api/files/`)
- Public share URLs for published files (`/index.php/s/`)

**Auto-Publishing**
- Schema-level `autoPublish` configuration
- Automatic share creation on upload

**Metadata Integration**
- Logo field mapping (`objectImageField`)
- Image metadata in `@self.image`
- First-file-in-array selection

**File Deletion**
- Delete single file by sending `null`
- Delete file array by sending `[]`

#### Example Test

```php
public function testAutoShareFileProperty(): void
{
    // Create schema with autoPublish
    $schemaResponse = $this->client->post(
        '/index.php/apps/openregister/api/schemas', 
        [
            'json' => [
                'register' => $registerId,
                'slug' => 'auto-share-test',
                'properties' => [
                    'document' => [
                        'type' => 'file',
                        'autoPublish' => true
                    ],
                ],
            ]
        ]
    );
    
    // Upload file
    $response = $this->client->post(
        "/index.php/apps/openregister/api/objects/{$register}/{$schema}", 
        ['multipart' => [...]]
    );
    
    $object = json_decode($response->getBody(), true);
    
    // Verify public share URL
    $this->assertArrayHasKey('published', $object['document']);
    $this->assertStringContainsString(
        '/index.php/s/', 
        $object['document']['accessUrl']
    );
}
```

### Test Group 4: Array Filtering Tests (Tests 23-30)

Tests for advanced filtering with AND/OR logic and dot notation.

#### What These Tests Cover

These tests verify filtering functionality **across multiple registers and schemas**, as well as array properties within objects:

**Cross-Register/Schema Filtering**
- Test objects created in **different registers** (Register 1, Register 2)
- Test objects created with **different schemas** (Schema 1, Schema 2)
- Verify AND logic returns zero results when filtering single-value fields for multiple values
- Verify OR logic returns objects from multiple registers/schemas

**Object Array Property Filtering**  
- Test objects with array properties (e.g., 'availableColours': ['red', 'blue'])
- Verify AND logic requires ALL values present in the array
- Verify OR logic matches objects with ANY of the specified values

#### Covered Functionality

**Default AND Logic**
- Metadata arrays: `@self.register[]=1&@self.register[]=2` → zero results (object can't be in BOTH registers)
- Object arrays: `colours[]=red&colours[]=blue` → objects with BOTH colors in their array

**Explicit OR Logic**
- Metadata: `@self.register[or]=1,2` → objects from EITHER register 1 OR register 2
- Objects: `colours[or]=red,blue` → objects with EITHER red OR blue (or both)

**Dot Notation Syntax**
- Clean URLs: `@self.field` instead of `@self[field]`
- Works with operators: `@self.created[gte]=2025-01-01`
- Combines with regular filters: `@self.register=5&title=Test`

**Complex Scenarios**
- Multiple filter types combined
- Nested operators
- Mixed AND/OR logic across different fields

#### Example Tests

**Default AND Logic**
```php
public function testMetadataArrayFilterDefaultAndLogic(): void
{
    // Create objects in different registers
    $obj1 = createObject($register1);
    $obj2 = createObject($register2);
    
    // Filter with AND logic (default)
    $url = "/api/objects?@self.register[]={$reg1['id']}&@self.register[]={$reg2['id']}";
    $response = $this->client->get($url);
    $result = json_decode($response->getBody(), true);
    
    // Should return zero results (object can't be in BOTH registers)
    $this->assertEquals(0, $result['total']);
}
```

**Explicit OR Logic with Dot Notation**
```php
public function testMetadataArrayFilterExplicitOrLogicWithDotNotation(): void
{
    // Create objects
    $obj1 = createObject($register1);
    $obj2 = createObject($register2);
    
    // Filter with OR logic using dot notation
    $url = "/api/objects?@self.register[or]={$reg1['id']},{$reg2['id']}";
    $response = $this->client->get($url);
    $result = json_decode($response->getBody(), true);
    
    // Should return objects from BOTH registers
    $this->assertGreaterThanOrEqual(2, $result['total']);
    $returnedIds = array_column($result['results'], 'id');
    $this->assertContains($obj1['id'], $returnedIds);
    $this->assertContains($obj2['id'], $returnedIds);
}
```

**Object Array Property AND Logic**
```php
public function testObjectArrayPropertyDefaultAndLogic(): void
{
    // Create products with different color combinations
    $redBlue = createProduct(['red', 'blue']);        // ✅ Match
    $onlyBlue = createProduct(['blue']);              // ❌ No match
    $redBlueGreen = createProduct(['red', 'blue', 'green']); // ✅ Match
    
    // Filter for products with BOTH red AND blue
    $url = "/api/objects?availableColours[]=red&availableColours[]=blue";
    $response = $this->client->get($url);
    $result = json_decode($response->getBody(), true);
    
    $returnedIds = array_column($result['results'], 'id');
    $this->assertContains($redBlue['id'], $returnedIds);
    $this->assertContains($redBlueGreen['id'], $returnedIds);
    $this->assertNotContains($onlyBlue['id'], $returnedIds);
}
```

**Dot Notation Syntax**
```php
public function testDotNotationSyntaxForMetadataFilters(): void
{
    // Use dot notation for metadata filter
    $url = "/api/objects?@self.register={$registerId}";
    $response = $this->client->get($url);
    $result = json_decode($response->getBody(), true);
    
    // All returned objects should be from specified register
    foreach ($result['results'] as $obj) {
        $this->assertEquals($registerId, $obj['@self']['register']);
    }
}
```

## Running Tests

### Prerequisites

1. **Docker containers running**:
   ```bash
   docker ps | grep -E "nextcloud|database"
   ```

2. **OpenRegister app enabled**:
   ```bash
   docker exec -u 33 master-nextcloud-1 php occ app:enable openregister
   ```

### Run Tests Inside Docker Container (Recommended)

Integration tests should be run **inside the Nextcloud Docker container** to have access to the full Nextcloud environment:

```bash
# Run all tests
docker exec master-nextcloud-1 bash -c "cd /var/www/html/apps-extra/openregister && php vendor/bin/phpunit tests/Integration/CoreIntegrationTest.php --bootstrap tests/integration-bootstrap.php --no-configuration 2>&1"

# Run specific test group with readable output
docker exec master-nextcloud-1 bash -c "cd /var/www/html/apps-extra/openregister && php vendor/bin/phpunit --filter 'testMetadataArrayFilter|testObjectArrayProperty' tests/Integration/CoreIntegrationTest.php --bootstrap tests/integration-bootstrap.php --no-configuration --testdox 2>&1"

# Run single test
docker exec master-nextcloud-1 bash -c "cd /var/www/html/apps-extra/openregister && php vendor/bin/phpunit --filter testDotNotationSyntaxForMetadataFilters tests/Integration/CoreIntegrationTest.php --bootstrap tests/integration-bootstrap.php --no-configuration 2>&1"

# Save output to file
docker exec master-nextcloud-1 bash -c "cd /var/www/html/apps-extra/openregister && php vendor/bin/phpunit tests/Integration/CoreIntegrationTest.php --bootstrap tests/integration-bootstrap.php --no-configuration 2>&1 | tee /tmp/test-output.txt"
```

### Run Specific Test Groups

```bash
# File Upload Tests (1-15)
docker exec master-nextcloud-1 bash -c "cd /var/www/html/apps-extra/openregister && php vendor/bin/phpunit --filter testMultipart tests/Integration/CoreIntegrationTest.php --bootstrap tests/integration-bootstrap.php --no-configuration --testdox"

# Cascade Protection Tests (16-18)
docker exec master-nextcloud-1 bash -c "cd /var/www/html/apps-extra/openregister && php vendor/bin/phpunit --filter testCannotDelete tests/Integration/CoreIntegrationTest.php --bootstrap tests/integration-bootstrap.php --no-configuration --testdox"

# File Publishing Tests (19-22)
docker exec master-nextcloud-1 bash -c "cd /var/www/html/apps-extra/openregister && php vendor/bin/phpunit --filter 'testAutoShare|testLogo|testImage|testDelete' tests/Integration/CoreIntegrationTest.php --bootstrap tests/integration-bootstrap.php --no-configuration --testdox"

# Array Filtering Tests (23-30)
docker exec master-nextcloud-1 bash -c "cd /var/www/html/apps-extra/openregister && php vendor/bin/phpunit --filter 'testMetadataArrayFilter|testObjectArrayProperty|testDotNotation|testComplex' tests/Integration/CoreIntegrationTest.php --bootstrap tests/integration-bootstrap.php --no-configuration --testdox"
```

### Why Run in Docker Container?

Running tests inside the Docker container ensures:
- Access to Nextcloud's internal API
- Proper database connections
- File system access
- Nextcloud environment variables
- PHP extensions and dependencies
- Correct base URL ('http://localhost' inside container)

## Test Environment

### Configuration

Tests use the following configuration:
- **Base URL**: `http://localhost`
- **Auth**: `admin:admin` (Basic Auth)
- **Containers**: 
  - `master-nextcloud-1` (Nextcloud)
  - `master-database-mysql-1` (MySQL)

### Cleanup

Tests automatically clean up created resources:
1. Objects (deleted first)
2. Schemas (deleted second)
3. Registers (deleted last)

Proper cleanup order is essential for cascade protection.

## Writing New Tests

### Test Structure

```php
public function testYourFeature(): void
{
    // 1. Setup: Create necessary resources
    $register = $this->createTestRegister();
    $schema = $this->createTestSchema($register);
    
    // 2. Execute: Perform the test action
    $response = $this->client->post(
        "/api/objects/{$register}/{$schema}",
        ['json' => ['title' => 'Test']]
    );
    
    // 3. Assert: Verify expected behavior
    $this->assertEquals(201, $response->getStatusCode());
    $data = json_decode($response->getBody(), true);
    $this->assertArrayHasKey('id', $data);
    
    // 4. Cleanup: Track for tearDown
    $this->createdObjectIds[] = $data['id'];
}
```

### Best Practices

1. **Use unique identifiers**: Add `uniqid()` to slugs to avoid conflicts
2. **Track created resources**: Add IDs to cleanup arrays
3. **Clear assertions**: Use descriptive assertion messages
4. **Isolated tests**: Each test should be independent
5. **Cleanup order**: Objects → Schemas → Registers

### Adding to Test Groups

When adding tests, follow the existing group structure:
- **Tests 1-15**: File uploads
- **Tests 16-18**: Cascade protection
- **Tests 19-22**: File publishing
- **Tests 23-30**: Array filtering
- **Tests 31+**: Your new group

Update the class-level docblock when adding new groups.

## Continuous Integration

### GitHub Actions

Tests can be integrated with GitHub Actions:

```yaml
name: Integration Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Start Docker containers
        run: docker-compose up -d
      - name: Run tests
        run: ./vendor/bin/phpunit tests/Integration/
```

## Troubleshooting

### Container Connection Issues

```bash
# Check container status
docker ps

# Check container logs
docker logs master-nextcloud-1

# Restart containers
docker-compose restart
```

### Database Issues

```bash
# Access database
docker exec -it master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud

# Check object count
SELECT COUNT(*) FROM oc_openregister_objects;

# Clean test data
DELETE FROM oc_openregister_objects WHERE register IN (
    SELECT id FROM oc_openregister_registers WHERE slug LIKE 'test-%'
);
```

### Test Failures

1. **Check container logs** for errors
2. **Verify app is enabled**: `php occ app:list | grep openregister`
3. **Check database connections**
4. **Ensure proper cleanup** between test runs

## Future Test Groups

Planned additional test groups:
- **RBAC Tests**: Role-based access control
- **Multi-tenancy Tests**: Organization isolation
- **Search Tests**: Full-text and filtered search
- **Solr Integration Tests**: Search engine functionality
- **Performance Tests**: Bulk operations and optimization

## See Also

- [Search Documentation](./Features/search.md) - Array filtering details
- [File Attachments](./file-attachments.md) - File upload specifications
- [API Documentation](./api/) - API endpoint reference

