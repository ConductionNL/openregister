# Bulk Operations Implementation

This document describes the technical implementation of the bulk operations feature in OpenRegister, including the architecture, design decisions, and internal workings.

## Architecture Overview

The bulk operations feature follows a layered architecture pattern:

```
┌─────────────────────────────────────────────────────────────┐
│                    API Layer (BulkController)               │
├─────────────────────────────────────────────────────────────┤
│                  Service Layer (ObjectService)              │
├─────────────────────────────────────────────────────────────┤
│                Data Layer (ObjectEntityMapper)              │
├─────────────────────────────────────────────────────────────┤
│                    Database Layer (MySQL)                   │
└─────────────────────────────────────────────────────────────┘
```

## Component Details

### 1. BulkController (`lib/Controller/BulkController.php`)

The controller handles HTTP requests and provides the API interface for bulk operations.

#### Key Features:
- **Admin-only access**: All endpoints require admin privileges
- **Input validation**: Validates request parameters and data formats
- **Error handling**: Provides consistent error responses
- **CSRF bypass**: Uses `@NoCSRFRequired` annotation for API access

#### Methods:
- `delete()` - Bulk delete operations
- `publish()` - Bulk publish operations  
- `depublish()` - Bulk depublish operations
- `save()` - Bulk save operations

#### Security:
```php
private function isCurrentUserAdmin(): bool
{
    $user = $this->userSession->getUser();
    if ($user === null) {
        return false;
    }
    return $this->groupManager->isAdmin($user->getUID());
}
```

### 2. ObjectService (`lib/Service/ObjectService.php`)

The service layer provides business logic and coordinates between the controller and data layer.

#### Key Features:
- **Permission filtering**: Applies RBAC and multi-organization filtering
- **Transaction management**: Ensures data consistency
- **Error handling**: Provides detailed error information
- **Logging**: Comprehensive operation logging

#### Methods:
- `deleteObjects()` - Orchestrates bulk delete operations
- `publishObjects()` - Orchestrates bulk publish operations
- `depublishObjects()` - Orchestrates bulk depublish operations
- `saveObjects()` - Orchestrates bulk save operations

#### Permission Filtering:
```php
private function filterUuidsForPermissions(array $uuids, bool $rbac, bool $multi): array
{
    // Get objects for permission checking
    $objects = $this->objectEntityMapper->findAll(ids: $uuids, includeDeleted: true);
    
    foreach ($objects as $object) {
        // Check RBAC permissions
        if ($rbac && $userId !== null) {
            // Verify user has permission for this object
        }
        
        // Check multi-organization permissions
        if ($multi && $activeOrganisation !== null) {
            // Verify object belongs to active organization
        }
    }
}
```

### 3. ObjectEntityMapper (`lib/Db/ObjectEntityMapper.php`)

The data layer handles database operations and provides optimized bulk operations.

#### Key Features:
- **Transaction support**: All operations wrapped in database transactions
- **Optimized queries**: Uses efficient SQL for bulk operations
- **Data type handling**: Properly handles DateTime, boolean, and JSON data
- **Error recovery**: Graceful handling of database errors

#### Methods:
- `deleteObjects()` - Public interface for bulk delete
- `publishObjects()` - Public interface for bulk publish
- `depublishObjects()` - Public interface for bulk depublish
- `saveObjects()` - Public interface for bulk save

#### Private Helper Methods:
- `bulkDelete()` - Internal bulk delete implementation
- `bulkPublish()` - Internal bulk publish implementation
- `bulkDepublish()` - Internal bulk depublish implementation
- `bulkUpdate()` - Internal bulk update implementation
- `bulkInsert()` - Internal bulk insert implementation

## Database Operations

### Bulk Delete Implementation

The bulk delete operation handles both soft and hard deletes:

```php
private function bulkDelete(array $uuids): array
{
    // 1. Get current state of objects
    $objects = $this->getObjectsForDeletion($uuids);
    
    // 2. Separate objects for soft vs hard delete
    $softDeleteIds = [];
    $hardDeleteIds = [];
    
    foreach ($objects as $object) {
        if (empty($object['deleted'])) {
            $softDeleteIds[] = $object['id']; // Soft delete
        } else {
            $hardDeleteIds[] = $object['id']; // Hard delete
        }
    }
    
    // 3. Perform soft deletes (UPDATE with deleted timestamp)
    if (!empty($softDeleteIds)) {
        $this->performSoftDeletes($softDeleteIds);
    }
    
    // 4. Perform hard deletes (DELETE from database)
    if (!empty($hardDeleteIds)) {
        $this->performHardDeletes($hardDeleteIds);
    }
}
```

### Bulk Publish/Depublish Implementation

