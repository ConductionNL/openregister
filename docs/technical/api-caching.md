# API Caching System

The OpenRegister API includes an advanced multi-layer caching system for optimal performance.

## Overview

The caching system automatically stores API responses to improve performance for subsequent identical requests. This is especially important for complex queries with relationships (`_extend` parameter).

## Cache Parameters

### `_cache=false`

Bypass caching for testing and debugging purposes.

### `_performance=true`

Include detailed performance metrics in API responses for analysis and optimization.

**Usage:**
```
GET /api/objects/registers/schemas?_cache=false
GET /api/objects/voorzieningen/product?_limit=10&_extend[]=@self.schema&_cache=false
GET /api/objects/voorzieningen/product?_limit=10&_extend[]=@self.schema&_performance=true
GET /api/objects/voorzieningen/product?_limit=10&_extend[]=@self.schema&_cache=false&_performance=true
```

**When to use `_cache=false`:**
- **Testing**: To ensure you get fresh data during development
- **Debugging**: To verify performance improvements 
- **Data validation**: To confirm cache consistency
- **Performance benchmarking**: To measure true database performance

**When to use `_performance=true`:**
- **Performance Analysis**: To identify bottlenecks in API responses
- **Optimization**: To understand where time is spent during request processing
- **Monitoring**: To track performance improvements over time
- **Debugging**: To troubleshoot slow API responses

**Example:**
```bash
# With cache (fast, may return cached data)
curl 'http://localhost/api/objects/products?_limit=10'

# Without cache (slower, always fresh data)
curl 'http://localhost/api/objects/products?_limit=10&_cache=false'

# With performance metrics (includes detailed timing breakdown)
curl 'http://localhost/api/objects/products?_limit=10&_performance=true'

# Performance analysis without cache (true database performance + metrics)
curl 'http://localhost/api/objects/products?_limit=10&_cache=false&_performance=true'
```

## Cache Behavior

### Default Caching
- **Enabled by default** for all API requests
- **8-hour maximum TTL** for office environments
- **Automatic invalidation** when data changes
- **Multi-tenant aware** (separate cache per organization)
- **User-specific caching** for RBAC compliance

### Cache Keys
Cache keys are normalized to ensure consistent caching:
- `objects/19/108` and `objects/voorzieningen/contactpersoon` use the **same cache key**
- Slug and ID URLs are automatically normalized for cache efficiency

### Cache Invalidation
Caches are automatically cleared when:
- Objects are created, updated, or deleted
- Schemas are modified  
- Relationships change
- Maximum TTL (8 hours) is reached

## Performance Impact

### With Cache
- **Response time**: ~100-300ms (typical)
- **Database load**: Minimal
- **Memory usage**: Moderate

### Without Cache (_cache=false)
- **Response time**: ~500ms-2s (depends on complexity)
- **Database load**: Full query execution
- **Memory usage**: Higher during processing

### Performance Examples
```bash
# First request (cache miss): ~1.1s
curl 'api/objects/voorzieningen/module?_extend[]=@self.schema'

# Second request (cache hit): ~150ms  
curl 'api/objects/voorzieningen/module?_extend[]=@self.schema'

# Force fresh data: ~1.1s
curl 'api/objects/voorzieningen/module?_extend[]=@self.schema&_cache=false'
```

## Cache Architecture

### Multiple Cache Layers
1. **Response Cache**: Complete API responses
2. **Object Cache**: Individual object entities  
3. **Schema Cache**: Schema definitions and metadata
4. **Facet Cache**: Facet configurations and results
5. **SOLR Cache**: Search results and object indexes

### Cache Services
- `ObjectCacheService`: Object-specific caching with SOLR integration
- `SchemaCacheHandler`: Schema-specific caching (Handler in Schemas/)
- `FacetCacheHandler`: Facet-specific caching (Handler in Schemas/)
- `SolrService`: Apache SOLR search engine integration

### SOLR Integration

When SOLR is enabled, OpenRegister uses a hybrid caching approach:
- **Search queries** are processed by SOLR (50ms average)
- **Object loading** uses traditional cache layers
- **Automatic fallback** to database when SOLR unavailable

