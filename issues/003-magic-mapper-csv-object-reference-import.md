# 003 - Magic Mapper CSV Object Reference Import

**Status:** 📋 Open  
**Priority:** 🔴 High  
**Category:** 🐛 Bug  
**Effort:** ⏱️ 4-6h  
**Created:** 2026-01-05  
**Target:** Support CSV import for schemas with object references ($ref properties)

## 🎯 Problem Statement

When importing CSV files for schemas that contain object reference properties (using `$ref`), the import fails with a PostgreSQL error:

```
SQLSTATE[22P02]: Invalid text representation: 7 ERROR: invalid input syntax for type json
DETAIL: Token "412d2f3c" is invalid.
CONTEXT: JSON data, line 1: 412d2f3c...
```

This prevents importing complex schemas like `module`, `gebruik`, `dienst`, and `koppeling` which reference other objects like `organisatie`, `contactpersoon`, etc.

## 📊 Current Situation

### What Works
- ✅ CSV import for simple schemas (strings, numbers, booleans, dates)
- ✅ CSV import for schemas without object references
- ✅ Example: `organisatie.csv` imported successfully (3089 rows)

### What Fails
- ❌ CSV import for schemas with `$ref` properties
- ❌ Example: `module.csv` with `aanbieder` property

### Technical Details

**Schema Definition:**
```json
{
  "aanbieder": {
    "type": "object",
    "objectConfiguration": {
      "handling": "related-object"
    },
    "$ref": "#/components/schemas/organisatie"
  }
}
```

**Magic Mapper Behavior:**
- Creates column: `aanbieder JSONB`
- Expects: JSON object like `{"id": "uuid", "naam": "..."}` or `{"id": "uuid"}`

**CSV Data:**
- Contains: `"412d2f3c-230c-5c5a-9bb0-594c9d33f917"` (plain UUID string)
- PostgreSQL tries to parse as JSON → fails

**Affected Schemas:**
- ✅ organisatie: No complex references, imports fine
- ❌ module: Has `aanbieder`, `contactpersoon`, `suite`, `component` references
- ❌ moduleVersie: Has `module` reference
- ❌ gebruik: Has `afnemer`, `module`, `contactpersoon` references
- ❌ dienst: Has `aanbieder`, `contactpersoon`, `modules` references
- ❌ koppeling: Has `moduleA`, `moduleB`, `aanbieder`, `dienst` references

## 🔧 Proposed Solutions

### Option A: CSV Pre-processing (Quick Fix)
**Approach:** Transform CSV data before import

**Implementation:**
1. Detect columns that match schema properties with `$ref`
2. Transform UUID strings to JSON: `"uuid"` → `{"id": "uuid"}`
3. Import transformed CSV

**Pros:**
- ✅ No changes to Magic Mapper
- ✅ Can be implemented in import endpoint
- ✅ Quick to implement

**Cons:**
- ❌ Processing overhead for large files
- ❌ Doesn't solve root cause
- ❌ CSV files still incompatible without preprocessing

### Option B: Smart Column Type Detection (Recommended)
**Approach:** Make Magic Mapper detect `$ref` properties and use appropriate column type

**Implementation:**
1. In `MagicMapper::createTableFromSchema()`, detect properties with `$ref`
2. For `$ref` properties with `"handling": "related-object"`:
   - Use `VARCHAR(255)` instead of `JSONB`
   - Store only the UUID reference
3. When reading, resolve references if needed

**Pros:**
- ✅ Solves root cause
- ✅ CSV files work directly
- ✅ More efficient storage (UUID vs full JSON)
- ✅ Cleaner data model

**Cons:**
- ❌ Requires Magic Mapper changes
- ❌ Need to update bulk import to handle VARCHAR references
- ❌ May affect existing magic tables (migration needed?)

### Option C: Flexible JSONB Casting
**Approach:** Make bulk import flexible in accepting UUID strings for JSONB columns

**Implementation:**
1. In `MagicBulkHandler::executeBulkInsert()`, detect JSONB columns
2. For values that look like plain UUIDs:
   - Wrap in JSON object: `"uuid"` → `'{"id": "uuid"}'::jsonb`
3. For values that are already JSON, use as-is

**Pros:**
- ✅ Works with current schema
- ✅ Flexible input format
- ✅ Backward compatible

**Cons:**
- ❌ Complex parsing logic
- ❌ Performance overhead
- ❌ Still stores full JSON (more storage)

## 📋 Implementation Plan

### Recommended: Option B (Smart Column Type Detection)

#### Phase 1: Analysis (1h)
- [ ] Review all schemas with `$ref` properties
- [ ] Identify which use `"handling": "related-object"` vs other types
- [ ] Check if existing magic tables exist with JSONB reference columns

