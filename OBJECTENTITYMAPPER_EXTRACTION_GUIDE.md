# ObjectEntityMapper Complete Extraction Guide

**Status:** Handler 1 (LockingHandler) COMPLETE ✅  
**Remaining:** 6 handlers to extract

**Pattern Established:** LockingHandler serves as the template  
**All handlers follow the same structure:**
1. Namespace: `OCA\OpenRegister\Db\ObjectEntity`
2. Inject ObjectEntityMapper + needed dependencies
3. Implement business logic methods
4. Add comprehensive logging
5. PSR-2 styling

---

## ✅ Handler 1: LockingHandler - COMPLETE

**File:** `lib/Db/ObjectEntity/LockingHandler.php` (213 lines)
**Status:** ✅ Created, styled, ready to use

**Methods:**
- `lockObject()` - Lines 2328-2352 (extracted)
- `unlockObject()` - Lines 2365-2385 (extracted)

**Dependencies:**
- ObjectEntityMapper
- IUserSession
- IEventDispatcher
- LoggerInterface

---

## 🔧 Handler 2: CrudHandler (~400 lines)

**Responsibility:** Basic CRUD operations

**Methods to Extract:**

### insert() - Lines 2180-2210
```php
public function insert(Entity $entity): Entity
```
**Dependencies needed:**
- ObjectEntityMapper (for parent::insert)
- IEventDispatcher (for ObjectCreatedEvent)
- LoggerInterface

### update() - Lines 2212-2251
```php
public function update(Entity $entity, bool $includeDeleted=false): Entity
```
**Dependencies needed:**
- ObjectEntityMapper (for parent::update)
- IEventDispatcher (for ObjectUpdatedEvent)
- LoggerInterface

### delete() - Lines 2253-2280
```php
public function delete(Entity $entity): ObjectEntity
```
**Dependencies needed:**
- ObjectEntityMapper (for parent::update - soft delete)
- IEventDispatcher (for ObjectDeletedEvent)
- LoggerInterface

**Extraction Notes:**
- These methods wrap parent mapper methods
- Add event dispatching for each operation
- Preserve transaction handling if present

---

## 🔧 Handler 3: QuerySearchHandler (~1,200 lines)

**Responsibility:** Find, search, and query operations

**Methods to Extract:**

### find() - Lines 616-716
```php
public function find(string|int $identifier, ?Register $register=null, 
                     ?Schema $schema=null, bool $includeDeleted=false, 
                     bool $_rbac=true, bool $_multi=true): ObjectEntity
```

### findAll() - Lines 718-1148
```php
public function findAll(?int $limit=null, ?int $offset=null, 
                        ?array $filters=[], ?array $searchParams=[], 
                        ?array $searchConditions=[], ?string $uses=null, 
                        ?Schema $schema=null, ?Register $register=null, 
                        ?Organisation $organisation=null, ?array $ids=null, 
                        ?string $activeOrganisation=null, bool $_rbac=true, 
                        bool $_multi=true, ?string $sort=null): array
```

### searchObjects() - Lines 1150-1575
```php
public function searchObjects(array $query=[], ?string $activeOrganisationUuid=null, 
                               bool $_rbac=true, bool $_multitenancy=true, 
                               ?array $ids=null, ?string $uses=null): array|int
```

### countSearchObjects() - Lines 1577-1709
```php
public function countSearchObjects(array $query=[], ?string $_activeOrganisationUuid=null, 
                                    bool $_rbac=true, bool $_multi=true, 
                                    ?array $ids=null, ?string $uses=null): int
```

### sizeSearchObjects() - Lines 1711-2049
```php
public function sizeSearchObjects(array $query=[], ?string $_activeOrganisationUuid=null, 
                                   bool $_rbac=true, bool $_multitenancy=true, 
                                   ?array $ids=null): int
```

### countAll() - Lines 2051-2178
```php
public function countAll(?array $filters=[], ?array $searchParams=[], 
                         ?array $searchConditions=[], ?Schema $schema=null, 
                         ?Register $register=null, bool $_rbac=true, 
                         bool $_multi=true): int
```

