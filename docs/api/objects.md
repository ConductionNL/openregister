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
- **`_extend`**: Properties to extend (comma-separated or array)
  - Extend object properties by fetching related objects
  - Special values:
    - `_registers`: Include full register object(s) in `@self.registers`
    - `_schemas`: Include full schema object(s) in `@self.schemas`
    - `_files` (or canonical `@self.files`): Include full file metadata in `@self.files`. Equivalent spellings, normalized internally. **Default behavior:** when this extension is NOT requested, `@self.files` is a lightweight list of integer file IDs (`[123, 456]`). When requested, `@self.files` is an array of full file objects with `id`, `path`, `title`, `accessUrl`, `downloadUrl`, `type`, `extension`, `size`, `hash`, `published`, `modified`, `labels`. The lightweight default uses a single batched query regardless of page size.
  - When any `_extend` is requested, `@self.objects` will contain all extended objects indexed by UUID
  - **Performance note**: Using `_extend` adds approximately 300ms overhead per request due to additional database queries for related objects. For performance-critical applications, consider fetching related objects separately or using caching strategies.
  - **Heavily discouraged on list endpoints: `_extend[]=@self.files` (or `_files`).** This extension causes one file lookup and one tag lookup *per row* on the page (N+1 queries scaling with page size) and will result in degraded performance. Use it only when full file metadata is genuinely required for every row of the list. For single-object reads (show endpoints), the cost is fixed (one extra request) and acceptable. For lists, prefer the lightweight default and look up full metadata for specific objects on demand.
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

# Search with property extension
GET /api/objects/1/3?_extend=aanbieder&_limit=10

# Search with register and schema metadata
GET /api/objects/1/3?_extend=_registers,_schemas&_limit=10

# Combine property extension with metadata
GET /api/objects/1/3?_extend=aanbieder,_registers,_schemas&_limit=10

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
  "@self": {
    "source": "database",
    "registers": {
      "1": { "id": 1, "title": "Register Name", "..." : "..." }
    },
    "schemas": {
      "3": { "id": 3, "title": "Schema Name", "..." : "..." }
    },
    "objects": {
      "related-uuid": { "..." : "..." }
    }
  },
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
  }
}
```

**Note:** The `@self` section at the response root level contains:
- `registers`: Only included when `_extend=_registers` is specified
- `schemas`: Only included when `_extend=_schemas` is specified
- `objects`: Extended/related objects indexed by UUID (included when any `_extend` is specified)

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
- **`@self.ignoredFilters`**: Array of filter property names that were ignored (only present when filters are ignored)

#### Ignored Filters

When you filter by a property that doesn't exist in the schema, the API will:
1. Return zero results for that schema (to prevent unfiltered data leakage)
2. Include the ignored property names in `@self.ignoredFilters`

This is particularly useful for:
- **Debugging**: Understanding why a query returns unexpected (empty) results
- **Multi-schema searches**: When filtering by a property that exists in some schemas but not others

**Example Request with Invalid Filter:**
```bash
GET /api/objects/1/3?invalidProperty=test&_limit=10
```

**Response:**
```json
{
  "results": [],
  "total": 0,
  "page": 1,
  "pages": 0,
  "limit": 10,
  "@self": {
    "ignoredFilters": ["invalidProperty"],
    "source": "database"
  }
}
```

**Note:** In multi-schema searches, a filter is reported as ignored if it doesn't exist in at least one of the searched schemas. This helps identify when a filter only applies to a subset of schemas.

**Common causes for ignored filters:**
- Typos in property names (e.g., `name` instead of `naam`)
- Using pagination parameters without underscore prefix (use `_limit` not `limit`)
- Filtering by a property from a different schema

### Search All Objects

**GET** `/api/objects`

Retrieves a paginated list of objects across all registers and schemas that the current user has access to.

#### Parameters

Same as the register/schema specific endpoint, with additional filtering options:

##### Multi-Register/Schema Search

You can filter by specific registers and schemas using query parameters:

- **'register'**: Single register ID or array of register IDs
- **'schema'**: Single schema ID or array of schema IDs

**Examples:**

```bash
# Search in single register and schema
GET /api/objects?register=1&schema=3

