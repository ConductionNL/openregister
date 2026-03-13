# Fix voor saveObjects inversedBy relaties

## Probleem
De `saveObjects` methode in `ObjectService.php` verwerkt inversedBy relaties niet correct tijdens bulk imports.

## Hoofdproblemen:
1. `prepareObjectsForBulkSave` doet niets (regel 2723-2725)
2. `handleBulkInverseRelations` wordt nooit aangeroepen
3. Geen pre-validatie cascading voor bulk operaties
4. Geen writeBack verwerking

## Oplossing

### 1. Verbeter prepareObjectsForBulkSave methode

```php
private function prepareObjectsForBulkSave(array $objects): array
{
    $startTime = microtime(true);
    $objectCount = count($objects);
    
    $this->logger->debug('Starting bulk preparation', ['objectCount' => $objectCount]);

    if (empty($objects)) {
        return [];
    }

    $preparedObjects = [];
    $schemaCache = [];
    
    // Pre-process objects for inversedBy relationships
    foreach ($objects as $index => $object) {
        try {
            $selfData = $object['@self'] ?? [];
            $schemaId = $selfData['schema'] ?? null;
            
            if (!$schemaId) {
                $preparedObjects[$index] = $object;
                continue;
            }
            
            // Cache schemas to avoid repeated database calls
            if (!isset($schemaCache[$schemaId])) {
                try {
                    $schemaCache[$schemaId] = $this->schemaMapper->find($schemaId);
                } catch (\Exception $e) {
                    $preparedObjects[$index] = $object;
                    continue;
                }
            }
            
            $schema = $schemaCache[$schemaId];
            
            // Generate UUID if not present
            if (!isset($selfData['id']) || empty($selfData['id'])) {
                $selfData['id'] = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                $object['@self'] = $selfData;
            }
            
            // Handle pre-validation cascading for inversedBy properties
            [$processedObject, $uuid] = $this->handlePreValidationCascading($object, $schema, $selfData['id']);
            
            $preparedObjects[$index] = $processedObject;
            
        } catch (\Exception $e) {
            $this->logger->error('Error preparing object for bulk save', [
                'index' => $index,
                'error' => $e->getMessage()
            ]);
            $preparedObjects[$index] = $object; // Continue with original object
        }
    }

    // Handle bulk inverse relations within the batch
    $this->handleBulkInverseRelations($preparedObjects, $schemaCache);

    $endTime = microtime(true);
    $duration = round(($endTime - $startTime) * 1000, 2);
    $successCount = count($preparedObjects);
    
    $this->logger->debug('Bulk preparation completed', [
        'successCount' => $successCount,
        'duration' => $duration,
        'unit' => 'ms'
    ]);

    return array_values($preparedObjects);
}
```

### 2. Verbeter handleBulkInverseRelations om ook zonder writeBack te werken

