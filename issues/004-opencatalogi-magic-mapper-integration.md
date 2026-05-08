# 004 - OpenCatalogi Magic Mapper Integration

**Status:** 📋 Open  
**Priority:** 🟡 Medium  
**Category:** ✨ Feature  
**Effort:** ⏱️ 6-8h  
**Created:** 2026-01-05  
**Target:** Enable OpenCatalogi to search across multiple magic tables

## 🎯 Problem Statement

OpenCatalogi app needs to be configured to use Magic Mapper tables as data source for publications. Currently:
- OpenRegister has 3089 organisaties in magic table `oc_openregister_table_5_30`
- OpenCatalogi cannot access this data yet
- Publications endpoint `/api/{catalogSlug}` returns "Catalog not found"

**Goal:** Configure OpenCatalogi to expose OpenRegister magic tables via publications API, enabling cross-table search across multiple schemas.

## 📊 Current Situation

### What We Have
- ✅ OpenRegister with working Magic Mapper
- ✅ Register "voorzieningen" (ID: 5, slug: voorzieningen)
- ✅ Schema "organisatie" (ID: 30) with 3089 rows in magic table
- ✅ OpenCatalogi app installed and enabled
- ✅ API endpoints available at `/apps/opencatalogi/api/*`

### What's Missing
- ❌ Catalog configuration in OpenCatalogi
- ❌ Link between OpenCatalogi and OpenRegister data
- ❌ Publications endpoint configuration
- ❌ Multi-schema search setup

### OpenCatalogi Architecture

**Key Components:**
1. **Catalogs** - Container for publications from specific data sources
2. **Publications** - Individual items searchable via API
3. **Metadata** - Structured data about publications
4. **Search** - Global search across all catalogs

**API Endpoints:**
- `GET /apps/opencatalogi/api/catalogi` - List catalogs
- `GET /apps/opencatalogi/api/{catalogSlug}` - List publications in catalog
- `GET /apps/opencatalogi/api/{catalogSlug}/{id}` - Get publication details
- `GET /apps/opencatalogi/api/search` - Global search

**Data Model:**
```json
{
  "title": "Catalog Name",
  "summary": "Short description",
  "description": "Long description",
  "listed": true,
  "search": "opencatalogi"
}
```

## 🔧 Proposed Solutions

### Option A: Direct OpenRegister Integration (Recommended)
**Approach:** Configure OpenCatalogi to query OpenRegister API directly

**Architecture:**
```
OpenCatalogi → OpenRegister API → UnifiedObjectMapper → Magic Tables
```

**Implementation:**
1. Configure catalog with OpenRegister connection details
2. Map OpenRegister schemas to publication types
3. Use OpenRegister search API for queries
4. Transform OpenRegister objects to publication format

**Pros:**
- ✅ Uses existing OpenRegister API
- ✅ No duplicate data
- ✅ Real-time data access
- ✅ Benefits from UnifiedObjectMapper routing

**Cons:**
- ❌ Requires OpenCatalogi configuration
- ❌ May need custom mapping logic

### Option B: OpenCatalogi as OpenRegister Client
**Approach:** Make OpenCatalogi store catalog config in OpenRegister

**Architecture:**
```
OpenCatalogi → Catalog (OpenRegister object) → Magic Tables
```

**Implementation:**
1. Create "catalogs" schema in OpenRegister
2. Store catalog configurations as objects
3. Link to register/schema combinations
4. Query magic tables based on catalog config

**Pros:**
- ✅ Leverages OpenRegister for all storage
- ✅ Unified data model
- ✅ Easy to configure via API

**Cons:**
- ❌ Tight coupling between apps
- ❌ More complex setup

### Option C: Elasticsearch/Solr Integration
**Approach:** Index magic table data in search engine

**Architecture:**
```
Magic Tables → Indexer → Elasticsearch → OpenCatalogi
```

**Implementation:**
1. Create indexer service for magic tables
2. Push data to Elasticsearch on insert/update
3. OpenCatalogi queries Elasticsearch
4. Cross-table search via Elasticsearch

**Pros:**
- ✅ High-performance search
- ✅ Advanced search features (facets, highlighting)
- ✅ Scales well

**Cons:**
- ❌ Additional infrastructure (Elasticsearch/Solr)
- ❌ Data synchronization complexity
- ❌ More moving parts

