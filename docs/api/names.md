# Names API - Ultra-Fast Object Name Resolution

The Names API provides lightning-fast object name lookup functionality for frontend applications, enabling seamless rendering of human-readable names instead of UUIDs throughout the user interface.

## 🚀 Key Features

- **Ultra-Fast Performance**: Sub-10ms response times through aggressive caching
- **Bulk Operations**: Retrieve multiple names in a single request
- **Automatic Cache Management**: Integrates with object CRUD operations
- **Frontend Optimized**: Designed specifically for UI name resolution needs
- **Related Data Support**: Extract related object names from search results

## 📋 Available Endpoints

### 1. Get All Names or Specific Names (GET)

```
GET /api/names
GET /api/names?ids=uuid1,uuid2,uuid3
```

**Query Parameters:**
- `ids` (optional): Comma-separated list of object IDs/UUIDs or JSON array

**Response Example:**
```json
{
  "names": {
    "uuid-1": "Organization Alpha",
    "uuid-2": "Contact John Doe",
    "uuid-3": "Document Beta"
  },
  "total": 3,
  "cached": true,
  "execution_time": "2.45ms",
  "cache_stats": {
    "name_cache_size": 1564,
    "name_hit_rate": 98.5
  }
}
```

### 1b. Get Specific Names with JSON Body (POST)

```
POST /api/names
```

**Use Case:** Handles large UUID arrays that exceed URL length limits (especially with long UUIDs).

**Request Body:**
```json
{
  "ids": ["uuid-1", "uuid-2", "uuid-3"]
}
```

**Response Example:**
```json
{
  "names": {
    "uuid-1": "Organization Alpha",
    "uuid-2": "Contact John Doe",
    "uuid-3": "Document Beta"
  },
  "total": 3,
  "requested": 3,
  "cached": true,
  "execution_time": "0.77ms",
  "cache_stats": {
    "name_cache_size": 1564,
    "name_hit_rate": 100
  }
}
```

## ❌ Removed Endpoints (SEC-CTRL-2)

Three endpoints that used to be documented here have been **removed**. All three were
`#[PublicPage]`, i.e. reachable with no Nextcloud session at all.

| Removed | Why | What to use instead |
| --- | --- | --- |
| `GET /api/names/{id}` | Resolved **any** object's name through `findAcrossAllSources(_rbac: false, _multitenancy: false)`, trying organisations first. An anonymous caller holding a UUID could read names across every register, schema and tenant. | `POST /api/names` with `{"ids": ["<id>"]}` — same `{"names": {...}}` response shape, requires a session. |
| `GET /api/names/stats` | Exposed cache internals anonymously. | No public equivalent. Cache metrics belong in admin settings. |
| `POST /api/names/warmup` | Let an anonymous caller make the server rebuild the entire name cache — a cheap denial-of-service lever. | `POST /api/settings/cache/warmup-names` (admin only). |

Both surviving endpoints (`GET /api/names`, `POST /api/names`) return `401` without a session.

> ⚠️ Name resolution is still **not** RBAC- or tenant-aware once you are authenticated —
> `getMultipleObjectNames()` returns names across all organisations. See the open TODO in
> `NamesController::index()`. Requiring a session closed the anonymous hole; it did not make the
> resolver permission-aware.

## 🔗 Enhanced Search Responses

The Names API integrates with paginated search endpoints to provide related object data for frontend optimization.

### The `_names` Extension Parameter (Recommended)

The most efficient way to get UUID-to-name mappings is using the `_names` extension parameter. This eliminates the need for separate API calls to the Names service.

**Example Request:**
```
GET /api/objects/register/schema?_limit=10&_extend[]=_names
```

**Enhanced Response:**
```json
{
  "results": [...],
  "total": 150,
  "page": 1,
  "@self": {
    "source": "database",
    "names": {
      "uuid-1": "Organization Alpha",
      "uuid-2": "Contact John Doe",
      "uuid-3": "Related Document"
    }
  }
}
```

**How it works:**
1. Collects all UUIDs from object relations and properties
2. Resolves names using the cached name service (24-hour cache)
3. Returns names in `@self.names` for the entire result set

**Performance:**
- Uses the same cached name service as direct Names API calls
- Adds minimal overhead (~10-50ms depending on number of unique UUIDs)
- Eliminates need for separate frontend calls to `/api/names`

**Best for:**
- Collection endpoints returning multiple objects with relations
- Single object endpoints with many related UUIDs
- Frontend applications that need to display names immediately

### Related Data Parameters (Legacy)

Add these query parameters to any paginated search endpoint:

- `_related=true`: Include aggregated related object IDs
- `_relatedNames=true`: Include related object ID → name mappings

**Example Request:**
```
GET /api/objects?_limit=10&_related=true&_relatedNames=true
```

**Enhanced Response:**
```json
{
  "results": [...],
  "total": 150,
  "page": 1,
  "related": [
    "uuid-rel-1",
    "uuid-rel-2",
    "uuid-rel-3"
  ],
  "relatedNames": {
    "uuid-rel-1": "Related Organization A",
    "uuid-rel-2": "Related Contact B",
    "uuid-rel-3": "Related Document C"
  }
}
```

## ⚡ Performance Characteristics

### Benchmark Results - Search Response Enhancement

Based on comprehensive testing with 10 objects per paginated search request:

| Query Type | Average Response Time | Performance Impact |
|------------|----------------------|-------------------|
| **Standard Search** | 838ms | Baseline |
| **With `_related=true`** | 924ms | **+10% (+86ms)** |
| **With `_relatedNames=true`** | 804ms | **-4% (-34ms)** |
| **Both Parameters** | 894ms | **+7% (+56ms)** |

### Key Performance Insights

- **`_related=true`**: Small 10% performance cost for extracting relationship IDs
- **`_relatedNames=true`**: Actually 4% faster due to efficient cache usage  
- **Combined Parameters**: Moderate 7% overhead when using both features
- **Single Name Lookup**: Ultra-fast **0.4ms** response time from cache

### Cache Layers

```mermaid
graph TD
    A[Frontend Request] --> B[In-Memory Cache]
    B --> C{Cache Hit?}
    C -->|Yes| D[Return Name <1ms]
    C -->|No| E[Distributed Cache]
    E --> F{Cache Hit?}
    F -->|Yes| G[Return Name <5ms]
    F -->|No| H[Database Lookup]
    H --> I[Cache & Return <15ms]
```

### Cache Performance Benchmarks

| Operation | Cache Status | Response Time | Use Case |
|-----------|-------------|---------------|----------|
| Single Name | In-Memory Hit | **0.4ms** | Individual lookups |
| Bulk Names (50) | Mixed Cache | 3-8ms | Batch operations |
| All Names | Warmed Cache | 5-15ms | Initial load |
| Cache Warmup | Database Load | **11ms** (1,500+ names) | System initialization |
| Cold Cache | Database | 20-50ms | First access |

## 💻 Frontend Integration Examples

### React Hook Example

```javascript
// Custom hook for name resolution with POST support for large ID arrays
function useObjectNames(ids) {
  const [names, setNames] = useState({});
  const [loading, setLoading] = useState(true);
  
  useEffect(() => {
    if (ids && ids.length > 0) {
      // Use POST method for large ID arrays (>50 UUIDs) to avoid URL length limits
      if (ids.length > 50) {
        fetch('/api/names', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ids })
        })
          .then(r => r.json())
          .then(data => {
            setNames(data.names);
            setLoading(false);
          });
      } else {
        // Use GET method for smaller ID arrays
        const idsParam = ids.join(',');
        fetch('/api/names?ids=' + idsParam)
          .then(r => r.json())
          .then(data => {
            setNames(data.names);
            setLoading(false);
          });
      }
    }
  }, [ids]);
  
  return { names, loading };
}

// Usage in component
function ObjectList({ objects }) {
  const ids = objects.map(obj => obj.uuid);
  const { names, loading } = useObjectNames(ids);
  
  return (
    <div>
      {objects.map(obj => (
        <div key={obj.uuid}>
          {loading ? obj.uuid : names[obj.uuid] || obj.uuid}
        </div>
      ))}
    </div>
  );
}
```

### Vue.js Example

```javascript
// Vue 3 Composition API
import { ref, computed, watch } from 'vue';

export function useNames(objectIds) {
  const names = ref({});
  const loading = ref(false);
  
  const fetchNames = async (ids) => {
    if (!ids || ids.length === 0) return;
    
    loading.value = true;
    try {
      const response = await fetch(`/api/names?ids=${ids.join(',')}`);
      const data = await response.json();
      names.value = { ...names.value, ...data.names };
    } finally {
      loading.value = false;
    }
  };
  
  watch(() => objectIds.value, fetchNames, { immediate: true });
  
  const getNameOrUuid = (uuid) => names.value[uuid] || uuid;
  
  return { names, loading, getNameOrUuid };
}
```

## 🔄 Cache Management

### Cache Configuration

The name cache is configured for optimal performance with long-lived entries:

| Setting | Value | Description |
|---------|-------|-------------|
| **Default TTL** | 24 hours | Names rarely change, long cache is safe |
| **Maximum TTL** | 24 hours | Enforced maximum for all cache entries |
| **Nightly Warmup** | Automatic | Background job pre-loads all names daily |

### Automatic Cache Updates

The name cache automatically updates when objects are modified:

- **Create**: New object names are immediately cached
- **Update**: Modified names are updated in cache
- **Delete**: Deleted objects are removed from both in-memory and distributed cache
- **Bulk Operations**: Efficiently handles bulk CRUD operations

### Nightly Cache Warmup

A background job (`NameCacheWarmupJob`) runs every 24 hours to pre-populate the distributed cache with all object names. This ensures:

- First morning requests are fast (no cold cache)
- Names are loaded from all sources:
  - Organisations table
  - Objects table
  - All magic tables (register+schema combinations)

### Manual Cache Control

```bash
# Warmup cache manually
curl -X POST /api/names/warmup

# Check cache statistics
curl /api/names/stats

# Force cache refresh (via warmup)
curl -X POST /api/names/warmup
```

## 🎯 Best Practices

### 1. Frontend Patterns

```javascript
// ✅ Good: Batch multiple name lookups with method selection
const allIds = [...relationIds, ...ownerIds, ...categoryIds];

// Choose method based on ID count to avoid URL length limits
if (allIds.length > 50) {
  // Use POST for large arrays
  const names = await fetch('/api/names', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids: allIds })
  }).then(r => r.json());
} else {
  // Use GET for smaller arrays
  const names = await fetch(`/api/names?ids=${allIds.join(',')}`).then(r => r.json());
}

// ❌ Bad: Individual requests
for (const id of relationIds) {
  const name = await fetchName(id); // Creates N requests
}
```

### 1b. URL Length Considerations

```javascript
// ✅ Good: Handle URL length limits gracefully
function fetchObjectNames(ids) {
  // UUID strings average ~36 chars + comma = ~37 chars per ID
  // Most browsers support ~2000 char URLs safely
  // 50 UUIDs = ~1850 chars (safe margin)
  const URL_SAFE_LIMIT = 50;
  
  if (ids.length > URL_SAFE_LIMIT) {
    return fetch('/api/names', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids })
    });
  }
  
  return fetch(`/api/names?ids=${ids.join(',')}`);
}
```

### 2. Caching Strategy

```javascript
// ✅ Good: Use related names for nested data
const response = await fetch('/api/objects?_relatedNames=true');
const { results, relatedNames } = response.data;

// ❌ Bad: Separate requests for related data
const results = await fetch('/api/objects').then(r => r.json());
const relatedNames = await fetch('/api/names?ids=' + extractIds(results));
```

### 3. Error Handling

```javascript
// ✅ Good: Graceful fallback to UUIDs
function displayName(uuid, names) {
  return names[uuid] || uuid; // Falls back to UUID if name not found
}

// ✅ Good: Handle missing names gracefully
const { names = {}, error } = await fetchNames(ids);
if (error) {
  console.warn('Name lookup failed, using UUIDs');
}
```

## 🔧 Configuration

### Schema-Based Name Mapping

Names are extracted using schema configuration:

```json
{
  "configuration": {
    "objectNameField": "naam",
    "objectSummaryField": "beschrijvingKort",
    "objectDescriptionField": "beschrijving"
  }
}
```

If no name field is configured, the object UUID is used as fallback.

## 📊 Monitoring & Debugging

### Performance Monitoring

```javascript
// Monitor cache performance
const stats = await fetch('/api/names/stats').then(r => r.json());
console.log('Name Cache Hit Rate:', stats.cache_statistics.name_hit_rate + '%');
```

### Debug Information

All name endpoints include execution time and cache statistics in responses for performance analysis.

## 🚨 Error Handling

### Common Error Scenarios

| Status Code | Scenario | Response |
|-------------|----------|----------|
| 200 | Success | Names returned with cache stats |
| 404 | Object not found | `{"found": false, "name": null}` |
| 500 | Cache/DB error | `{"error": "Failed to retrieve names"}` |

### Graceful Degradation

The Names API is designed for graceful degradation:
- Missing names fall back to object UUIDs
- Cache failures fall back to direct database lookups
- Partial results are returned when some objects are not found

## 🔮 Future Enhancements

- **Elasticsearch Integration**: For full-text name searching
- **Real-time Updates**: WebSocket-based cache invalidation
- **Multi-language Names**: Support for internationalized object names
- **Advanced Filtering**: Name-based object filtering capabilities
