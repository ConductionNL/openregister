# Objects API

The Objects API provides comprehensive search and management capabilities for objects within registers and schemas.

## Endpoints

### Search Objects

**GET** `/api/objects/{register}/{schema}`

Retrieves a paginated list of objects that match the specified register and schema.

#### Parameters

##### Standard Filters
- **Any object field**: Filter by any field in the object schema (e.g., `name`, `status`, `type`, etc.)

##### Metadata Filters
- **register**: Filter by register identifier
- **schema**: Filter by schema identifier  
- **uuid**: Filter by object UUID
- **organisation**: Filter by organisation UUID
- **owner**: Filter by object owner
- **application**: Filter by application
- **created**: Filter by creation date
- **updated**: Filter by last update date
- **published**: Filter by publication date
- **depublished**: Filter by depublication date
- **deleted**: Filter by deletion date

##### Pagination
- **`_limit`**: Number of items per page (default: 20)
- **`_offset`**: Number of items to skip
- **`_page`**: Current page number (alternative to `_offset`)

##### Search
- **`_search`**: Full-text search term

##### Rendering
- **`_extend`**: Properties to extend (comma-separated)
- **`_fields`**: Fields to include (comma-separated)
- **`_filter`**: Fields to filter (comma-separated)
- **`_unset`**: Fields to exclude (comma-separated)

##### Faceting (SOLR only)
- **`_facets`**: Facet configuration object
- **`_facetable`**: Enable facetable field discovery (boolean)

##### Aggregations (SOLR only)
- **`_aggregations`**: Enable aggregations in response (boolean)
  - Currently an alias for facets, but reserved for future advanced aggregation features
  - **Future capabilities**: Statistical aggregations (sum, avg, min, max, count), time series analysis, nested aggregations, and complex mathematical analysis using SOLR's Analytics Component

##### Debug (SOLR only)
- **`_debug`**: Enable debug information in response (boolean)
  - Includes SOLR query details, execution times, and internal processing information

##### Source Selection
- **`_source`**: Force search source
  - `database`: Use database search (default for simple queries)
  - `index` or `solr`: Use SOLR search engine

##### Sorting
- **`_order`**: Sort specification (field:direction or array of field:direction pairs)

#### Example Requests

```bash
# Basic search
GET /api/objects/1/3?_limit=10

# Search with facets (SOLR only)
GET /api/objects/1/3?_facetable=true&_limit=10

# Search with aggregations (SOLR only)  
GET /api/objects/1/3?_aggregations=true&_limit=10

# Search with debug information (SOLR only)
GET /api/objects/1/3?_debug=true&_limit=10

# Force database search
GET /api/objects/1/3?_source=database&_limit=10

# Force SOLR search
GET /api/objects/1/3?_source=index&_limit=10

# Search with filtering
GET /api/objects/1/3?naam=test&_limit=10

# Search with date filtering
GET /api/objects/1/3?created=2024-01-01&_limit=10
```

#### Response Format

```json
{
  "results": [
    {
      "id": "uuid",
      "naam": "Object Name",
      "beschrijvingKort": "Short description",
      "@self": {
        "id": "uuid",
        "name": "Object Name",
        "register": "1",
        "schema": "3",
        "created": "2024-01-01T00:00:00Z",
        "updated": "2024-01-01T00:00:00Z",
        "owner": "admin",
        "organisation": "org-uuid"
      }
    }
  ],
  "total": 1000,
  "page": 1,
  "pages": 50,
  "limit": 20,
  "facets": {
    "facet_queries": [],
    "facet_fields": {
      "self_register": ["1", 1000],
      "self_schema": ["3", 1000]
    },
    "facet_ranges": [],
    "facet_intervals": [],
    "facet_heatmaps": []
  },
  "aggregations": {
    "facet_queries": [],
    "facet_fields": {
      "self_register": ["1", 1000],
      "self_schema": ["3", 1000]
    },
    "facet_ranges": [],
    "facet_intervals": [],
    "facet_heatmaps": []
  },
  "debug": {
    "url": "http://solr:8983/solr/openregister/select?...",
    "solr_numFound": 1000,
    "solr_status": 0,
    "translated_query": {...},
    "solr_facets": {...}
  },
  "_source": "index"
}
```

#### Response Fields

- **`results`**: Array of matching objects
- **`total`**: Total number of matching objects
- **`page`**: Current page number
- **`pages`**: Total number of pages
- **`limit`**: Number of items per page
- **`facets`**: Facet data (only when `_facetable=true`)
- **`aggregations`**: Aggregation data (only when `_aggregations=true`)
- **`debug`**: Debug information (only when `_debug=true`)
- **`_source`**: Search source used (`database` or `index`)

### Search All Objects

**GET** `/api/objects`

Retrieves a paginated list of objects across all registers and schemas that the current user has access to.

#### Parameters

Same as the register/schema specific endpoint, except:
- No `register` or `schema` parameters (searches across all)
- Respects RBAC and multitenancy settings

### Get Single Object

**GET** `/api/objects/{register}/{schema}/{id}`

Retrieves a single object by ID.

#### Parameters

- **`_extend`**: Properties to extend
- **`_fields`**: Fields to include
- **`_filter`**: Fields to filter
- **`_unset`**: Fields to exclude

## Error Handling

### SOLR-Only Features in Database Mode

When using `_source=database` (or default database mode), certain features are not available:

- **`_facetable=true`**: Will return an error
- **`_aggregations=true`**: Will return an error

**Error Response:**
```json
{
  "error": "Facets and aggregations are only available when using SOLR search engine. Please use _source=index parameter to enable SOLR search, or remove _facetable/_aggregations parameters."
}
```

## Performance Considerations

- **Database mode**: Faster for simple queries, supports all basic filtering
- **SOLR mode**: Better for complex searches, faceting, and aggregations
- **Automatic selection**: System automatically chooses the best source unless `_source` is specified

## @self Metadata

Objects include a special `@self` metadata section that contains system-managed information:

```json
{
  "@self": {
    "id": "object-uuid",
    "name": "Object Name",
    "register": "1",
    "schema": "3",
    "created": "2024-01-01T00:00:00Z",
    "updated": "2024-01-01T00:00:00Z",
    "owner": "owner-uuid",
    "organisation": "org-uuid",
    "published": "2024-01-01T00:00:00Z",
    "depublished": null
  }
}
```

### Modifiable @self Properties

When creating or updating objects, you can explicitly set certain @self metadata properties:

- **`owner`**: Object owner UUID
- **`organisation`**: Organization UUID  
- **`published`**: Publication timestamp
- **`depublished`**: Depublication timestamp

Example:
```json
{
  "naam": "My Object",
  "@self": {
    "owner": "user-uuid",
    "organisation": "org-uuid",
    "published": "2024-01-01T00:00:00Z"
  }
}
```

For detailed information about @self metadata handling, see [Self Metadata Handling](../developers/self-metadata-handling.md).

## Security

- **RBAC**: Respects role-based access control
- **Multitenancy**: Filters results by user's organisation when enabled
- **Admin override**: Admin users can bypass RBAC and multitenancy restrictions
- **Published objects**: Objects that are currently published (published date ≤ now AND depublished date is null or > now) are publicly available and bypass both RBAC and multitenancy restrictions, making them visible to all users regardless of their roles or organization