## 📋 Implementation Plan

### Recommended: Option A (Direct OpenRegister Integration)

#### Phase 1: Research & Analysis (2h)
- [ ] Study OpenCatalogi catalog configuration
- [ ] Review OpenCatalogi\PublicationsController implementation
- [ ] Identify configuration storage mechanism
- [ ] Document OpenCatalogi data model requirements

#### Phase 2: Catalog Configuration (2h)
- [ ] Create catalog configuration schema
- [ ] Define mapping: OpenRegister schema → Publication format
- [ ] Configure catalog with register/schema references
- [ ] Test catalog creation via API

**Example Configuration:**
```json
{
  "title": "Software Catalog",
  "summary": "VNG Softwarecatalogus data",
  "description": "Catalogus met organisaties, modules, en gebruik",
  "listed": true,
  "search": "opencatalogi",
  "source": {
    "type": "openregister",
    "register": "voorzieningen",
    "schemas": [
      {
        "slug": "organisatie",
        "publicationType": "organization"
      },
      {
        "slug": "module", 
        "publicationType": "application"
      },
      {
        "slug": "gebruik",
        "publicationType": "usage"
      }
    ]
  }
}
```

#### Phase 3: Publications Controller Integration (3h)
- [ ] Update `PublicationsController::index()`:
  - Detect if catalog uses OpenRegister source
  - Call OpenRegister API: `GET /api/registers/{register}/objects?schema={schema}`
  - Transform objects to publication format
  - Return standardized response

- [ ] Update `PublicationsController::show()`:
  - Fetch single object from OpenRegister
  - Transform to publication format
  - Handle references/relations

- [ ] Implement object-to-publication transformer:
  ```php
  class OpenRegisterPublicationTransformer
  {
      public function transform(array $object, Schema $schema): array
      {
          return [
              'id' => $object['id'],
              'title' => $object[$schema->objectNameField] ?? 'Untitled',
              'summary' => $object[$schema->objectSummaryField] ?? '',
              'description' => $object[$schema->objectDescriptionField] ?? '',
              'published' => $object['created'] ?? null,
              'modified' => $object['modified'] ?? null,
              'schema' => $schema->slug,
              'data' => $object,
          ];
      }
  }
  ```

#### Phase 4: Search Integration (2h)
- [ ] Implement cross-schema search
- [ ] Use OpenRegister search with `_schemas` filter:
  ```
  GET /api/registers/5/objects?_search=gemeente&_schemas=organisatie,module,gebruik
  ```
- [ ] Aggregate results from multiple schemas
- [ ] Rank and sort combined results

#### Phase 5: Testing (1h)
- [ ] Test catalog creation
- [ ] Test publications listing: `GET /api/software-catalog`
- [ ] Test single publication: `GET /api/software-catalog/{id}`
- [ ] Test search: `GET /api/search?query=gemeente`
- [ ] Performance test with 3000+ results

## 🧪 Testing Strategy

### Manual Testing

**Step 1: Create Catalog**
```bash
curl -X POST http://localhost/apps/opencatalogi/api/catalogi \
  -H 'Content-Type: application/json' \
  -u admin:admin \
  -d '{
    "title": "Software Catalog",
    "summary": "VNG Softwarecatalogus",
    "description": "Organisaties, modules en gebruik uit de VNG catalogus",
    "listed": true,
    "search": "opencatalogi",
    "source": {
      "type": "openregister",
      "register": "voorzieningen",
      "schemas": ["organisatie"]
    }
  }'
```

**Step 2: List Publications**
```bash
curl -u admin:admin \
  'http://localhost/apps/opencatalogi/api/software-catalog?_limit=10'
```

**Expected Response:**
```json
{
  "results": [
    {
      "id": "uuid",
      "title": "VNG Realisatie",
      "summary": "...",
      "schema": "organisatie",
      "data": { /* full object */ }
    }
  ],
  "total": 3089,
  "page": 1,
  "pages": 309
}
```

**Step 3: Search**
```bash
curl -u admin:admin \
  'http://localhost/apps/opencatalogi/api/search?query=gemeente'
```

**Step 4: Get Single Publication**
```bash
curl -u admin:admin \
  'http://localhost/apps/opencatalogi/api/software-catalog/{uuid}'
```

