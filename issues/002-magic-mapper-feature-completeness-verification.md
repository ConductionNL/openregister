# Issue #002: Magic Mapper Feature Completeness Verification

**Status:** 📋 Open  
**Priority:** 🔴 High  
**Effort:** ⏱️ 4-6 hours  
**Created:** 2026-01-05  
**Target:** Verify all advanced OpenRegister features work with Magic Mapper

---

## 📊 Problem Statement

The Magic Mapper implementation is complete for basic CRUD operations and search, but we need to verify that **all advanced features** of OpenRegister work correctly with magic-mapped objects.

Currently verified:
- ✅ Basic CRUD (Create, Read, Update, Delete)
- ✅ Search (single + multi-table)
- ✅ Fuzzy search with pg_trgm
- ✅ Bulk operations
- ✅ Event dispatching

**Need to verify:**
- ❓ Locking/Unlocking objects
- ❓ Relations (uses/used by)
- ❓ Faceting and aggregations
- ❓ Publishing/Unpublishing
- ❓ Contracts
- ❓ File attachments
- ❓ Extend pattern
- ❓ Merge operations

---

## 🎯 Features to Verify

### 1. 🔒 Locking/Unlocking

**What it does:**
Objects can be locked to prevent concurrent edits. When locked, only the lock owner can modify the object.

**API Endpoints:**
```http
POST /api/objects/{register}/{schema}/{id}/lock
POST /api/objects/{register}/{schema}/{id}/unlock
```

**Current Implementation:**
- Located in: `ObjectsController::lock()` and `ObjectsController::unlock()`
- Uses: `ObjectService::lockObject()` / `ObjectService::unlockObject()`
- Database fields: `locked`, `lock_expires`, `lock_owner`

**Magic Mapper Considerations:**
- ✅ Lock fields are in `MagicMapper::METADATA_COLUMNS` (should work)
- ❓ Need to verify lock expiry checking works
- ❓ Need to verify lock owner validation works
- ❓ Need to verify lock cleanup on delete

**Test Plan:**
1. Create object in magic-mapped schema
2. Lock the object via API
3. Verify locked status in database
4. Try to edit while locked (should fail)
5. Unlock and verify edit works again
6. Test lock expiry (wait or manipulate timestamp)

**Success Criteria:**
- [ ] Lock can be created on magic-mapped object
- [ ] Locked objects cannot be edited by others
- [ ] Lock owner can edit locked objects
- [ ] Locks expire correctly
- [ ] Unlock works correctly

---

### 2. 🔗 Relations (uses/used)

**What it does:**
Objects can reference other objects. The system tracks:
- **Uses:** Objects that this object references
- **Used by:** Objects that reference this object

**API Endpoints:**
```http
GET /api/objects/{register}/{schema}/{id}/uses
GET /api/objects/{register}/{schema}/{id}/used
```

**Current Implementation:**
- Located in: `ObjectsController::uses()` and `ObjectsController::used()`
- Uses: `ObjectService` to traverse relations
- Relation storage: Via `_relations` field or schema properties

**Magic Mapper Considerations:**
- ❓ Relations might be stored in JSON columns (not SQL columns)
- ❓ Need efficient query for "used by" (reverse lookup)
- ❓ Cross-table relations (object in table A references object in table B)
- ❓ Performance of relation queries with magic mapper

**Test Plan:**
1. Create object A in magic-mapped schema
2. Create object B that references A
3. Query A's "used by" endpoint → should return B
4. Query B's "uses" endpoint → should return A
5. Test cross-schema relations
6. Test performance with many relations

**Success Criteria:**
- [ ] Object can reference other objects
- [ ] "uses" endpoint returns correct references
- [ ] "used by" endpoint returns correct reverse references
- [ ] Cross-table relations work
- [ ] Performance is acceptable (<500ms)

**Potential Issues:**
- Relations might be stored in JSON, which magic mapper converts to TEXT
- Need to ensure relation queries work with both blob and magic storage
- Indexing might be needed for performance

---

### 3. 📊 Faceting

**What it does:**
Faceting provides aggregated counts for search results, useful for filters:
```json
{
  "results": [...],
  "facets": {
    "type": {"module": 50, "organisatie": 30},
    "status": {"active": 60, "inactive": 20}
  }
}
```

**API Parameters:**
```http
GET /api/objects?_facets=type,status&_facetable=type,status,category
```

