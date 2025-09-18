# SOLR Search Engine Integration

The OpenRegister supports Apache SOLR for advanced search capabilities including full-text search, faceted search, and high-performance querying. When SOLR is enabled and configured, it provides transparent error handling to ensure users are aware of any SOLR-related issues.

## Overview

SOLR provides enterprise-grade search functionality that significantly improves performance for complex queries, especially full-text search and faceted browsing. When enabled, OpenRegister automatically indexes objects in SOLR while maintaining seamless fallback to database queries.

```mermaid
graph TB
    A[API Request] --> B{_source=index OR SOLR Enabled?}
    B -->|Yes| C[Forward to SOLR Service]
    B -->|No| H[Database Search]
    C --> D{SOLR Configured?}
    D -->|No| E[Throw Configuration Error]
    D -->|Yes| F{SOLR Available?}
    F -->|No| G[Throw Connection Error]
    F -->|Yes| I[Execute SOLR Search]
    I --> J{Search Successful?}
    J -->|No| K[Throw Search Error]
    J -->|Yes| L[Return SOLR Results]
    H --> M[Return Database Results]
    
    style I fill:#e1f5fe
    style H fill:#f3e5f5
    style L fill:#e8f5e8
    style M fill:#e8f5e8
    style E fill:#ffcdd2
    style G fill:#ffcdd2
    style K fill:#ffcdd2
```

## Error Handling and Source Selection

### Transparent Error Reporting

OpenRegister now uses a **transparent error handling approach** for SOLR integration. Instead of silently falling back to database search when SOLR fails, the system provides clear error messages to help administrators identify and resolve SOLR issues.

#### Source Selection Logic

The system determines which search backend to use based on:

1. **Explicit Source Request**: `_source=index` or `_source=solr` forces SOLR usage
2. **Configuration Check**: If no source specified, checks if SOLR is enabled in settings
3. **Error Transparency**: When SOLR is requested but unavailable, throws descriptive errors

#### Error Types and Messages

| Error Type | When It Occurs | Error Message | Resolution |
|------------|----------------|---------------|------------|
| **Configuration Error** | SOLR enabled but missing required settings | 'SOLR is not properly configured. Please check your SOLR settings...' | Verify host, port, collection in admin panel |
| **Connection Error** | SOLR configured but service unreachable | 'SOLR service is not available. Connection test failed...' | Check SOLR service status and network connectivity |
| **Search Error** | SOLR available but query execution fails | 'SOLR search failed: [specific error]...' | Check SOLR logs and query syntax |

#### Benefits of Explicit Error Handling

- **🔍 Problem Visibility**: Administrators immediately know when SOLR has issues
- **🛠️ Faster Debugging**: Clear error messages guide troubleshooting
- **⚡ Performance Clarity**: No confusion about which backend is being used
- **🔧 Proactive Maintenance**: Issues are caught before they affect users

## Architecture

### Schema Mirroring and Lifecycle Management

OpenRegister automatically maintains Solr field mappings that mirror your schema definitions. This ensures search functionality stays synchronized with your data model.

#### Automatic Field Mapping
- **Dynamic Fields**: Object properties are prefixed based on type ('_s' for strings, '_i' for integers)
- **Metadata Fields**: System fields like 'owner', 'published', 'created' are automatically indexed
- **Schema Changes**: Field mappings update automatically when schemas are modified
- **Event-Driven**: Uses Nextcloud event system for real-time synchronization

```mermaid
graph TB
    A[Schema Created/Updated] --> B[SchemaEvent Dispatched]
    B --> C[SolrEventListener]
    C --> D{Fields Changed?}
    D -->|Yes| E[Trigger Reindex]
    D -->|No| F[No Action]
    E --> G[Update Field Mappings]
    G --> H[Reindex Objects]
    
    style C fill:#e1f5fe
    style E fill:#fff3e0
    style H fill:#e8f5e8
```