### Unit Tests
```php
public function testPublicationsControllerWithOpenRegisterSource(): void
{
    $catalog = $this->createCatalog([
        'slug' => 'test-catalog',
        'source' => [
            'type' => 'openregister',
            'register' => 'voorzieningen',
            'schemas' => ['organisatie']
        ]
    ]);
    
    $response = $this->controller->index('test-catalog', 1, 10);
    
    $this->assertEquals(200, $response->getStatus());
    $data = $response->getData();
    $this->assertArrayHasKey('results', $data);
    $this->assertGreaterThan(0, $data['total']);
}
```

### Integration Tests
1. Create catalog via API
2. Verify catalog appears in catalog list
3. Query publications endpoint
4. Verify data matches OpenRegister objects
5. Test pagination
6. Test filtering
7. Test search across multiple schemas

## 📚 References

### Related Files
- `opencatalogi/lib/Controller/PublicationsController.php` - Main endpoint
- `opencatalogi/lib/Controller/CatalogiController.php` - Catalog management
- `opencatalogi/lib/Controller/SearchController.php` - Global search
- `opencatalogi/appinfo/routes.php` - Route definitions

### Related Documentation
- `opencatalogi/website/docs/schema/Catalogue.json` - Catalog schema
- OpenCatalogi API documentation
- OpenRegister API documentation

### Related Issues
- Issue #001: Magic Mapper Performance Optimization
- Issue #003: CSV Object Reference Import (blocking for full integration)

### External Resources
- OpenCatalogi documentation: https://documentatie.opencatalogi.nl/
- DCAT-AP standard (if relevant for publication format)

## 📅 Status Updates

### 2026-01-05 - Issue Created
- Initial research completed during integration attempt
- Discovered OpenCatalogi architecture and requirements
- Documented three solution approaches
- Recommended: Direct OpenRegister integration (Option A)

### Current Blockers
- Issue #003: CSV import for complex schemas needed for full multi-schema testing
- OpenCatalogi documentation incomplete for custom sources

### Next Steps
1. Complete Phase 1: Research OpenCatalogi internals
2. Design catalog configuration format
3. Implement basic OpenRegister source adapter
4. Test with organisatie schema (already has data)

## 💬 Discussion

### Why Option A is Recommended

**Simplicity:**
- Uses existing, working OpenRegister API
- No duplicate data storage
- Clear separation of concerns

**Maintainability:**
- OpenCatalogi as thin presentation layer
- All business logic stays in OpenRegister
- Easy to update/extend

**Performance:**
- Direct database access via UnifiedObjectMapper
- Magic Mapper provides optimized queries
- Can leverage PostgreSQL's native search features

**Flexibility:**
- Easy to add new schemas to catalog
- Can filter/transform data at presentation layer
- Supports both magic tables and blob storage

### Alternative Considerations

**Option B (OpenRegister Client):**
- Pros: Very tight integration, unified storage
- Cons: Too much coupling, harder to maintain separately
- Decision: Rejected - violates separation of concerns

**Option C (Elasticsearch):**
- Pros: Excellent search performance, advanced features
- Cons: Complex setup, synchronization overhead, infrastructure requirements
- Decision: Future enhancement after basic integration works

### Open Questions

1. **Publication Format:**
   - Should we conform to standard like DCAT-AP?
   - Or use custom format based on OpenRegister schemas?
   - **Decision needed:** Check OpenCatalogi requirements

2. **Catalog Storage:**
   - Store in OpenRegister objects table?
   - Or OpenCatalogi's own storage?
   - **Lean toward:** OpenRegister for consistency

3. **Multi-tenant:**
   - Should catalogs be organisation-scoped?
   - Or global across Nextcloud instance?
   - **Lean toward:** Support both via configuration

4. **Caching:**
   - Cache publication listings?
   - How to invalidate on data changes?
   - **Future enhancement:** Add once performance becomes issue

### Success Criteria

Implementation is successful when:
1. ✅ Catalog can be created via API
2. ✅ Publications endpoint returns magic table data
3. ✅ Search works across multiple schemas
4. ✅ Pagination works correctly
5. ✅ Performance acceptable (< 100ms for 10 results)
6. ✅ Data stays in sync with OpenRegister

---

**Last Updated:** 2026-01-05