**Current Implementation:**
- Located in: `ObjectService::searchObjectsPaginated()`
- Builds aggregation queries
- Returns counts per facet value

**Magic Mapper Considerations:**
- ⚠️ Faceting on magic mapper columns should be FAST (real SQL columns)
- ❓ Need to ensure faceting works with snake_case columns
- ❓ Faceting across multiple tables (multi-schema search)
- ❓ Performance comparison: magic mapper vs blob storage

**Test Plan:**
1. Create objects with facetable properties (e.g., "type", "status")
2. Query with `_facets=type,status`
3. Verify facet counts are correct
4. Test with filters applied
5. Test cross-table faceting
6. Measure performance

**Success Criteria:**
- [ ] Faceting returns correct counts
- [ ] Facets work with magic-mapped columns
- [ ] Cross-table faceting works (if applicable)
- [ ] Performance is good (<300ms with facets)
- [ ] Facets respect filters and search terms

**Potential Issues:**
- Column name translation (camelCase → snake_case)
- Faceting across multiple tables needs special handling
- Need to ensure facet queries use indexes

---

### 4. 📢 Publishing/Unpublishing

**What it does:**
Objects can be published/unpublished, affecting visibility.

**API Endpoints:**
```http
POST /api/objects/{register}/{schema}/{id}/publish
POST /api/objects/{register}/{schema}/{id}/depublish
```

**Current Implementation:**
- Located in: `ObjectsController::publish()` and `ObjectsController::depublish()`
- Sets `published` timestamp
- Filters queries based on `_published` parameter

**Magic Mapper Considerations:**
- ✅ `published` is in metadata columns (should work)
- ❓ Need to verify publish/unpublish updates magic table
- ❓ Need to verify `_published` filter works in searches

**Test Plan:**
1. Create object in magic-mapped schema
2. Publish via API
3. Verify `published` timestamp in database
4. Query with `_published=true` → should return object
5. Depublish
6. Query with `_published=true` → should NOT return object

**Success Criteria:**
- [ ] Publish sets published timestamp
- [ ] Unpublish clears published timestamp
- [ ] `_published` filter works in searches
- [ ] Published status persists correctly

---

### 5. 📄 Contracts

**What it does:**
Objects can have associated contracts.

**API Endpoints:**
```http
GET /api/objects/{register}/{schema}/{id}/contracts
```

**Current Implementation:**
- Located in: `ObjectsController::contracts()`
- Queries related contract objects

**Magic Mapper Considerations:**
- ❓ Similar to relations - need to verify cross-table queries work

**Test Plan:**
1. Create object with contracts
2. Query contracts endpoint
3. Verify correct contracts are returned

**Success Criteria:**
- [ ] Contracts endpoint returns correct data
- [ ] Works for magic-mapped objects

---

### 6. 📎 File Attachments

**What it does:**
Objects can have file attachments.

**API Endpoints:**
```http
GET /api/objects/{register}/{schema}/{id}/files
POST /api/objects/{register}/{schema}/{id}/files
DELETE /api/objects/{register}/{schema}/{id}/files/{fileId}
```

**Current Implementation:**
- Located in: `FilesController`
- Files stored separately, linked to object by UUID

**Magic Mapper Considerations:**
- ✅ Files are linked by UUID (not object ID)
- ✅ Should work automatically since UUID is consistent

**Test Plan:**
1. Create object in magic-mapped schema
2. Upload file attachment
3. List files via API
4. Download file
5. Delete file
6. Verify file operations work correctly

**Success Criteria:**
- [ ] Files can be attached to magic-mapped objects
- [ ] File listing works
- [ ] File download works
- [ ] File deletion works

---

### 7. 🔄 Extend Pattern

**What it does:**
Objects can include related objects in response via `_extend` parameter:
```http
GET /api/objects/{register}/{schema}/{id}?_extend=author,category
```

**Current Implementation:**
- Located in: `RenderObject` class
- Fetches and embeds related objects

**Magic Mapper Considerations:**
- ❓ Need to ensure extend works when fetching from magic tables
- ❓ Extended objects might be in different tables (magic or blob)
- ❓ Performance impact of extending across tables

**Test Plan:**
1. Create object A in magic-mapped schema
2. Create object B with reference to A
3. Query B with `_extend=referenceField`
4. Verify A is embedded in response
5. Test cross-table extends
6. Measure performance