```php
private function handleBulkInverseRelations(array &$preparedObjects, array $schemaCache): void
{
    $inverseRelationMap = [];
    $processedCount = 0;

    // Build inverse relation map by scanning all objects
    foreach ($preparedObjects as $index => $object) {
        $selfData = $object['@self'] ?? [];
        $schemaId = $selfData['schema'] ?? null;
        $objectUuid = $selfData['id'] ?? null;

        if (!$schemaId || !$objectUuid || !isset($schemaCache[$schemaId])) {
            continue;
        }

        $schema = $schemaCache[$schemaId];
        $schemaProperties = $schema->getProperties();

        // Scan each property for inverse relations
        foreach ($object as $property => $value) {
            if ($property === '@self' || !isset($schemaProperties[$property])) {
                continue;
            }

            $propertyConfig = $schemaProperties[$property];
            $items = $propertyConfig['items'] ?? [];
            
            // Check for inversedBy at property level (single object relations)
            $inversedBy = $propertyConfig['inversedBy'] ?? null;
            $writeBack = $propertyConfig['writeBack'] ?? false;
            
            // Check for inversedBy in array items (array of object relations)
            if (!$inversedBy && isset($items['inversedBy'])) {
                $inversedBy = $items['inversedBy'];
                $writeBack = $items['writeBack'] ?? false;
            }

            // Process if this property has inverse relations (writeBack not required for bulk)
            if ($inversedBy) {
                // Handle single object relations
                if (!is_array($value) && is_string($value) && \Symfony\Component\Uid\Uuid::isValid($value)) {
                    if (!isset($inverseRelationMap[$value])) {
                        $inverseRelationMap[$value] = [];
                    }
                    if (!isset($inverseRelationMap[$value][$inversedBy])) {
                        $inverseRelationMap[$value][$inversedBy] = [];
                    }
                    $inverseRelationMap[$value][$inversedBy][] = $objectUuid;
                    $processedCount++;
                }
                // Handle array of object relations
                elseif (is_array($value)) {
                    foreach ($value as $relatedUuid) {
                        if (is_string($relatedUuid) && \Symfony\Component\Uid\Uuid::isValid($relatedUuid)) {
                            if (!isset($inverseRelationMap[$relatedUuid])) {
                                $inverseRelationMap[$relatedUuid] = [];
                            }
                            if (!isset($inverseRelationMap[$relatedUuid][$inversedBy])) {
                                $inverseRelationMap[$relatedUuid][$inversedBy] = [];
                            }
                            $inverseRelationMap[$relatedUuid][$inversedBy][] = $objectUuid;
                            $processedCount++;
                        }
                    }
                }
            }
        }
    }

    $this->logger->debug('Processing inverse relations', [
        'processedCount' => $processedCount,
        'targetObjects' => count($inverseRelationMap)
    ]);

    // Apply inverse relations back to objects in the current batch
    $appliedCount = 0;
    foreach ($preparedObjects as $index => &$object) {
        $selfData = $object['@self'] ?? [];
        $objectUuid = $selfData['id'] ?? null;

        if ($objectUuid && isset($inverseRelationMap[$objectUuid])) {
            foreach ($inverseRelationMap[$objectUuid] as $property => $relatedUuids) {
                // Merge with existing values if any, ensuring uniqueness
                $existingValues = $object[$property] ?? [];
                if (!is_array($existingValues)) {
                    $existingValues = [];
                }
                $object[$property] = array_values(array_unique(array_merge($existingValues, $relatedUuids)));
                $appliedCount++;
            }
        }
    }

    $this->logger->debug('Applied inverse relation updates', [
        'appliedCount' => $appliedCount
    ]);
}
```

### 3. Voeg writeBack verwerking toe na bulk save

```php
private function handlePostSaveInverseRelations(array $savedObjects, array $schemaCache): void
{
    $writeBackCount = 0;
    
    foreach ($savedObjects as $savedObject) {
        $objectData = $savedObject->getObject();
        $schemaId = $savedObject->getSchema();
        
        if (!isset($schemaCache[$schemaId])) {
            continue;
        }
        
        $schema = $schemaCache[$schemaId];
        $schemaProperties = $schema->getProperties();
        
        foreach ($objectData as $property => $value) {
            if (!isset($schemaProperties[$property])) {
                continue;
            }
            
            $propertyConfig = $schemaProperties[$property];
            $items = $propertyConfig['items'] ?? [];
            
            // Check for writeBack enabled properties
            $writeBack = $propertyConfig['writeBack'] ?? ($items['writeBack'] ?? false);
            $inversedBy = $propertyConfig['inversedBy'] ?? ($items['inversedBy'] ?? null);
            
            if ($writeBack && $inversedBy && !empty($value)) {
                // Use SaveObject handler's writeBack functionality
                try {
                    $this->saveHandler->handleInverseRelationsWriteBack($savedObject, $schema, $objectData);
                    $writeBackCount++;
                } catch (\Exception $e) {
                    $this->logger->error('WriteBack failed for object', [
                        'objectUuid' => $savedObject->getUuid(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }
    
    $this->logger->debug('Processed writeBack operations', [
        'writeBackCount' => $writeBackCount
    ]);
}
```

## Implementatie Plan

1. **Vervang de huidige `prepareObjectsForBulkSave` methode**
2. **Update `handleBulkInverseRelations` om zonder writeBack te werken**
3. **Voeg post-save writeBack verwerking toe**
4. **Roep de nieuwe methodes aan in de juiste volgorde**

## Test Plan

1. Import organisatie.csv met deelnemers relaties
2. Controleer of inversedBy relaties correct worden aangemaakt
3. Verificeer dat writeBack operaties correct werken
4. Test performance met grote datasets