# Search in multiple registers
GET /api/objects?register[]=1&register[]=2&schema=3

# Search in multiple schemas
GET /api/objects?register=1&schema[]=3&schema[]=4

# Search across multiple registers and schemas
GET /api/objects?register[]=1&register[]=2&schema[]=3&schema[]=4&schema[]=5

# Combine with other filters
GET /api/objects?register[]=1&register[]=2&schema[]=3&_search=test&_limit=50
```

**Use Cases:**
- Search across all publication types (articles, books, reports) at once
- Query multiple data sources simultaneously
- Build unified views across related schemas
- Compare data across different registers

**Note:** The backend automatically uses SQL 'IN' clauses for efficient filtering when arrays are provided.

### Get Single Object

**GET** `/api/objects/{register}/{schema}/{id}`

Retrieves a single object by ID.

#### Parameters

- **`_extend`**: Properties to extend (comma-separated or array)
  - Extend object properties by fetching related objects
  - Special values:
    - `_registers`: Include full register object in `@self.registers`
    - `_schemas`: Include full schema object in `@self.schemas`
    - `_files` (or canonical `@self.files`): Include full file metadata in `@self.files`. Equivalent spellings, normalized internally. Without this extension, `@self.files` is a lightweight list of integer file IDs.
  - When any `_extend` is requested, `@self.objects` will contain all extended objects indexed by UUID
- **`_fields`**: Fields to include
- **`_filter`**: Fields to filter
- **`_unset`**: Fields to exclude

#### Example Requests

```bash
# Get single object
GET /api/objects/1/3/uuid

# Get single object with extended property
GET /api/objects/1/3/uuid?_extend=aanbieder

# Get single object with register and schema metadata
GET /api/objects/1/3/uuid?_extend=_registers,_schemas

# Get single object with full file metadata (both spellings work)
GET /api/objects/1/3/uuid?_extend=@self.files
GET /api/objects/1/3/uuid?_extend=_files

# Combine property extension with metadata
GET /api/objects/1/3/uuid?_extend=aanbieder,_registers,_schemas
```

#### Single Object Response Format

```json
{
  "id": "uuid",
  "naam": "Object Name",
  "beschrijvingKort": "Short description",
  "aanbieder": "related-uuid",
  "@self": {
    "id": "uuid",
    "name": "Object Name",
    "register": "1",
    "schema": "3",
    "created": "2024-01-01T00:00:00Z",
    "updated": "2024-01-01T00:00:00Z",
    "owner": "admin",
    "organisation": "org-uuid",
    "registers": {
      "1": { "id": 1, "title": "Register Name", "..." : "..." }
    },
    "schemas": {
      "3": { "id": 3, "title": "Schema Name", "..." : "..." }
    },
    "objects": {
      "related-uuid": { "..." : "..." }
    }
  }
}
```

**Note:** For single objects, the `registers`, `schemas`, and `objects` are included in the object's own `@self` section (not at the response root level).

### Create Object

**POST** `/api/objects/{register}/{schema}`

Creates a new object in the specified register and schema.

**Authentication:** Public (`@PublicPage`) — no authentication required.

#### Request Formats

##### JSON

```bash
curl -X POST /api/objects/1/3 \
  -H "Content-Type: application/json" \
  -d '{
    "naam": "New Object",
    "beschrijvingKort": "A short description",
    "type": "Leverancier"
  }'
```

##### Multipart Form Data (with file uploads)

Use `multipart/form-data` to upload files alongside object data. Each form field maps to a schema property. Files are automatically stored in Nextcloud and linked to the object.

```bash
curl -X POST /api/objects/1/3 \
  -F "naam=New Object" \
  -F "type=Leverancier" \
  -F "logo=@/path/to/logo.png" \
  -F 'contactpersonen=[{"voornaam":"John","achternaam":"Doe"}]'