#### Phase 2: Magic Mapper Update (2-3h)
- [ ] Update `MagicMapper::createTableFromSchema()`:
  ```php
  // In column type detection
  if (isset($property['$ref']) && 
      isset($property['objectConfiguration']['handling']) && 
      $property['objectConfiguration']['handling'] === 'related-object') {
      return 'VARCHAR(255)'; // Store UUID reference
  }
  ```
- [ ] Update `MagicMapper::mapPropertyToSqlType()` with reference handling
- [ ] Add test cases for reference properties

#### Phase 3: Bulk Import Update (1h)
- [ ] Update `MagicBulkHandler` to handle VARCHAR reference columns
- [ ] Ensure UUID validation for reference columns
- [ ] Test with module.csv

#### Phase 4: Testing (1-2h)
- [ ] Test CSV import for all complex schemas:
  - module.csv
  - moduleVersie.csv  
  - gebruik.csv
  - dienst.csv
  - koppeling.csv
- [ ] Verify data integrity
- [ ] Test API retrieval with references
- [ ] Performance test with large datasets

#### Phase 5: Migration (if needed) (1h)
- [ ] Check if any existing magic tables have JSONB reference columns
- [ ] Create migration script to convert JSONB → VARCHAR if needed
- [ ] Document migration process

## 🧪 Testing Strategy

### Unit Tests
```php
public function testSchemaWithObjectReferenceCreatesVarcharColumn(): void
{
    $schema = [
        'properties' => [
            'aanbieder' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/organisatie',
                'objectConfiguration' => [
                    'handling' => 'related-object'
                ]
            ]
        ]
    ];
    
    $table = $this->magicMapper->createTableFromSchema($schema, 5, 30);
    $columnType = $this->getColumnType($table, 'aanbieder');
    
    $this->assertEquals('VARCHAR', $columnType);
}
```

### Integration Tests
1. Import module.csv (has multiple references)
2. Verify all 3000+ rows imported
3. Query via API: `/api/registers/5/objects?schema=41`
4. Verify reference UUIDs are preserved
5. Test search functionality with referenced data

### Performance Tests
- Import 10,000 rows with references
- Compare performance: VARCHAR vs JSONB storage
- Verify no degradation in query performance

## 📚 References

### Related Files
- `lib/Service/MagicMapper.php` - Table creation logic
- `lib/Db/MagicBulkHandler.php` - Bulk insert handling
- `lib/Service/UnifiedObjectMapper.php` - Routing logic

### Related Issues
- Issue #002: Magic Mapper Feature Completeness Verification (related)

### Test Data
- `softwarecatalog/data/module.csv` (2.6MB, ~3000 rows)
- `softwarecatalog/data/moduleVersie.csv` (4.1MB)
- `softwarecatalog/data/gebruik.csv`
- `softwarecatalog/data/dienst.csv`
- `softwarecatalog/data/koppeling.csv` (1.4MB)

## 📅 Status Updates

### 2026-01-05 - Issue Created
- Discovered during CSV import testing
- Root cause identified: JSONB columns for object references
- Three solution options documented
- Recommended approach: Smart column type detection (Option B)

### Current Blockers
- None - ready to implement

### Next Steps
1. Get team approval on recommended approach (Option B)
2. Implement Phase 1: Analysis
3. Start Phase 2: Magic Mapper updates

## 💬 Discussion

### Why Option B is Recommended

**Storage Efficiency:**
- VARCHAR(255): ~40 bytes per UUID
- JSONB: ~100-200 bytes per reference object
- For 3000+ rows with multiple references: significant savings

**Data Integrity:**
- Storing only UUID enforces clean referential data
- Easier to maintain consistency
- Simpler migration if we add foreign keys later

**CSV Compatibility:**
- Standard CSV format works directly
- No preprocessing needed
- Better developer experience

**Performance:**
- UUID comparison faster than JSONB parsing
- Indexes work better on VARCHAR than JSONB
- Simpler JOIN operations if needed

### Alternative Considerations

**Why not Option A?**
- Temporary fix, doesn't solve root issue
- Adds processing overhead
- Still need Option B eventually

**Why not Option C?**
- Complex parsing logic prone to edge cases
- Higher storage costs
- Performance overhead for type detection/conversion

### Future Enhancements

Once Option B is implemented, we can consider:
1. **Reference Resolution API**: Optionally expand references in API responses
2. **Foreign Key Constraints**: Add FK constraints for data integrity
3. **Cascade Operations**: Handle cascade deletes for referenced objects
4. **Reference Validation**: Validate that referenced UUIDs exist during import

---

**Last Updated:** 2026-01-05

