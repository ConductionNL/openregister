# Phase 1 Extracted Methods - Complete Inventory

## Summary
During Phase 1 refactoring, we extracted **37 new private methods** from 8 complex methods.
These methods now need comprehensive unit tests to protect the refactoring work.

## 1. ObjectService.php (6 extracted methods)

### From `findAll()`:
- `prepareConfig(array &$config): void`
- `resolveRelatedEntities(array $config, array $objects): array`
- `renderObjectsAsync(array $objects, array $config, ?array $registers, ?array $schemas, bool $_rbac, bool $_multitenancy): array`

### From `saveObject()`:
- `setContextFromParameters(?Register $register = null, ?Schema $schema = null): void`
- `extractUuidAndNormalizeObject(array|ObjectEntity $object, ?string $uuid): array`
- `checkSavePermissions(?string $uuid, bool $_rbac): void`
- `handleCascadingWithContextPreservation(array $object, ?string $uuid): array`
- `validateObjectIfRequired(array $object): void`
- `ensureObjectFolder(?string $uuid): ?int`

**Total: 9 methods (3 from findAll + 6 from saveObject)**

## 2. SaveObject.php (7 extracted methods)

### From `saveObject()`:
- `extractUuidAndSelfData(array $data, ?string $uuid): array`
- `resolveSchemaAndRegister(array $data, Register|string|int|null $register, Schema|string|int|null $schema): void`
- `findAndValidateExistingObject(?string $uuid): ?ObjectEntity`
- `handleObjectUpdate(ObjectEntity $existingObject, array $data, ?int $folderId, bool $_validation): ObjectEntity`
- `handleObjectCreation(array $data, ?int $folderId, bool $_validation): ObjectEntity`
- `processFilePropertiesWithRollback(ObjectEntity $savedObject, array $uploadedFiles): void`
- `clearImageMetadataIfFileProperty(array $data): array`

**Total: 7 methods**

## 3. SchemaService.php (9 extracted methods)

### From `comparePropertyWithAnalysis()`:
- `compareType(array $property, array $analysis): ?string`
- `compareStringConstraints(array $property, array $analysis): ?string`
- `compareNumericConstraints(array $property, array $analysis): ?string`
- `compareNullableConstraint(array $property, array $analysis): ?string`
- `compareEnumConstraint(array $property, array $analysis): ?string`

### From `recommendPropertyType()`:
- `getTypeFromFormat(?string $format): ?string`
- `getTypeFromPatterns(array $analysis): ?string`
- `normalizeSingleType(string $type): string`
- `getDominantType(array $types): string`

**Total: 9 methods (5 from comparePropertyWithAnalysis + 4 from recommendPropertyType)**

## 4. ConfigurationController.php (1 extracted method)

### From `update()`:
- `applyConfigurationUpdates(array &$config, array $input): void`

**Total: 1 method**

## 5. FilesController.php (7 extracted methods)

### From `createMultipart()`:
- `validateAndGetObject(string $uuid): ObjectEntity`
- `extractUploadedFiles(): array`
- `normalizeMultipartFiles(array $uploadedFiles): array`
- `normalizeSingleFile(array $file, string $propertyName): array`
- `normalizeMultipleFiles(array $files, string $propertyName): array`
- `processUploadedFiles(array $normalizedFiles, ObjectEntity $object): void`
- `validateUploadedFile(array $file): void`

**Total: 7 methods**

## 6. SaveObjects.php (8 extracted methods)

### From `saveObjects()`:
- `createEmptyResult(): array`
- `logBulkOperationStart(array $objects, bool $async): void`
- `prepareObjectsForSave(array $objects): array`
- `initializeResult(array $preparedObjects): array`
- `processObjectsInChunks(array $preparedObjects, array $result, bool $async): array`
- `mergeChunkResult(array &$result, array $chunkResult): void`
- `calculatePerformanceMetrics(array $result, float $totalStartTime): array`

**Note: One additional helper extracted:**
- `processChunk(array $chunk, bool $async): array`

**Total: 8 methods**

## Testing Priority Matrix

### Priority 1: CRITICAL (High Complexity Reduction)
**SaveObject.php** - 7 methods
- Highest NPath reduction (411M → 30)
- Most complex logic
- Core business logic
- **Estimated time: 4-5 hours**

**ObjectService.php** - 9 methods
- Second highest impact
- Main service entry points
- **Estimated time: 4-5 hours**

### Priority 2: HIGH (Significant Complexity)
**SchemaService.php** - 9 methods
- Schema validation logic
- Complex comparison algorithms
- **Estimated time: 3-4 hours**

**SaveObjects.php** - 8 methods
- Bulk operations
- Performance-critical
- **Estimated time: 3-4 hours**

### Priority 3: MEDIUM (Controller Logic)
**FilesController.php** - 7 methods
- File upload handling
- Validation logic
- **Estimated time: 2-3 hours**

**ConfigurationController.php** - 1 method
- Configuration updates
- **Estimated time: 0.5-1 hour**

## Testing Strategy

### Phase A: Service Layer Tests (Priority 1)
1. **SaveObject.php** (4-5 hours)
   - Test UUID extraction
   - Test schema/register resolution
   - Test update vs create handling
   - Test file property handling
   - Test rollback scenarios

2. **ObjectService.php** (4-5 hours)
   - Test config preparation
   - Test entity resolution
   - Test async rendering
   - Test context management
   - Test permission checks
   - Test validation triggers

**Subtotal: 8-10 hours**

### Phase B: Complex Logic Tests (Priority 2)
3. **SchemaService.php** (3-4 hours)
   - Test type comparison
   - Test constraint comparison
   - Test format detection
   - Test pattern matching
   - Test type normalization

4. **SaveObjects.php** (3-4 hours)
   - Test bulk preparation
   - Test chunk processing
   - Test result merging
   - Test performance metrics
   - Test error handling in bulk

**Subtotal: 6-8 hours**

### Phase C: Controller Tests (Priority 3)
5. **FilesController.php** (2-3 hours)
   - Test file normalization
   - Test single vs multiple files
   - Test file validation
   - Test upload processing

6. **ConfigurationController.php** (0.5-1 hour)
   - Test configuration updates
   - Test data-driven approach

**Subtotal: 2.5-4 hours**

## Total Estimated Time
- **Minimum: 16.5 hours**
- **Maximum: 22 hours**
- **Average: ~19 hours**

## Success Criteria
- [ ] All 37 methods have unit tests
- [ ] Code coverage > 80% for extracted methods
- [ ] All tests pass in CI/CD
- [ ] Edge cases covered (null, empty, invalid inputs)
- [ ] Error handling tested
- [ ] Happy path + unhappy path coverage

## Test File Structure
```
tests/Unit/Service/
  ├── ObjectServiceRefactoredMethodsTest.php (9 tests)
  ├── ObjectHandlers/
  │   ├── SaveObjectRefactoredMethodsTest.php (7 tests)
  │   └── SaveObjectsRefactoredMethodsTest.php (8 tests)
  ├── SchemaServiceRefactoredMethodsTest.php (9 tests)
  └── ConfigurationControllerRefactoredMethodsTest.php (1 test)

tests/Unit/Controller/
  └── FilesControllerRefactoredMethodsTest.php (7 tests)
```

## Notes
- Existing `SaveObjectTest.php` already covers main `saveObject()` method
- New tests focus on **extracted private methods** (using reflection if needed)
- Or make methods `protected` for testing, or test through public methods
- Follow existing patterns from `SaveObjectTest.php`