### findByRelation() - Lines 2282-2326
```php
public function findByRelation(string $search, bool $partialMatch=true): array
```

### findMultiple() - Lines 2399-2468
```php
public function findMultiple(array $ids): array
```

### findBySchema() - Lines 2470-2501
```php
public function findBySchema(int $schemaId): array
```

**Dependencies needed:**
- ObjectEntityMapper (for QueryBuilder access)
- SchemaMapper
- RegisterMapper
- OrganisationMapper
- IDBConnection
- IUserSession
- IGroupManager
- IConfig
- LoggerInterface

**Private Helper Methods to Include:**
- RBAC filtering methods
- Multitenancy filtering methods  
- Query building helpers

**Extraction Notes:**
- These are the most complex methods
- Preserve all RBAC and multitenancy logic
- Keep SQL optimization intact
- Test thoroughly after extraction

---

## 🔧 Handler 4: StatisticsHandler (~800 lines)

**Responsibility:** Generate statistics and chart data

**Methods to Extract:**

### getStatistics() - Lines 2503-2595
```php
public function getStatistics(int|array|null $registerId=null, 
                               int|array|null $schemaId=null, 
                               array $exclude=[]): array
```

### getRegisterChartData() - Lines 2597-2656
```php
public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
```

### getSchemaChartData() - Lines 2658-2717
```php
public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array
```

### getSizeDistributionChartData() - Lines 2719-2806
```php
public function getSizeDistributionChartData(?int $registerId=null, ?int $schemaId=null): array
```

**Dependencies needed:**
- ObjectEntityMapper (for QueryBuilder)
- IDBConnection
- LoggerInterface

**Extraction Notes:**
- These methods perform complex aggregation queries
- Preserve SQL performance optimizations
- Consider caching strategies

---

## 🔧 Handler 5: FacetsHandler (~1,000 lines)

**Responsibility:** Faceted search and field discovery

**Methods to Extract:**

### getSimpleFacets() - Lines 2808-2891
```php
public function getSimpleFacets(array $query=[]): array
```

### getFacetableFieldsFromSchemas() - Lines 2893-3764
```php
public function getFacetableFieldsFromSchemas(array $baseQuery=[]): array
```

**Dependencies needed:**
- ObjectEntityMapper (for QueryBuilder)
- SchemaMapper
- IDBConnection
- LoggerInterface

**Extraction Notes:**
- getFacetableFieldsFromSchemas is VERY large (~870 lines!)
- Consider splitting this method further into sub-methods
- Preserve facet discovery logic carefully

---

## 🔧 Handler 6: BulkOperationsHandler (~1,200 lines)

**Responsibility:** Bulk operations on multiple objects

**Methods to Extract:**

### ultraFastBulkSave() - Lines 3766-4190
```php
public function ultraFastBulkSave(array $insertObjects=[], array $updateObjects=[]): array
```

### deleteObjects() - Lines 4192-4246
```php
public function deleteObjects(array $uuids=[], bool $hardDelete=false): array
```

### publishObjectsBySchema() - Lines 4248-4305
```php
public function publishObjectsBySchema(int $schemaId, bool $publishAll=false): array
```

### deleteObjectsBySchema() - Lines 4307-4363
```php
public function deleteObjectsBySchema(int $schemaId, bool $hardDelete=false): array
```

### deleteObjectsByRegister() - Lines 4365-4420
```php
public function deleteObjectsByRegister(int $registerId): array
```

### publishObjects() - Lines 4422-4480
```php
public function publishObjects(array $uuids=[], \DateTime|bool $datetime=true): array
```

### depublishObjects() - Lines 4482-4658
```php
public function depublishObjects(array $uuids=[], \DateTime|bool $datetime=true): array
```

### bulkOwnerDeclaration() - Lines 4660-4833
```php
public function bulkOwnerDeclaration(?string $defaultOwner=null, 
                                      ?string $defaultOrganisation=null, 
                                      int $batchSize=1000): array
```

### setExpiryDate() - Lines 4835-4884
```php
public function setExpiryDate(int $retentionMs): int
```