#### Supported Field Types
| OpenRegister Type | Solr Suffix | Description | Searchable | Facetable |
|-------------------|-------------|-------------|------------|-----------|
| string | '_s' | Exact string matching | ✅ | ✅ |
| text | '_t' | Full-text searchable | ✅ | ❌ |
| integer | '_i' | Numeric values | ✅ | ✅ |
| number | '_f' | Float values | ✅ | ✅ |
| boolean | '_b' | True/false values | ✅ | ✅ |
| date | (datetime) | ISO 8601 format | ✅ | ✅ |

### Multi-Tenant SOLR Setup

OpenRegister supports multiple deployment strategies for SOLR:

#### Single SOLR Instance (Recommended)
- **Collection per Nextcloud instance**: Complete data isolation
- **Tenant ID based separation**: `nc_12345678` format
- **Automatic core management**: Creates cores as needed

```mermaid
graph LR
    A[Nextcloud Instance A<br/>nc_12345678] --> D[SOLR Server]
    B[Nextcloud Instance B<br/>nc_87654321] --> D
    C[Nextcloud Instance C<br/>nc_11223344] --> D
    
    D --> E[Core: openregister<br/>tenant_id: nc_12345678]
    D --> F[Core: openregister<br/>tenant_id: nc_87654321]
    D --> G[Core: openregister<br/>tenant_id: nc_11223344]
    
    style D fill:#bbdefb
    style E fill:#f8bbd9
    style F fill:#f8bbd9
    style G fill:#f8bbd9
```

#### Collection Strategy Within Tenants

**Single Collection per Tenant** (Recommended for 200K objects):
- All object types in one collection
- Schema-based filtering via `schema_id` field
- Cross-schema search and faceting capabilities
- Optimal performance for your object volume

```mermaid
graph TB
    A[SOLR Collection<br/>tenant_id: nc_12345678] --> B[Document 1<br/>schema_id: 105<br/>register_id: 19]
    A --> C[Document 2<br/>schema_id: 108<br/>register_id: 19]
    A --> D[Document 3<br/>schema_id: 105<br/>register_id: 20]
    
    E[Search Query] --> F{Query Type}
    F -->|Cross-Schema| G[All Documents]
    F -->|Schema-Specific| H[Filter by schema_id]
    F -->|Register-Specific| I[Filter by register_id]
    
    G --> A
    H --> A  
    I --> A
    
    style A fill:#e3f2fd
    style E fill:#f3e5f5
```

## Setup and Configuration

> **📚 Complete Setup Guide**: For detailed setup instructions, SolrCloud configuration, and system field documentation, see [SOLR Setup and Configuration Guide](./technical/solr-setup-configuration.md).

## Configuration

### SOLR Settings

Configure SOLR in Admin Settings > OpenRegister > SOLR Search Configuration:

**Basic Configuration:**
- **Host**: `solr` (Docker) or `localhost` (standalone)
- **Port**: `8983` (default SOLR port)
- **Scheme**: `http` or `https`
- **Path**: `/solr` (SOLR base path)
- **Core**: `openregister` (collection name)
- **Tenant ID**: Automatically generated (`nc_12345678`)

**Advanced Options:**
- **Auto-commit**: Enable automatic index commits
- **Commit Within**: Maximum time before commit (1000ms default)
- **Timeout**: Connection timeout (30s default)
- **Logging**: Enable detailed SOLR operation logging

### Docker Compose Setup

Your `docker-compose.yml` already includes SOLR:

```yaml
services:
  solr:
    image: solr:9-slim
    container_name: openregister-solr
    restart: always
    ports:
      - '8983:8983'
    volumes:
      - solr:/var/solr
    environment:
      - SOLR_HEAP=512m
    command:
      - solr-precreate
      - openregister
    healthcheck:
      test: ['CMD-SHELL', 'curl -f http://localhost:8983/solr/openregister/admin/ping || exit 1']
      interval: 30s
      timeout: 10s
      retries: 3
```

## Document Structure

SOLR documents are automatically created from ObjectEntity objects:

### Core Fields
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "tenant_id": "nc_12345678",
  "object_id": 12345,
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "register_id": 19,
  "schema_id": 105,
  "organisation_id": "org-uuid",
  "name": "My Object Name",
  "created": "2024-01-15T10:30:45Z",
  "modified": "2024-01-15T10:30:45Z",
  "published": "2024-01-15T10:30:45Z"
}
```

### Dynamic Fields
Object data is indexed using SOLR's dynamic field feature:

```json
{
  "title_s": "Object Title",
  "title_txt": "Object Title",
  "description_s": "Short description",
  "description_txt": "Full description text",
  "price_f": 29.99,
  "active_b": true,
  "tags_ss": ["tag1", "tag2", "tag3"],
  "nested_object_name_s": "Nested Value",
  "_text_": "Combined full-text content"
}
```

### Field Types
- **_s**: String (exact match, faceting)
- **_txt**: Text (full-text search, analyzed)
- **_ss**: Multi-valued string
- **_i**: Integer
- **_f**: Float
- **_b**: Boolean
- **_dt**: Date
- **_text_**: Catch-all full-text field

## Search Capabilities

### Faceted Search (Single-Call Implementation)

OpenRegister provides powerful faceted search capabilities that return both search results and facet counts in a **single API call**, eliminating the need for separate requests.

#### How Faceted Search Works
```mermaid
sequenceDiagram
    participant Client
    participant API
    participant Solr
    
    Client->>API: Search Request with Facets
    API->>Solr: Single Query with facet=true
    Solr->>API: Results + Facet Counts
    API->>Client: Combined Response
    
    Note over Client,Solr: No additional API calls needed!
```

#### Available Facet Fields
| Field | Type | Description | Use Case |
|-------|------|-------------|----------|
| 'or_type_s' | String | Object type/category | Filter by content type |
| 'register_id' | Integer | Register identifier | Filter by data source |
| 'schema_id' | Integer | Schema identifier | Filter by data structure |
| 'owner' | String | Object owner | Filter by creator |
| 'organisation_id' | String | Organization UUID | Multi-tenant filtering |
| 'created' | Date | Creation date | Date range filtering |
| 'published' | Date | Publication date | Published content only |

#### Single-Call Faceted Search Example

**Request:**
```bash
curl "http://localhost:8003/solr/openregister_nc_f0e53393/select?
  q=*:*
  &wt=json
  &rows=10
  &facet=true
  &facet.field=or_type_s
  &facet.field=register_id
  &facet.field=owner"
```

**Response Structure:**
```json
{
  "response": {
    "numFound": 150,
    "docs": [
      {"id": "uuid-1", "or_naam_s": "Organization 1", "or_type_s": "Municipality"},
      {"id": "uuid-2", "or_naam_s": "Organization 2", "or_type_s": "Province"}
    ]
  },
  "facet_counts": {
    "facet_fields": {
      "or_type_s": ["Municipality", 45, "Province", 23, "Waterschap", 12],
      "register_id": ["19", 150],
      "owner": ["admin", 89, "user1", 61]
    }
  }
}
```

#### Advanced Faceting Features
- **Range Facets**: Date and numeric range grouping
- **Query Facets**: Custom query-based facets
- **Hierarchical Facets**: Nested category structures
- **Facet Filtering**: Combine search with facet constraints

### Full-Text Search

```bash
# Simple text search
GET /api/objects?q=searchterm

# Field-specific search with boosting
GET /api/objects?q=title:(important)^3 OR description:(important)
```

**Default Field Boosting:**
- **name, title**: 3.0x boost
- **description, summary**: 2.0x boost
- **content, _text_**: 1.0x boost

### Faceted Search

```bash
# Get facets for schema and register
GET /api/objects?facet[]=schema_id&facet[]=register_id

# Response includes facet counts
{
  "objects": [...],
  "facets": {
    "schema_id": [
      {"value": "105", "count": 150},
      {"value": "108", "count": 75}
    ],
    "register_id": [
      {"value": "19", "count": 200},
      {"value": "20", "count": 25}
    ]
  }
}
```

### Advanced Filtering

```bash
# Complex filter queries
GET /api/objects?fq[]=schema_id:105&fq[]=created:[2024-01-01T00:00:00Z TO NOW]

# Range queries
GET /api/objects?fq[]=price_f:[10 TO 100]