See [SOLR Setup & Configuration](./solr-setup-configuration.md) for detailed information.

## Best Practices

### Development
- Use `_cache=false` during active development
- Test with and without cache to verify consistency
- Monitor logs for cache hit/miss information

### Testing
- Always clear cache before performance tests
- Use `_cache=false` for baseline measurements
- Verify cache invalidation works correctly

### Production
- Let caching happen automatically
- Monitor cache hit rates
- Avoid `_cache=false` in production (performance impact)

## Monitoring

### Log Messages
Cache operations are logged with performance details:
```
🎯 CACHE HIT - Performance optimized response (responseTime: <10ms)
🚫 CACHE BYPASS: _cache=false parameter detected
❌ CACHE MISS - Will compute and cache response
```

### Performance Metrics
- Cache hit/miss rates
- Response times with/without cache
- Memory usage statistics
- TTL effectiveness

## Performance Metrics

When using `_performance=true`, the API response includes a detailed `_performance` object:

### Performance Response Structure
```json
{
  "data": [...],
  "pagination": {...},
  "_performance": {
    "totalTime": 850.5,
    "breakdown": {
      "cacheCheck": 2.3,
      "authorization": 15.7,
      "databaseQuery": 245.8,
      "objectHydration": 125.2,
      "relationshipLoading": 425.1,
      "jsonProcessing": 28.9,
      "facetCalculation": 7.5,
      "cacheStorage": 0.0
    },
    "queryInfo": {
      "totalObjects": 25,
      "totalPages": 3,
      "currentPage": 1,
      "limit": 10,
      "hasExtend": true,
      "extendCount": 2,
      "cacheHit": false,
      "cacheDisabled": true
    },
    "recommendations": [
      {
        "type": "warning",
        "issue": "Slow response time",
        "message": "Total time 850.5ms exceeds 500ms target",
        "suggestions": [
          "Consider enabling caching",
          "Optimize _extend usage",
          "Review database query complexity"
        ]
      }
    ],
    "timestamp": "2024-01-15T10:30:45Z"
  }
}
```

### Performance Breakdown

- **totalTime**: Complete request processing time in milliseconds
- **cacheCheck**: Time spent checking for cached responses
- **authorization**: Time spent on RBAC and permission checks
- **databaseQuery**: Time spent executing database queries
- **objectHydration**: Time spent converting database rows to PHP objects
- **relationshipLoading**: Time spent loading `_extend` relationships
- **jsonProcessing**: Time spent encoding/decoding JSON data
- **facetCalculation**: Time spent calculating facet data
- **cacheStorage**: Time spent storing results in cache

### Performance Recommendations

The system automatically provides optimization suggestions:

- **Critical** (> 2000ms): Immediate action required
- **Warning** (> 500ms): Optimization recommended  
- **Info**: General optimization tips
- **Success** (≤ 500ms): Performance target achieved

### Performance Optimization Guide

**Target Response Times:**
- **Excellent**: < 200ms (cache hit)
- **Good**: < 500ms (cache miss)
- **Acceptable**: < 1000ms (complex queries)
- **Slow**: > 1000ms (needs optimization)

**Common Bottlenecks:**
1. **Database Queries**: Add indexes, optimize WHERE clauses
2. **Relationship Loading**: Reduce `_extend` usage, implement selective loading. Note: Each `_extend` parameter adds approximately 300ms overhead due to additional database queries for related objects and inverse relationships.
3. **JSON Processing**: Truncate large objects, use selective field loading
4. **Authorization**: Cache RBAC decisions, optimize permission checks

## Troubleshooting

### Cache Issues
If you suspect cache problems:
1. Test with `_cache=false` to get fresh data
2. Compare results between cached and uncached requests
3. Check logs for cache invalidation events
4. Verify TTL settings for your use case

### Performance Issues
If responses are slow:
1. Use `_performance=true` to identify bottlenecks
2. Check cache hit rates in logs
3. Verify indexes are properly configured
4. Monitor relationship loading performance
5. Consider reducing `_extend` complexity
6. Review performance recommendations in the response