Both publish and depublish operations follow the same pattern:

```php
private function bulkPublish(array $uuids, \DateTime|bool $datetime = true): array
{
    // 1. Determine datetime value
    $publishedValue = $this->determineDatetimeValue($datetime);
    
    // 2. Get object IDs for the UUIDs
    $objectIds = $this->getObjectIdsForUuids($uuids);
    
    // 3. Update published timestamp
    if (!empty($objectIds)) {
        $this->updatePublishedTimestamp($objectIds, $publishedValue);
    }
}
```

### Data Type Handling

The implementation properly handles various data types:

```php
private function getEntityValue(ObjectEntity $entity, string $column): mixed
{
    $value = $this->getPropertyValue($entity, $column);
    
    // Handle DateTime objects
    if ($value instanceof \DateTime) {
        $value = $value->format('Y-m-d H:i:s');
    }
    
    // Handle boolean values
    if (is_bool($value)) {
        $value = $value ? 1 : 0;
    }
    
    // Handle JSON encoding for arrays
    if (is_array($value) && in_array($column, ['files', 'relations', 'locked'])) {
        $value = json_encode($value);
    }
    
    return $value;
}
```

## Performance Optimizations

### 1. Batch Processing

Large operations are processed in batches to avoid memory issues:

```php
// Process 1000 objects at a time
$batchSize = 1000;
for ($i = 0; $i < count($insertObjects); $i += $batchSize) {
    $batch = array_slice($insertObjects, $i, $batchSize);
    $this->processBatch($batch);
}
```

### 2. Efficient SQL Queries

Uses optimized SQL for bulk operations:

```sql
-- Bulk UPDATE with IN clause
UPDATE oc_openregister_objects 
SET published = ? 
WHERE id IN (?, ?, ?)

-- Bulk DELETE with IN clause  
DELETE FROM oc_openregister_objects 
WHERE id IN (?, ?, ?)
```

### 3. Transaction Management

All operations are wrapped in database transactions:

```php
try {
    $this->db->beginTransaction();
    
    // Perform bulk operations
    $result = $this->performBulkOperation($data);
    
    $this->db->commit();
    return $result;
} catch (\Exception $e) {
    $this->db->rollBack();
    throw $e;
}
```

### 4. Dynamic Packet Size Management

The system automatically detects and adapts to the database's `max_allowed_packet` setting to prevent packet size errors during large bulk operations:

#### Automatic Detection

The system queries the database to determine the actual `max_allowed_packet` value:

```php
public function getMaxAllowedPacketSize(): int
{
    try {
        $stmt = $this->db->executeQuery('SHOW VARIABLES LIKE \'max_allowed_packet\'');
        $result = $stmt->fetch();
        
        if ($result && isset($result['Value'])) {
            return (int) $result['Value'];
        }
    } catch (\Exception $e) {
        $this->logger->warning('Could not get max_allowed_packet, using default', [
            'defaultSize' => 16777216,
            'unit' => 'bytes'
        ]);
    }
    
    // Default fallback value (16MB)
    return 16777216;
}
```

#### Configurable Buffer Percentage

Administrators can adjust the safety buffer percentage used for chunk size calculations:

```php
// Set buffer to 30% (more conservative)
$objectEntityMapper->setMaxPacketSizeBuffer(0.3);

// Set buffer to 60% (less conservative)
$objectEntityMapper->setMaxPacketSizeBuffer(0.6);

// Get current buffer setting
$currentBuffer = $objectEntityMapper->getMaxPacketSizeBuffer();
```

#### Adaptive Buffer Sizing

The system automatically adjusts the buffer based on the detected packet size:

- **≤ 16MB**: 30% buffer (very conservative)
- **> 16MB**: 40% buffer (conservative)
- **> 32MB**: 50% buffer (moderate)
- **> 64MB**: 60% buffer (less conservative)

#### Dynamic Chunk Sizing

Chunk sizes are calculated dynamically based on actual object sizes and the configured buffer:

```php
private function calculateOptimalChunkSize(array $insertObjects, array $updateObjects): int
{
    // Sample objects to estimate data size
    $sampleSize = min(20, max(5, count($insertObjects) + count($updateObjects)));
    
    // Calculate safety object size (using maximum size for safety)
    $safetyObjectSize = max($averageObjectSize, $maxObjectSize);
    
    // Use dynamic buffer percentage
    $maxPacketSize = $this->getMaxAllowedPacketSize() * $this->maxPacketSizeBuffer;
    $safeChunkSize = intval($maxPacketSize / $safetyObjectSize);
    
    // Ensure chunk size is within conservative bounds
    return max(5, min(100, $safeChunkSize));
}
```

## Configuration Options

### 1. Max Packet Size Buffer Configuration