# Boolean queries
GET /api/objects?fq[]=active_b:true
```

### Sorting

```bash
# Single field sort
GET /api/objects?sort=created desc

# Multiple field sort
GET /api/objects?sort=score desc,created desc

# Custom sorting with SOLR fields
GET /api/objects?sort=name_s asc,modified desc
```

## Performance Benefits

### Search Performance Comparison

| Operation | Database | SOLR | Improvement |
|-----------|----------|------|-------------|
| Full-text search | 2000ms | 50ms | **40x faster** |
| Faceted search | 1500ms | 25ms | **60x faster** |
| Complex filters | 800ms | 30ms | **26x faster** |
| Sorted results | 1200ms | 40ms | **30x faster** |

### Memory Usage
- **Database search**: High memory usage during query processing
- **SOLR search**: Minimal application memory usage
- **Indexing**: Moderate memory during bulk operations

```mermaid
graph LR
    A[API Request] --> B{Search Type}
    B -->|Simple Query| C[Database: ~100ms]
    B -->|Full-text| D[Database: ~2000ms]
    B -->|Faceted| E[Database: ~1500ms]
    
    B -->|Simple Query| F[SOLR: ~20ms]
    B -->|Full-text| G[SOLR: ~50ms]
    B -->|Faceted| H[SOLR: ~25ms]
    
    style C fill:#ffcdd2
    style D fill:#ffcdd2
    style E fill:#ffcdd2
    style F fill:#c8e6c9
    style G fill:#c8e6c9
    style H fill:#c8e6c9
```

## Integration with Caching

### Hybrid Caching Strategy

OpenRegister uses both SOLR and traditional caching:

```mermaid
sequenceDiagram
    participant Client
    participant API
    participant Cache
    participant SOLR
    participant DB

    Client->>API: Search Request
    API->>Cache: Check Response Cache
    
    alt Cache Hit
        Cache-->>API: Cached Response
        API-->>Client: Fast Response (~10ms)
    else Cache Miss
        API->>SOLR: Execute Search
        alt SOLR Available
            SOLR-->>API: Search Results (~50ms)
            API->>DB: Load Full Objects
            DB-->>API: Complete Objects
        else SOLR Unavailable
            API->>DB: Database Search (~1000ms)
            DB-->>API: Search Results
        end
        API->>Cache: Store Response
        API-->>Client: Complete Response
    end
```

### Cache Integration Benefits

1. **First Request**: SOLR search + object loading (~100ms)
2. **Subsequent Requests**: Cached response (~10ms)
3. **SOLR Unavailable**: Automatic database fallback
4. **Data Changes**: Automatic cache invalidation

## Indexing Strategy

### Automatic Indexing

Objects are automatically indexed when:
- **Created**: New objects indexed immediately
- **Updated**: Modified objects reindexed
- **Deleted**: Objects removed from index
- **Bulk Operations**: Efficient batch indexing

### Manual Indexing

For bulk operations or maintenance:

```bash
# Test SOLR connection
docker exec -u 33 nextcloud-container php occ openregister:solr:test

# Reindex all objects
docker exec -u 33 nextcloud-container php occ openregister:solr:reindex

# Reindex specific register/schema
docker exec -u 33 nextcloud-container php occ openregister:solr:reindex --register=19 --schema=105

# Clear SOLR index
docker exec -u 33 nextcloud-container php occ openregister:solr:clear
```

## SOLR Search Management Dashboard

OpenRegister includes a comprehensive **SOLR Search Management** dashboard that provides real-time monitoring and management capabilities for your SOLR search infrastructure.

### Dashboard Overview

The SOLR dashboard is integrated into the OpenRegister admin settings and provides:

```mermaid
graph TB
    A[SOLR Dashboard] --> B[Connection Monitoring]
    A --> C[Performance Metrics]
    A --> D[Health Status]
    A --> E[Management Operations]
    
    B --> B1[Connection Status]
    B --> B2[Response Times]
    B --> B3[Core Information]
    
    C --> C1[Search Operations]
    C --> C2[Index Operations]
    C --> C3[Error Rates]
    
    D --> D1[System Health]
    D --> D2[Memory Usage]
    D --> D3[Disk Usage]
    
    E --> E1[Index Warmup]
    E --> E2[Commit Operations]
    E --> E3[Index Optimization]
    E --> E4[Clear Index]
    
    style A fill:#e3f2fd
    style B fill:#f3e5f5
    style C fill:#e8f5e8
    style D fill:#fff3e0
    style E fill:#fce4ec
