# ObjectEntityMapper Refactoring Plan

**Target:** lib/Db/ObjectEntityMapper.php
**Current Size:** 4,985 lines, 68 methods (32 public + 36 private)
**Goal:** Split into domain-specific handlers, create thin facade
**Pattern:** Proven with SettingsService & ChatService

---

## Domain Analysis

### 1. Query & Search Handler (~1,200 lines)
**Responsibility:** Search, filtering, and finding objects

**Methods:**
- `find()` - Find single object by identifier
- `findAll()` - Find all objects with filters
- `searchObjects()` - Complex search with query DSL
- `countSearchObjects()` - Count search results
- `sizeSearchObjects()` - Get total size of search results
- `countAll()` - Count all objects
- `findByRelation()` - Find by relation
- `findMultiple()` - Find multiple by IDs
- `findBySchema()` - Find by schema ID

### 2. CRUD Handler (~400 lines)
**Responsibility:** Basic create, read, update, delete operations

**Methods:**
- `insert()` - Insert new object
- `update()` - Update existing object
- `delete()` - Delete object (soft delete)

### 3. Locking Handler (~200 lines)
**Responsibility:** Object locking/unlocking for concurrency control

**Methods:**
- `lockObject()` - Lock an object
- `unlockObject()` - Unlock an object

### 4. Statistics Handler (~800 lines)
**Responsibility:** Generate statistics and chart data

**Methods:**
- `getStatistics()` - Get general statistics
- `getRegisterChartData()` - Chart data by register
- `getSchemaChartData()` - Chart data by schema
- `getSizeDistributionChartData()` - Size distribution data

### 5. Facets Handler (~1,000 lines)
**Responsibility:** Faceted search and field analysis

**Methods:**
- `getSimpleFacets()` - Get simple facets
- `getFacetableFieldsFromSchemas()` - Discover facetable fields

### 6. Bulk Operations Handler (~1,200 lines)
**Responsibility:** Bulk data operations

**Methods:**
- `ultraFastBulkSave()` - High-performance bulk insert/update
- `deleteObjects()` - Bulk delete
- `publishObjectsBySchema()` - Publish all in schema
- `deleteObjectsBySchema()` - Delete all in schema
- `deleteObjectsByRegister()` - Delete all in register
- `publishObjects()` - Bulk publish
- `depublishObjects()` - Bulk depublish
- `bulkOwnerDeclaration()` - Bulk ownership update
- `setExpiryDate()` - Set expiry for retention

### 7. Query Builder Handler (~200 lines)
**Responsibility:** Query building utilities

**Methods:**
- `getQueryBuilder()` - Get query builder instance
- `getMaxAllowedPacketSize()` - MySQL config helper

---

## Implementation Strategy

### Phase 1: Create Handlers (Estimated: 3-4 hours)

**Handler Files to Create:**
1. `lib/Db/ObjectEntity/QuerySearchHandler.php` (~1,200 lines)
2. `lib/Db/ObjectEntity/CrudHandler.php` (~400 lines)
3. `lib/Db/ObjectEntity/LockingHandler.php` (~200 lines)
4. `lib/Db/ObjectEntity/StatisticsHandler.php` (~800 lines)
5. `lib/Db/ObjectEntity/FacetsHandler.php` (~1,000 lines)
6. `lib/Db/ObjectEntity/BulkOperationsHandler.php` (~1,200 lines)
7. `lib/Db/ObjectEntity/QueryBuilderHandler.php` (~200 lines)

**Total Handler Lines:** ~5,000 lines (well organized)

### Phase 2: Create Facade (Estimated: 30 minutes)

**ObjectEntityMapper.php** (target: ~400-500 lines)
- Inject all 7 handlers
- Delegate all public methods to appropriate handlers
- Keep only constructor and basic utilities

### Phase 3: DI Registration (Estimated: 30 minutes)

**Application.php**
- Register all 7 handlers explicitly
- Ensure proper dependency chain

### Phase 4: Testing (Estimated: 1 hour)

- Test each handler independently
- Test full mapper integration
- Verify no circular dependencies
- Check RBAC, multitenancy, and other concerns

---

## Key Considerations

### Shared Dependencies
All handlers will need:
- `IDBConnection $db`
- `LoggerInterface $logger`
- `IUserSession $userSession`
- `IGroupManager $groupManager`
- `IConfig $config`

Some may also need:
- `SchemaMapper $schemaMapper`
- `RegisterMapper $registerMapper`
- `OrganisationMapper $organisationMapper`

### Private Methods Distribution
- Query helpers → QuerySearchHandler
- RBAC helpers → Separate RbacHandler or keep in facade
- Multitenancy helpers → Keep in facade or separate handler
- SQL builders → QueryBuilderHandler

### Special Considerations
1. **RBAC & Multitenancy:** These are cross-cutting concerns used by many methods
   - Option A: Keep helper methods in facade
   - Option B: Create separate RbacHandler & MultitenancyHandler
   - **Recommended:** Keep in facade for simplicity

2. **Database Transactions:** Bulk operations need transaction support
   - Handlers should accept transaction context
   - Facade manages transaction lifecycle

3. **Performance:** ultraFastBulkSave is critical for performance
   - Must preserve current optimizations
   - No overhead from handler delegation

---

## Expected Results

**Before:**
- 1 file: 4,985 lines
- 68 methods mixed together
- Hard to test, maintain, understand

**After:**
- 8 files: 7 handlers (~200-1,200 lines each) + 1 facade (~400-500 lines)
- Clear separation of concerns
- Easy to test each handler independently
- Maintainable, understandable structure

**Reduction:** Main file from 4,985 → ~400-500 lines (90% reduction!)

---

## Risk Assessment

**Low Risk:**
- Pattern proven with SettingsService & ChatService
- All methods are public, clear interfaces
- Comprehensive tests can verify correctness

**Potential Issues:**
- RBAC/multitenancy logic is pervasive
- Some private methods are shared across domains
- Database transactions need careful handling

**Mitigation:**
- Start with simpler handlers (Locking, CRUD)
- Test incrementally
- Keep RBAC/multitenancy in facade initially
- Preserve transaction logic carefully

---

## Timeline

**Total Estimated Time:** 5-6 hours

1. Handler creation: 3-4 hours
2. Facade creation: 30 minutes
3. DI registration: 30 minutes
4. Testing & fixes: 1-1.5 hours

**Can be done in:** 1 session or split across 2 sessions

---

## Next Steps

1. ✅ Create backup of ObjectEntityMapper.php
2. ✅ Create handler directory structure
3. ✅ Extract QuerySearchHandler (largest, most complex)
4. ✅ Extract remaining handlers in order of complexity
5. ✅ Create facade
6. ✅ Register in DI
7. ✅ Test everything

Let's begin! 🚀