```

**Important notes for multipart requests:**
- Each form field name must match a schema property name
- File fields (like `logo`) should contain a file upload — the backend will store the file and set the property value
- Complex fields (arrays, objects) must be JSON-stringified (e.g., `contactpersonen=[{"voornaam":"John"}]`)
- There is no file size limit imposed by the API (only PHP/server limits apply)

#### Response

```json
{
  "id": "uuid",
  "naam": "New Object",
  "type": "Leverancier",
  "logo": "data:image/png;base64,...",
  "@self": {
    "id": "uuid",
    "register": "1",
    "schema": "3",
    "files": [
      {
        "id": "806",
        "path": "files/Open Registers/.../logo_1234567890_abc123.png",
        "title": "logo_1234567890_abc123.png",
        "accessUrl": "http://localhost:8080/index.php/s/shareToken",
        "downloadUrl": "http://localhost:8080/index.php/s/shareToken/download",
        "type": "image/png",
        "size": 12345,
        "labels": ["property:logo"]
      }
    ],
    "created": "2024-01-01T00:00:00Z"
  }
}
```

**Status:** `201 Created`

### Update Object (Full Replace)

**PUT** `/api/objects/{register}/{schema}/{id}`

Replaces all fields of an existing object. Fields not included in the request body will be set to null.

**Authentication:** Required (user must be logged in).

> **Note:** PUT does not support multipart file uploads due to a PHP limitation (`$_FILES` is only populated for POST requests). Use JSON for PUT requests, or use the POST-as-PATCH endpoint below for file uploads on existing objects.

```bash
curl -X PUT /api/objects/1/3/uuid \
  -u admin:admin \
  -H "Content-Type: application/json" \
  -d '{
    "naam": "Updated Object",
    "beschrijvingKort": "Updated description",
    "type": "Gemeente"
  }'
```

**Status:** `200 OK`

### Patch Object (Partial Update)

**PATCH** `/api/objects/{register}/{schema}/{id}`

Partially updates an existing object. Only the fields included in the request body are updated; other fields remain unchanged.

**Authentication:** Required (user must be logged in).

> **Note:** PATCH does not support multipart file uploads due to the same PHP limitation as PUT. Use the POST-as-PATCH endpoint below for file uploads on existing objects.

```bash
curl -X PATCH /api/objects/1/3/uuid \
  -u admin:admin \
  -H "Content-Type: application/json" \
  -d '{"naam": "Patched Name"}'
```

**Status:** `200 OK`

### Update Object with File Upload (POST-as-PATCH)

**POST** `/api/objects/{register}/{schema}/{id}`

Partially updates an existing object using PATCH semantics, with support for multipart file uploads. This endpoint exists because PHP only populates `$_FILES` for POST requests — making it impossible to upload files via PUT or PATCH with `multipart/form-data`.

**Authentication:** Public (`@PublicPage`) — no authentication required.

#### When to Use

Use this endpoint instead of PUT/PATCH when you need to:
- Upload or replace files on an existing object
- Update an object from a public (unauthenticated) context with file attachments
- Submit form data with file inputs to an existing object

#### Example Requests

```bash
# Update name and upload a new logo (no authentication needed)
curl -X POST /api/objects/1/3/uuid \
  -F "naam=Updated Name" \
  -F "logo=@/path/to/new-logo.png"

# Update only the logo file
curl -X POST /api/objects/1/3/uuid \
  -F "logo=@/path/to/new-logo.png"

# With authentication
curl -X POST /api/objects/1/3/uuid \
  -u admin:admin \
  -F "naam=Updated Name" \
  -F "logo=@/path/to/new-logo.png"