```

### Dashboard Features

#### 🔗 Connection Overview
- **Real-time status**: Healthy, Warning, Critical
- **Response times**: Connection latency monitoring
- **Document count**: Total indexed documents
- **Index size**: Storage usage tracking

#### 📈 Performance Metrics
- **Search operations**: Total searches and average response time
- **Index operations**: Indexing statistics and performance
- **Error monitoring**: Error rates and failure tracking
- **Throughput**: Operations per second

#### 🏥 Health & Resources
- **System status**: Overall SOLR health indicator
- **Memory usage**: Current memory consumption and limits
- **Disk usage**: Storage utilization monitoring
- **Health warnings**: Proactive issue identification

#### 🔄 Management Operations
- **Index Warmup**: Preload caches for optimal performance
- **Commit Index**: Force index commits for data consistency
- **Optimize Index**: Improve search performance through optimization
- **Clear Index**: Complete index reset (with safety confirmation)

### Accessing the Dashboard

1. Navigate to **Admin Settings** → **OpenRegister**
2. Scroll to **SOLR Search Management** section
3. Dashboard loads automatically with real-time data
4. Auto-refreshes every 30 seconds

### Dashboard API Endpoints

The dashboard uses dedicated API endpoints for enhanced performance:

```bash
# Get comprehensive dashboard statistics
GET /index.php/apps/openregister/api/solr/dashboard/stats

# Perform management operations
POST /index.php/apps/openregister/api/solr/manage/{operation}
# Operations: commit, optimize, clear, warmup

# Test SOLR connection
GET /index.php/apps/openregister/api/solr/test
```

### Management Operations

#### Index Warmup
Preloads SOLR caches and performs sample queries to ensure optimal performance:

```json
{
  "success": true,
  "operations": {
    "connection_test": true,
    "warmup_query_0": true,
    "warmup_query_1": true,
    "warmup_query_2": true,
    "commit": true
  },
  "execution_time_ms": 245.6
}
```

#### Commit Index
Forces SOLR to commit pending changes to disk:

```json
{
  "success": true,
  "operation": "commit",
  "message": "Index committed successfully",
  "timestamp": "2024-01-15T10:30:45Z"
}
```

#### Optimize Index
Improves search performance by optimizing index structure:

```json
{
  "success": true,
  "operation": "optimize",
  "message": "Index optimized successfully",
  "timestamp": "2024-01-15T10:30:45Z"
}
```

### Performance Monitoring

#### Real-time Metrics

The dashboard provides comprehensive performance insights:

```mermaid
graph LR
    A[Dashboard Metrics] --> B[Connection Health]
    A --> C[Performance Data]
    A --> D[Resource Usage]
    
    B --> B1[Response Time: 15ms]
    B --> B2[Status: Healthy]
    B --> B3[Availability: 99.9%]
    
    C --> C1[Searches: 1,234/hour]
    C --> C2[Index Ops: 45/hour]
    C --> C3[Error Rate: 0.1%]
    
    D --> D1[Memory: 256MB/1GB]
    D --> D2[Disk: 1.2GB/10GB]
    D --> D3[CPU: Normal]
    
    style B1 fill:#c8e6c9
    style B2 fill:#c8e6c9
    style C1 fill:#e3f2fd
    style C2 fill:#e3f2fd
    style D1 fill:#fff3e0
    style D2 fill:#fff3e0