**Success Criteria:**
- [ ] Extend works for magic-mapped objects
- [ ] Extended objects are correctly embedded
- [ ] Cross-table extends work
- [ ] Performance is acceptable

---

### 8. 🔀 Merge Operations

**What it does:**
Two objects can be merged, combining their data.

**API Endpoints:**
```http
POST /api/objects/{register}/{schema}/{id}/merge
```

**Current Implementation:**
- Located in: `ObjectsController::merge()`
- Merges data from source into target
- Deletes source object

**Magic Mapper Considerations:**
- ❓ Need to ensure merge updates magic table correctly
- ❓ Relations from source should transfer to target
- ❓ Files from source should transfer to target

**Test Plan:**
1. Create two objects in magic-mapped schema
2. Merge object A into object B
3. Verify B contains merged data
4. Verify A is deleted
5. Verify relations transferred
6. Verify files transferred (if applicable)

**Success Criteria:**
- [ ] Merge combines data correctly
- [ ] Magic table is updated
- [ ] Source object is deleted from magic table
- [ ] Relations are preserved

---

## 🧪 Testing Strategy

### Phase 1: Individual Feature Tests
Test each feature in isolation:
1. Create test objects in magic-mapped schema
2. Execute feature-specific operations
3. Verify database state
4. Verify API responses
5. Check error handling

### Phase 2: Integration Tests
Test features in combination:
1. Lock → Edit → Unlock
2. Create → Attach File → Extend → Merge
3. Create Relations → Facet on Relations
4. Publish → Search with _published filter

### Phase 3: Performance Tests
Measure performance impact:
1. Locking overhead
2. Relation query performance
3. Faceting speed comparison (magic vs blob)
4. Extend performance with mixed storage

### Phase 4: Newman Tests Update
Add tests to `openregister-crud.postman_collection.json`:
- Lock/Unlock tests
- Relation tests
- Faceting tests
- Publishing tests
- File attachment tests
- Extend tests
- Merge tests

---

## 📝 Implementation Checklist

For each feature that doesn't work:

1. **Identify the issue**
   - [ ] Feature uses object ID instead of UUID?
   - [ ] Feature assumes blob storage structure?
   - [ ] Feature doesn't check for magic mapper?

2. **Implement fix**
   - [ ] Update code to use UnifiedObjectMapper
   - [ ] Add magic mapper-specific logic if needed
   - [ ] Update queries to work with magic tables

3. **Test the fix**
   - [ ] Unit tests
   - [ ] Integration tests
   - [ ] Newman tests
   - [ ] Performance tests

4. **Document the change**
   - [ ] Update code comments
   - [ ] Add to CHANGELOG
   - [ ] Update API documentation

---

## 🎯 Success Criteria

This issue is complete when:

- [ ] All 8 features verified and working with magic mapper
- [ ] Newman tests updated with feature tests
- [ ] All tests passing (100%)
- [ ] Performance meets expectations
- [ ] Documentation updated
- [ ] No regressions in blob storage functionality

---

## 📚 References

- Magic Mapper implementation: `openregister/lib/Db/MagicMapper.php`
- Controller methods: `openregister/lib/Controller/ObjectsController.php`
- Object service: `openregister/lib/Service/ObjectService.php`
- Newman tests: `openregister/tests/integration/openregister-crud.postman_collection.json`

---

## 🔄 Status Updates

| Date | Status | Notes |
|------|--------|-------|
| 2026-01-05 | Created | Initial feature list documented |

---

## 💬 Priority Order

Suggested verification order:

1. **Publishing** (Easy, commonly used)
2. **Locking** (Medium, important for concurrent editing)
3. **File Attachments** (Medium, should work automatically)
4. **Extend Pattern** (Medium, complex but important)
5. **Relations** (Hard, complex cross-table queries)
6. **Faceting** (Hard, needs aggregation logic)
7. **Contracts** (Similar to relations)
8. **Merge** (Hard, complex operation)

---

## 🐛 Known Issues

None yet - to be discovered during verification.

---

## 💡 Notes

- Some features might work out-of-the-box if they use UUID-based lookups
- Features that rely on object ID (integer) might need updates
- Cross-table operations (relations, extends) are most complex
- Performance testing is critical for features that query multiple tables


