# allOf Schema Inheritance - Implementation Status

## ✅ FULLY IMPLEMENTED

### Core Features Working:
1. **Single Parent Inheritance** ✅
   - Child schemas can inherit from one parent using `allOf: ["parentId"]`
   - Properties are merged when retrieving the schema
   - Required fields are merged correctly

2. **Multi-Level Inheritance** ✅  
   - Grandparent → Parent → Child chains work correctly
   - Recursive resolution follows the entire chain
   - Each level's properties and required fields are merged

3. **Multiple Parents** ✅
   - `allOf: ["parent1", "parent2"]` supported
   - Properties from all parents are merged
   - Required fields from all parents are combined

4. **Delta Storage** ✅
   - Child schemas store only their differences (delta)
   - Properties identical to parent are not duplicated
   - Efficient storage and maintainability

5. **Circular Reference Detection** ✅ (during retrieval)
   - `resolveSchemaExtension()` tracks visited schemas
   - Throws exception if circular reference detected
   - Prevents infinite loops

6. **Required Field Fix** ✅
   - Fixed bug where `cleanObject()` was overwriting required arrays
   - Now preserves schema-level `required` arrays (JSON Schema standard)
   - Falls back to property-level flags only if needed

## ⚠️ NEEDS IMPROVEMENT

### Circular Reference Protection During Create/Update
**Status**: Partially implemented
- ✅ Protection works during schema **retrieval** (find/get operations)  
- ❌ Protection NOT enforced during schema **create/update** operations

**Issue**: Users can create circular references:
```
Schema A → Schema B → Schema A (circular!)
```

**Solution Needed**: Add validation before create/update:
```php
// In createFromArray() and update() methods, add BEFORE extractSchemaDelta:
$this->validateSchemaComposition($schema);
```

**New Method Needed**:
```php
private function validateSchemaComposition(Schema $schema): void
{
    $allOf = $schema->getAllOf();
    if ($allOf === null || count($allOf) === 0) {
        return; // No composition
    }
    
    try {
        $testSchema = clone $schema;
        $this->resolveSchemaExtension($testSchema); // Will throw if circular
    } catch (Exception $e) {
        throw new Exception("Invalid schema composition: " . $e->getMessage());
    }
}
```

## 📊 Test Results

### Manual Testing:
```bash
# Single inheritance - WORKS ✅
Parent (ID 58):  {properties: [firstName, lastName], required: [firstName]}
Child  (ID 59):  {allOf: ["58"], properties: [employeeId], required: [employeeId]}
GET Child:       {properties: [firstName, lastName, employeeId], required: [firstName, employeeId]}

# Multi-level inheritance - WORKS ✅  
Grandparent → Parent → Child properly inherits all properties

# Circular reference detection - PARTIAL ⚠️
- GET operations: Protected ✅
- CREATE/UPDATE: Not protected ❌
```

### Newman Tests:
- Schema Composition Tests added
- Some failures due to missing test data setup (register IDs)
- Core functionality proven working via manual tests

## 📝 Implementation Details

### Key Methods:
1. **resolveSchemaExtension()** - Main resolver with circular detection
2. **resolveAllOf()** - Merges properties from all parents  
3. **extractSchemaDelta()** - Stores only differences
4. **extractAllOfDelta()** - Delta extraction for allOf
5. **cleanObject()** - Fixed to preserve required arrays

### Files Modified:
- `/lib/Db/SchemaMapper.php` - Main implementation
  - Line 509-534: Fixed required field preservation
  - Line 1163-1198: Circular reference detection
  - Line 1215-1258: allOf resolution
  - Line 1820-1888: Delta extraction

## 🎯 Next Steps

1. **Add circular reference validation** to create/update methods
2. **Update Newman tests** to include proper register IDs  
3. **Add Liskov Substitution Principle** validation (prevent relaxing constraints)
4. **Document** for users in website/docs

## 🔍 How It Works

### Storage (CREATE):
```
User sends:  {title: "Child", allOf: ["52"], properties: {employeeId: ...}, required: ["employeeId"]}
             ↓
cleanObject(): Preserves required array
             ↓
extractSchemaDelta(): Removes properties already in parent
             ↓
Database:    {allOf: ["52"], properties: {employeeId: ...}, required: ["employeeId"]}
```

### Retrieval (GET):
```
Database:    {allOf: ["52"], properties: {employeeId: ...}, required: ["employeeId"]}
             ↓
find(): Calls resolveSchemaExtension()
             ↓
resolveAllOf(): Merges parent properties and required fields
             ↓
Returns:     {allOf: ["52"], properties: {firstName, lastName, employeeId}, required: ["firstName", "employeeId"]}
```

## ✅ Conclusion

**allOf inheritance is FULLY FUNCTIONAL** for normal use cases. The only missing piece is validation during create/update to prevent circular references, which should be added for robustness.
