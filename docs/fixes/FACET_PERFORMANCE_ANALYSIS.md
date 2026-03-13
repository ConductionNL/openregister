# Facet Performance Analysis & Optimization Plan

## 🔍 Performance Issues Identified

Your faceting system currently takes **7+ seconds** to respond. Here's why:

### 1. **Missing Database Indexes (Critical)**

Current `openregister_objects` table only has:
- Primary key on `id`
- Index on `uuid`, `register`, `schema`, and a composite `slug+register+schema`

**Missing crucial indexes:**
- ❌ No index on `deleted` (used in EVERY query)
- ❌ No index on `published` (used for lifecycle filtering)
- ❌ No index on `created`, `updated` (common facet fields)
- ❌ No index on `organisation`, `owner` (common facet fields)
- ❌ No composite indexes for filter combinations

### 2. **JSON Field Performance (Major)**

Your JSON facet queries look like:
```sql
SELECT JSON_UNQUOTE(JSON_EXTRACT(object, '$.cloudDienstverleningsmodel')), COUNT(*)
FROM openregister_objects 
WHERE JSON_EXTRACT(object, '$.cloudDienstverleningsmodel') IS NOT NULL
GROUP BY JSON_UNQUOTE(JSON_EXTRACT(object, '$.cloudDienstverleningsmodel'))
```

**Problems:**
- ❌ Full table scan of 82,970+ rows per JSON facet
- ❌ No indexes can optimize JSON_EXTRACT operations
- ❌ 12 separate queries = 12 full table scans

### 3. **Sequential Query Execution (Major)**

Each of your 12 facets runs a separate database query:
- Register facet: 1 query
- Schema facet: 1 query  
- Organisation facet: 1 query
- cloudDienstverleningsmodel: 1 query
- etc...

**Total: 12+ separate database queries, many doing full table scans**

## 🚀 Optimization Solutions

### Phase 1: Immediate Indexes (Run Migration)

```bash
# Apply the new migration to add critical indexes
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ migrations:execute openregister 20250828120000
```

**Expected improvement: 7 seconds → 2-3 seconds**

### Phase 2: Query Optimization (Code Changes)

Replace current facet handlers with optimized versions:

1. **Metadata Facets** (register, schema, organisation): Use indexed columns
2. **JSON Field Facets**: Skip or limit for large datasets
3. **Query Batching**: Combine multiple facets where possible

**Expected improvement: 2-3 seconds → 0.5-1 second**

### Phase 3: Advanced Optimizations (Future)

1. **Virtual Columns** for common JSON fields
2. **Facet Result Caching** (Redis/Memcached)
3. **Async Facet Loading** (load critical facets first)

## 📊 Performance Targets

| Optimization Phase | Current | Target | Improvement |
|-------------------|---------|--------|-------------|
| **Baseline** | 7+ seconds | - | - |
| **+ Indexes** | 7 seconds | 2-3 seconds | 60-70% |
| **+ Query Optimization** | 2-3 seconds | 0.5-1 second | 80-90% |
| **+ Caching** | 0.5-1 second | 0.1-0.3 seconds | 95%+ |

## 🛠️ Implementation Steps

### Step 1: Apply Database Indexes (Immediate)

```bash
# Run the migration
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ migrations:execute openregister 20250828120000
```

### Step 2: Test Performance Improvement

```bash
# Test your original query
time curl -u 'admin:admin' 'http://localhost:3000/api/apps/opencatalogi/api/publications?_limit=20&_facetable=true&_facets%5B%40self%5D%5Bregister%5D=terms&_facets%5B%40self%5D%5Bschema%5D=terms&_facets%5B%40self%5D%5Borganisation%5D=terms'
```

### Step 3: Code Integration (Next Phase)

Integrate the `OptimizedFacetHandler` into your existing system:

```php
// In ObjectEntityMapper.php
use OCA\OpenRegister\Db\ObjectHandlers\OptimizedFacetHandler;

public function getSimpleFacets(array $query = []): array
{
    // Use optimized handler for better performance
    $optimizedHandler = new OptimizedFacetHandler($this->db);
    return $optimizedHandler->getBatchedFacets($query['_facets'] ?? [], $query);
}
```

## ⚡ Quick Wins Available Now

### Option 1: Reduce Facet Scope
```bash
# Instead of 12 facets, test with just critical ones:
_facets%5B%40self%5D%5Bregister%5D=terms&_facets%5B%40self%5D%5Bschema%5D=terms
```

### Option 2: Remove Field Discovery
```bash
# Remove _facetable=true to save ~15ms:
# OLD: ?_facetable=true&_facets[...]
# NEW: ?_facets[...]
```

### Option 3: Limit Results
```bash
# Use _limit=0 for facet-only queries:
?_limit=0&_facets%5B%40self%5D%5Bregister%5D=terms
```

## 🎯 Expected Results

After applying the database migration:
- **Register/Schema/Organisation facets**: 50-100ms each (from 500ms+)
- **JSON field facets**: Still slow but with limits/skipping for large datasets
- **Overall response**: 2-3 seconds (from 7+ seconds)

The biggest performance gain will come from properly indexing the `deleted`, `published`, and other lifecycle columns that are used in every base query.