The system provides several configuration methods for fine-tuning bulk operation performance:

#### Runtime Configuration
```php
// Get the ObjectEntityMapper instance
$objectEntityMapper = $this->getObjectEntityMapper();

// Configure buffer percentage (0.1 = 10%, 0.5 = 50%)
$objectEntityMapper->setMaxPacketSizeBuffer(0.3);  // Very conservative
$objectEntityMapper->setMaxPacketSizeBuffer(0.5);  // Default
$objectEntityMapper->setMaxPacketSizeBuffer(0.7);  // Less conservative

// Get current configuration
$currentBuffer = $objectEntityMapper->getMaxPacketSizeBuffer();
$maxPacketSize = $objectEntityMapper->getMaxAllowedPacketSize();
```

#### Configuration in Service Layer
```php
// In ObjectService or similar service class
public function configureBulkOperations(float $bufferPercentage): void
{
    if ($bufferPercentage >= 0.1 && $bufferPercentage <= 0.9) {
        $this->objectEntityMapper->setMaxPacketSizeBuffer($bufferPercentage);
        $this->logger->info('Bulk operations buffer set to ' . ($bufferPercentage * 100) . '%');
    }
}
```

#### Environment-Based Configuration
```php
// Configure based on environment variables
$bufferPercentage = getenv('OPENREGISTER_BULK_BUFFER') ?: 0.5;
$objectEntityMapper->setMaxPacketSizeBuffer((float) $bufferPercentage);
```

### 2. Large Object Threshold Configuration

The threshold for separating large objects can be configured:

```php
// Default threshold is 500KB (500,000 bytes)
$maxSafeSize = 1000000; // 1MB threshold

$objectGroups = $this->separateLargeObjects($objects, $maxSafeSize);
```

### 3. Chunk Size Bounds

The system enforces conservative bounds for chunk sizes:

```php
// Minimum chunk size: 5 objects
// Maximum chunk size: 100 objects
// These bounds prevent memory issues and ensure stability

$optimalChunkSize = max(5, min(100, $safeChunkSize));
```

## Error Handling Strategy

### 1. Input Validation

Comprehensive validation of input parameters:

```php
// Validate UUIDs array
if (empty($uuids) || !is_array($uuids)) {
    return new JSONResponse(
        ['error' => 'Invalid input. "uuids" array is required.'],
        Http::STATUS_BAD_REQUEST
    );
}

// Validate datetime format
if ($datetime !== true && $datetime !== false && $datetime !== null) {
    try {
        $datetime = new \DateTime($datetime);
    } catch (Exception $e) {
        return new JSONResponse(
            ['error' => 'Invalid datetime format. Use ISO 8601 format.'],
            Http::STATUS_BAD_REQUEST
        );
    }
}
```

### 2. Database Error Handling

Graceful handling of database errors:

```php
try {
    $qb->executeStatement();
} catch (\Exception $e) {
    throw new \Exception("Database operation failed: " . $e->getMessage());
}
```

### 3. Partial Success Handling

Operations continue even if some objects fail:

```php
$processedCount = 0;
$skippedCount = 0;

foreach ($objects as $object) {
    try {
        $this->processObject($object);
        $processedCount++;
    } catch (\Exception $e) {
        $skippedCount++;
        // Object processing failed, continue with next object
    }
}
```

## Troubleshooting Common Issues

### 1. Max Packet Size Errors

If you encounter `SQLSTATE[08S01]: Communication link failure: 1153 Got a packet bigger than 'max_allowed_packet' bytes` errors:

#### Check Current Database Setting
```bash
# From within the Nextcloud container
docker exec -it -u 33 master-nextcloud-1 bash -c "mysql -u root -p -e 'SHOW VARIABLES LIKE \"max_allowed_packet\";'"

# Or directly from MySQL container
docker exec -it master-database-mysql-1 mysql -u root -p -e 'SHOW VARIABLES LIKE "max_allowed_packet";'
```

#### Adjust Buffer Percentage
If the system is still too aggressive, reduce the buffer percentage:

```php
// More conservative (smaller chunks)
$objectEntityMapper->setMaxPacketSizeBuffer(0.3);

// Very conservative (very small chunks)
$objectEntityMapper->setMaxPacketSizeBuffer(0.2);
```

#### Monitor Chunk Sizing
Check the application logs for chunk size calculations:

```bash
docker logs master-nextcloud-1 | grep 'ObjectEntityMapper.*chunk size'
docker logs master-nextcloud-1 | grep 'ObjectEntityMapper.*Max packet size buffer'
```

### 2. Large Object Handling

The system automatically separates extremely large objects (>500KB by default) for individual processing:

```php
// Objects larger than this threshold are processed individually
$maxSafeSize = 500000; // 500KB

$objectGroups = $this->separateLargeObjects($objects, $maxSafeSize);
$largeObjects = $objectGroups['large'];      // Process individually
$normalObjects = $objectGroups['normal'];    // Process in chunks
```

### 3. Performance Monitoring

Monitor bulk operation performance through logs:

```bash
# Check for performance issues
docker logs master-nextcloud-1 | grep 'ObjectEntityMapper.*Starting saveObjects'
docker logs master-nextcloud-1 | grep 'ObjectEntityMapper.*Completed processing'

# Monitor memory usage
docker logs master-nextcloud-1 | grep 'ObjectEntityMapper.*memory'
```

## Security Considerations

### 1. Admin-Only Access

All bulk operations require admin privileges:

```php
if (!$this->isCurrentUserAdmin()) {
    return new JSONResponse(
        ['error' => 'Insufficient permissions. Admin access required.'],
        Http::STATUS_FORBIDDEN
    );
}
```

### 2. RBAC Integration

Objects are filtered based on user permissions:

```php
if ($rbac && $userId !== null) {
    $schema = $this->schemaMapper->find($objectSchema);
    if (!$this->hasPermission($schema, 'delete', $userId, $objectOwner, $rbac)) {
        continue; // Skip this object - no permission
    }
}
```

### 3. Multi-Organization Support

Objects are filtered based on organization context:

```php
if ($multi && $activeOrganisation !== null) {
    $objectOrganisation = $object->getOrganisation();
    if ($objectOrganisation !== null && $objectOrganisation !== $activeOrganisation) {
        continue; // Skip this object - different organization
    }
}
```

## Logging and Monitoring

### 1. Operation Logging

All operations are logged with detailed information:

```php
// Transaction logging has been removed for production
// Operations are tracked through audit trails instead
```

### 2. Performance Monitoring

Execution time and resource usage are tracked:

```php
$startTime = microtime(true);
$startMemory = memory_get_usage();

// Perform operation

$endTime = microtime(true);
$endMemory = memory_get_usage();

// Performance metrics can be logged to application logs if needed
// $operationTime = ($endTime - $startTime);
// $memoryUsed = ($endMemory - $startMemory);
```

## Testing Strategy

### 1. Unit Tests

Individual components are tested in isolation:

```php
public function testBulkDeleteWithValidUuids(): void
{
    $uuids = ['uuid1', 'uuid2', 'uuid3'];
    $result = $this->objectService->deleteObjects($uuids);
    
    $this->assertCount(3, $result);
    $this->assertContains('uuid1', $result);
}
```

### 2. Integration Tests

End-to-end testing of the complete workflow:

```php
public function testBulkDeleteEndpoint(): void
{
    $response = $this->client->post('/api/bulk/test/test/delete', [
        'json' => ['uuids' => ['uuid1', 'uuid2']]
    ]);
    
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($response->json()['success']);
}
```

### 3. Performance Tests

Load testing for large datasets:

```php
public function testBulkDeletePerformance(): void
{
    $uuids = $this->generateTestUuids(1000);
    
    $startTime = microtime(true);
    $result = $this->objectService->deleteObjects($uuids);
    $endTime = microtime(true);
    
    $this->assertLessThan(5.0, $endTime - $startTime); // Should complete within 5 seconds
}
```

## Future Enhancements

### 1. Async Processing

For very large operations, consider implementing async processing:

```php
public function deleteObjectsAsync(array $uuids): PromiseInterface
{
    return React\Async\async(function () use ($uuids) {
        return $this->deleteObjects($uuids);
    });
}
```

### 2. Progress Tracking

Add progress tracking for long-running operations:

```php
public function deleteObjectsWithProgress(array $uuids, callable $progressCallback): array
{
    $total = count($uuids);
    $processed = 0;
    
    foreach ($uuids as $uuid) {
        $this->deleteObject($uuid);
        $processed++;
        $progressCallback($processed, $total);
    }
}
```

### 3. Batch Size Optimization

Dynamic batch size based on object size and system resources:

```php
private function calculateOptimalBatchSize(array $objects): int
{
    $totalSize = array_sum(array_map('strlen', json_encode($objects)));
    $memoryLimit = ini_get('memory_limit');
    
    return min(1000, floor($memoryLimit * 0.1 / $totalSize));
}
```

## Conclusion

The bulk operations implementation provides a robust, secure, and performant solution for managing large datasets in OpenRegister. The layered architecture ensures maintainability, while the comprehensive error handling and logging provide observability and debugging capabilities.

The implementation follows Nextcloud best practices and integrates seamlessly with the existing RBAC and multi-organization systems, ensuring that security and access control are maintained even for bulk operations.