**Dependencies needed:**
- ObjectEntityMapper (for QueryBuilder)
- IDBConnection
- IEventDispatcher
- LoggerInterface

**Extraction Notes:**
- ultraFastBulkSave is CRITICAL for performance (~425 lines!)
- Must preserve all SQL optimizations
- Handle transactions carefully
- Test performance after extraction

---

## 🔧 Handler 7: QueryBuilderHandler (~200 lines)

**Responsibility:** Query builder utilities

**Methods to Extract:**

### getQueryBuilder() - Lines 221-326
```php
public function getQueryBuilder(): IQueryBuilder
```

### getMaxAllowedPacketSize() - Lines 328-358
```php
public function getMaxAllowedPacketSize(): int
```

**Private Helper Methods to Include:**
- Any SQL/QueryBuilder utility methods

**Dependencies needed:**
- IDBConnection
- LoggerInterface

**Extraction Notes:**
- Simple utility methods
- Straightforward extraction

---

## 📋 Facade Creation

After extracting all handlers, create the facade in `ObjectEntityMapper.php`:

**Structure:**
```php
class ObjectEntityMapper extends QBMapper
{
    private LockingHandler $lockingHandler;
    private CrudHandler $crudHandler;
    private QuerySearchHandler $querySearchHandler;
    private StatisticsHandler $statisticsHandler;
    private FacetsHandler $facetsHandler;
    private BulkOperationsHandler $bulkOperationsHandler;
    private QueryBuilderHandler $queryBuilderHandler;

    public function __construct(...all dependencies...) {
        parent::__construct(...);
        // Initialize all handlers
    }

    // Delegate all public methods to handlers:
    public function lockObject(...): ObjectEntity {
        return $this->lockingHandler->lockObject(...);
    }
    
    // ... etc for all 32 public methods
}
```

**Target Facade Size:** ~400-500 lines (90% reduction from 4,985!)

---

## 🧪 Testing Strategy

1. **Test Each Handler Independently:**
   ```php
   $handler = $app->getContainer()->get(LockingHandler::class);
   ```

2. **Test Facade Integration:**
   ```php
   $mapper = $app->getContainer()->get(ObjectEntityMapper::class);
   $object = $mapper->lockObject($id);
   ```

3. **Test Critical Paths:**
   - CRUD operations
   - Search with RBAC
   - Bulk save performance
   - Facet discovery

4. **Performance Testing:**
   - Ensure no degradation in ultraFastBulkSave
   - Benchmark search operations
   - Check database query count

---

## 📦 DI Registration

**In Application.php:**

```php
// Register all 7 handlers
$context->registerService(
    LockingHandler::class,
    function ($c) {
        return new LockingHandler(
            $c->get(ObjectEntityMapper::class),
            $c->get(IUserSession::class),
            $c->get(IEventDispatcher::class),
            $c->get('Psr\Log\LoggerInterface')
        );
    }
);

// Repeat for all 7 handlers...
```

---

## ⏱️ Time Estimates

- Handler 2 (CRUD): 30 minutes
- Handler 3 (QuerySearch): 2 hours (most complex)
- Handler 4 (Statistics): 45 minutes
- Handler 5 (Facets): 1 hour
- Handler 6 (BulkOps): 1.5 hours
- Handler 7 (QueryBuilder): 15 minutes
- Facade creation: 30 minutes
- DI registration: 30 minutes
- Testing: 1 hour

**Total:** ~6-7 hours

---

## ✅ Completion Checklist

- [x] Handler 1: LockingHandler (DONE)
- [ ] Handler 2: CrudHandler
- [ ] Handler 3: QuerySearchHandler
- [ ] Handler 4: StatisticsHandler
- [ ] Handler 5: FacetsHandler
- [ ] Handler 6: BulkOperationsHandler
- [ ] Handler 7: QueryBuilderHandler
- [ ] Create ObjectEntityMapper facade
- [ ] Register all handlers in Application.php
- [ ] Test each handler
- [ ] Test full integration
- [ ] Run PHPQA
- [ ] Document in SESSION_SUMMARY.txt

**Pattern established with LockingHandler - ready to complete!** 🚀