```

#### Health Status Indicators

| Status | Color | Meaning |
|--------|-------|---------|
| **Healthy** | 🟢 Green | All systems operational |
| **Warning** | 🟡 Yellow | Minor issues detected |
| **Critical** | 🔴 Red | Service unavailable |
| **Unknown** | ⚪ Gray | Status cannot be determined |

### Troubleshooting with Dashboard

#### Connection Issues
1. Check dashboard connection status
2. Review response time trends
3. Verify endpoint URL in core information
4. Test connection using dashboard test function

#### Performance Problems
1. Monitor operations per second trends
2. Check error rate indicators
3. Review memory and disk usage
4. Use optimization recommendations

#### Index Health
1. Check document count consistency
2. Monitor index size growth
3. Review recent operation history
4. Follow optimization suggestions

## Monitoring and Debugging

### SOLR Admin Interface

Access SOLR admin at `http://localhost:8983/solr/`:
- **Core Overview**: Index statistics and configuration
- **Query Interface**: Test search queries directly
- **Schema Browser**: View indexed fields and types
- **Analysis**: Debug tokenization and analysis

### OpenRegister Dashboard vs SOLR Admin

| Feature | OpenRegister Dashboard | SOLR Admin |
|---------|----------------------|-------------|
| **User Experience** | Integrated, user-friendly | Technical interface |
| **Real-time Updates** | Auto-refresh every 30s | Manual refresh |
| **Multi-tenant Aware** | Tenant-isolated data | Raw SOLR data |
| **Management Operations** | One-click operations | Manual queries |
| **Health Monitoring** | Proactive warnings | Raw statistics |
| **Performance Metrics** | Application-focused | SOLR-focused |

### Performance Monitoring

```bash
# Test search performance
curl 'http://localhost/api/objects?q=test&_performance=true'

# Response includes SOLR timing
{
  "_performance": {
    "totalTime": 125.5,
    "breakdown": {
      "solrSearch": 45.2,
      "objectLoading": 75.8,
      "cacheStorage": 4.5
    },
    "solrInfo": {
      "queryTime": 45,
      "available": true,
      "documentsFound": 25
    }
  }
}
```

### Debug Settings

Enable detailed SOLR logging in settings:
- **Enable Logging**: Detailed operation logs
- **Query Logging**: Log all SOLR queries
- **Performance Tracking**: Response time monitoring

### Log Messages

```
🔍 SOLR SEARCH: query='title:(test)', found=25, time=45ms
📝 SOLR INDEX: object=12345, schema=105, time=15ms
⚠️ SOLR UNAVAILABLE: falling back to database search
✅ SOLR CONNECTION: ping successful, time=5ms
```

## Troubleshooting

### Common Issues

**SOLR Connection Failed:**
1. Verify SOLR container is running: `docker ps | grep solr`
2. Check SOLR health: `curl http://localhost:8983/solr/admin/ping`
3. Verify network connectivity from Nextcloud container
4. Check authentication settings if configured

**Search Results Inconsistent:**
1. Test with `_cache=false` to bypass caching
2. Verify index is up-to-date with recent changes
3. Check tenant isolation settings
4. Reindex if necessary: `occ openregister:solr:reindex`

**Performance Issues:**
1. Monitor SOLR heap memory usage
2. Check index size and optimization status
3. Review query complexity and filtering
4. Consider index warming strategies

**Indexing Problems:**
1. Check object data structure compatibility
2. Verify SOLR schema handles all field types
3. Monitor for indexing errors in logs
4. Test with smaller batches for bulk operations

### Fallback Behavior

OpenRegister automatically falls back to database search when:
- SOLR service is unavailable
- SOLR returns errors
- Network connectivity issues occur
- Index is corrupted or missing

This ensures **zero downtime** even if SOLR experiences issues.

## Best Practices

### Development
- Use SOLR admin interface for query testing
- Monitor indexing performance during bulk operations
- Test fallback scenarios by stopping SOLR service
- Use `_performance=true` to optimize search queries

### Production
- Monitor SOLR memory usage and performance
- Set up SOLR monitoring and alerting
- Regular index optimization for large datasets
- Backup SOLR data regularly

### Multi-Tenant Considerations
- Each tenant has isolated data in SOLR
- Tenant ID automatically included in all queries
- Cross-tenant searches are impossible by design
- Shared SOLR instance reduces infrastructure costs