```

#### Behavior

- Fields included in the request are merged with existing object data (PATCH semantics)
- Fields not included in the request remain unchanged
- Uploaded files replace any previously stored file for that property
- The object must already exist; returns `404` if not found

**Status:** `200 OK`

### Delete Object

**DELETE** `/api/objects/{register}/{schema}/{id}`

Deletes an existing object.

**Authentication:** Required (user must be logged in).

```bash
curl -X DELETE /api/objects/1/3/uuid -u admin:admin
```

**Status:** `200 OK`

## File Uploads

### How File Uploads Work

When a file is uploaded via multipart/form-data (on POST create or POST-as-PATCH), the following happens:

1. The file is extracted from `$_FILES` by the controller
2. The file is converted to a base64 data URI internally
3. The file property handler stores the file in Nextcloud's file system under the object's folder
4. A public share link is created for the file
5. The file metadata is added to `@self.files` with a `property:{fieldName}` label

### File Storage

Files are stored in the Nextcloud file system under:
```
files/Open Registers/{Register Name}/{object-uuid}/{fieldName}_{timestamp}_{hash}.{ext}
```

For **unauthenticated** (public) requests, files are stored under the OpenRegister system user account. For authenticated requests, files are stored under the requesting user's account.

### Accessing Files

Each stored file has:
- **`accessUrl`**: A public Nextcloud share link to view the file
- **`downloadUrl`**: A direct download link (append `/download` to the share link)

Both URLs are publicly accessible without authentication.

### Endpoint Comparison for File Support

| Method | URL | Auth Required | File Uploads | Use Case |
|--------|-----|--------------|-------------|----------|
| `POST` | `/api/objects/{register}/{schema}` | No | Yes | Create with files |
| `POST` | `/api/objects/{register}/{schema}/{id}` | No | Yes | Update with files |
| `PUT` | `/api/objects/{register}/{schema}/{id}` | Yes | No | Full replace (JSON only) |
| `PATCH` | `/api/objects/{register}/{schema}/{id}` | Yes | No | Partial update (JSON only) |

> **Why can't PUT/PATCH handle file uploads?** PHP only populates the `$_FILES` superglobal for POST requests. When using PUT or PATCH with `multipart/form-data`, the request body is not parsed into `$_FILES` by PHP, so uploaded files cannot be accessed. The POST-as-PATCH endpoint works around this limitation.

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
- **`folder`**: Numeric Nextcloud folder ID to bind the object to (see access-control contract below)

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

For detailed information about @self metadata handling, see [Self Metadata Handling](../development/self-metadata-handling.md).

### `@self.folder` access-control contract

The `@self.folder` metadata field binds an object to an existing Nextcloud folder
by node ID. The bind is governed by an access-control check on every save:

- **Empty / absent** — the system creates a new folder under the register's root
  folder and stores the new node ID on the object. (Default behaviour, unchanged.)
- **Legacy non-numeric** (path-style strings from older installs) — auto-create
  proceeds as before; no access check runs.
- **Non-empty numeric** (the format produced by current `@self.folder` writes) —
  the acting user MUST be able to read the folder. The check uses the user's
  user-folder mount and `Folder::isReadable()`. If either fails — folder doesn't
  exist in the user's mount, the resolved node is a file, the folder is trashed,
  or the user has no read permission — the save is rejected.

#### Denial response shape

When `@self.folder` is rejected, the endpoint returns **HTTP 403** with body:

```json
{
  "error": "folder_access_denied",
  "folder": "99"
}
```

`folder` echoes the attempted node ID. The check applies uniformly across
`POST` (create), `PUT` (update), and `PATCH` (partial update) on object endpoints.

#### Acting user resolution ("self")

The check resolves the acting user in this order:

1. The `IUser` explicitly passed to the underlying service helper (DI / cron path).
2. The session user (`IUserSession::getUser()`).
3. If neither resolves, the bind is **denied** by default — there is no fail-open
   path on `@self.folder` writes.

#### Audit trail

Every denial writes a forensic audit-trail entry with `action: "folder_access_denied"`,
the actor (UID or `"system"`), the attempted folder ID, and a reason code. The
entry is written **before** the exception is thrown, so even a caller that
catches the exception has a record. Audit-write failures are logged at warning
level and do **not** swallow the denial — denial is authoritative.

For cleanup of stale `@self.folder` references on existing objects (folders the
owner can no longer access), an `occ openregister:folder-audit` command is
tracked separately as a follow-up.

#### See also

- Capability spec (post-archive): `openspec/specs/self-folder-access-control/spec.md`
- Architectural context: ADR-007 (Security and Auth), ADR-008 (Backend Layering)
- Downstream consumer benefiting from this hardening: DocuDesk's `add-dossier-schema` change.

## Security

- **RBAC**: Respects role-based access control
- **Multitenancy**: Filters results by user's organisation when enabled
- **Admin override**: Admin users can bypass RBAC and multitenancy restrictions
- **Published objects**: Objects that are currently published (published date ≤ now AND depublished date is null or > now) are publicly available and bypass both RBAC and multitenancy restrictions, making them visible to all users regardless of their roles or organization