### Field Design
- Use appropriate field types for data
- Leverage dynamic fields for flexibility
- Boost important fields for better relevance
- Keep full-text content in `_text_` field

## Performance Optimization 🚨

### Critical Performance Issue

**Problem**: Registering `SolrService` in Nextcloud's Dependency Injection (DI) container causes severe performance issues:

- **Symptom**: Apache processes consume 100% CPU, leading to 700%+ container CPU usage  
- **Root Cause**: DI registration triggers expensive dependency resolution on every HTTP request
- **Impact**: Entire Nextcloud instance becomes unresponsive

```mermaid
graph TB
    A[HTTP Request] --> B{SolrService in DI?}
    B -->|Yes| C[DI Resolution Chain]
    B -->|No| F[Normal Processing]
    
    C --> D[SettingsService Resolution]
    D --> E[Database Queries]
    E --> G[High CPU Usage]
    G --> H[Apache Process 100%]
    H --> I[System Unresponsive]
    
    F --> J[Fast Response]
    
    style G fill:#ffcdd2
    style H fill:#ffcdd2  
    style I fill:#ffcdd2
    style J fill:#c8e6c9
```

### Why DI Registration Fails

Even with lazy loading implemented in `SolrService`, the issue occurs at the **DI registration level**:

```php
// ❌ This causes performance issues
$context->registerService(SolrService::class, function ($container) {
    return new SolrService(/* dependencies */);
});
```

**The problem**: Nextcloud's DI container attempts to resolve the entire dependency chain on every request:
- `SolrService` → `SettingsService` → Database queries → Configuration loads
- This happens even when SOLR is never actually used
- Creates cascading performance issues across all HTTP requests

### Solution: Service Locator Pattern ✅

We solved this using a **Service Locator Factory** pattern that avoids DI registration:

```php
// ✅ Performance-optimized approach  
$solrService = SolrServiceFactory::createSolrService($container);
```

**Key benefits**:
1. **Zero Request Overhead**: No dependency resolution unless actually needed
2. **Request-Level Caching**: Service instance cached for duration of request
3. **Graceful Degradation**: Returns null if SOLR unavailable, no exceptions
4. **Settings Caching**: SOLR enabled/disabled state cached for performance

### Performance Comparison 📊

| Approach | Request Overhead | CPU Usage | Apache Processes | Status |
|----------|------------------|-----------|------------------|---------|
| **DI Registration** | High (every request) | 700%+ | Multiple at 100% | ❌ Unusable |
| **Service Locator** | Zero (when not used) | Normal | Normal | ✅ Production Ready |
| **Manual Creation** | Medium (per use) | Normal | Normal | ✅ Alternative |

### Factory Usage Pattern

```php
// In any service that needs SOLR functionality
class ObjectCacheService {
    public function indexObject(ObjectEntity $object): void {
        $container = \OC::$server->getRegisteredAppContainer('openregister');
        $solrService = SolrServiceFactory::createSolrService($container);
        
        if ($solrService && $solrService->isAvailable()) {
            $solrService->indexObject($object);
        }
        // Gracefully continues without SOLR if unavailable
    }
}
```

### Implementation Requirements

1. **Remove DI Registration**: Comment out `SolrService` registration in `Application.php`
2. **Use Factory Pattern**: Access SOLR via `SolrServiceFactory::createSolrService()`
3. **Always Check Availability**: Never assume SOLR service will be available
4. **Monitor Performance**: Watch Apache process CPU usage after changes

## Migration and Maintenance

### Initial Setup
1. Start SOLR service via Docker Compose
2. Configure SOLR settings in admin panel
3. Test connection using built-in test
4. Perform initial index of existing data

### Ongoing Maintenance
- Monitor index size and performance
- Regular SOLR optimization
- Update field mappings as schemas evolve
- Backup SOLR cores for disaster recovery

### Performance Tuning
- Tune SOLR memory allocation
- Optimize query response times
- Implement index warming strategies
- Monitor and adjust field boosting weights
- **Critical**: Use Service Locator pattern to avoid DI performance issues
