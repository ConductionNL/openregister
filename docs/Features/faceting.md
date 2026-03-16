# Faceting

OpenRegister provides an Elasticsearch-inspired faceting system for building faceted search interfaces. It supports disjunctive faceting, multiple facet types (terms, date histograms, numeric ranges), automatic UUID-to-label resolution, and schema-driven field discovery.

## Configuring Facetable Properties

Facets are configured per property in a schema definition using the `facetable` field. Two formats are supported:

### Boolean Format (Simple)

Set `"facetable": true` to enable a facet with default settings:

```json
{
  "properties": {
    "status": {
      "type": "string",
      "title": "Status",
      "facetable": true
    }
  }
}
```

This is equivalent to the object format with all defaults:
```json
{ "aggregated": true, "title": null, "description": null, "order": null }
```

### Object Format (Full Control)

Use an object to configure facet behavior:

```json
{
  "properties": {
    "status": {
      "type": "string",
      "title": "Status",
      "facetable": {
        "aggregated": true,
        "title": "Current Status",
        "description": "Filter by the current status of the item",
        "order": 1
      }
    },
    "category": {
      "type": "string",
      "title": "Category",
      "facetable": {
        "aggregated": false,
        "title": "Product Category",
        "order": 2
      }
    }
  }
}
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `aggregated` | boolean | `true` | When `true`, values from all schemas with the same property name are combined into one facet. When `false`, the facet is computed per-schema. |
| `title` | string | `null` | Display name in the filter panel. Falls back to the property `title` or key name. |
| `description` | string | `null` | Help text explaining what the facet filters on. |
| `order` | integer | `null` | Controls display position relative to other facets. Lower numbers appear first. |
| `type` | string | `null` | Override the auto-detected facet type. Valid values: `date_histogram`, `date_range`, `terms`. When `null`, the type is auto-detected from the property format. |
| `options` | object | `null` | Type-specific options (see below). |

#### Date Faceting Options

For `date` and `date-time` properties, you can control how dates are faceted using the `type` and `options` fields:

**Date Histogram** — groups dates by interval:

```json
{
  "published_date": {
    "type": "string",
    "format": "date",
    "facetable": {
      "type": "date_histogram",
      "options": {
        "interval": "month",
        "format": "F Y"
      }
    }
  }
}
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `interval` | string | `"month"` | Grouping interval: `day`, `week`, `month`, `quarter`, `year` |
| `format` | string | auto | PHP date format for bucket labels. Auto-detected from interval (e.g., `"Y"` for year, `"F Y"` for month). |

**Date Range** — predefined date ranges:

```json
{
  "created_at": {
    "type": "string",
    "format": "date-time",
    "facetable": {
      "type": "date_range",
      "options": {
        "useDefaultRanges": true
      }
    }
  }
}
```

Default ranges: Last 7 days, Last 30 days, Last 90 days, Last year, Older.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `useDefaultRanges` | boolean | `true` | Use the built-in predefined ranges. |
| `ranges` | array | — | Custom range definitions (overrides defaults). Each range has `label`, and optional `from`/`to` as relative date expressions (e.g., `"-7 days"`, `"-1 year"`). |

Custom ranges example:
```json
{
  "options": {
    "ranges": [
      { "label": "This week", "from": "-7 days" },
      { "label": "This month", "from": "-30 days", "to": "-7 days" },
      { "label": "This quarter", "from": "-90 days", "to": "-30 days" },
      { "label": "Older", "to": "-90 days" }
    ]
  }
}
```

Relative date expressions (e.g., `"-7 days"`) are resolved to absolute dates on each request using PHP `strtotime()`. Facets with relative date ranges use a shorter cache TTL (300s vs 3600s) to keep counts accurate.

**Terms** — override date auto-detection to use exact value matching:

```json
{
  "event_date": {
    "type": "string",
    "format": "date",
    "facetable": {
      "type": "terms"
    }
  }
}
```

### Backward Compatibility

Both formats are normalized internally by `FacetHandler::normalizeFacetConfig()`. The boolean `true` is converted to the object format with defaults. Setting `"facetable": false` or omitting it disables faceting for that property.

### What Makes a Good Facet

**Good candidates:**
- Status fields (Active, Archived, Draft)
- Type/category fields
- Organisation names
- Boolean flags (yes/no)
- Date fields (creation dates, deadlines)
- Numeric ranges (priority, rating)
- Tags/arrays with discrete values

**Poor candidates:**
- Free-text descriptions (high cardinality)
- Unique identifiers (UUIDs, unique names)
- Timestamps with second precision (use date histograms instead)

### Complete Schema Example

```json
{
  "title": "Publication",
  "properties": {
    "title": {
      "type": "string",
      "title": "Publication Title"
    },
    "category": {
      "type": "string",
      "title": "Category",
      "facetable": {
        "aggregated": true,
        "title": "Publication Category",
        "order": 1
      }
    },
    "status": {
      "type": "string",
      "title": "Status",
      "facetable": {
        "aggregated": true,
        "title": "Status",
        "description": "Filter by publication status",
        "order": 2
      }
    },
    "published_date": {
      "type": "string",
      "format": "date",
      "title": "Publication Date",
      "facetable": true
    },
    "priority": {
      "type": "integer",
      "title": "Priority",
      "minimum": 1,
      "maximum": 5,
      "facetable": {
        "aggregated": false,
        "title": "Priority Level",
        "order": 3
      }
    },
    "is_featured": {
      "type": "boolean",
      "title": "Featured",
      "facetable": true
    },
    "tags": {
      "type": "array",
      "items": { "type": "string" },
      "title": "Tags",
      "facetable": true
    },
    "internal_notes": {
      "type": "string",
      "title": "Internal Notes"
    }
  }
}
```

Properties without `facetable` (like `title` and `internal_notes`) are not available as facets.

### Configuring Facets via the UI

1. Navigate to **Schemas** in the left sidebar
2. Open the schema you want to configure
3. In the **Properties** tab, click the **three-dot menu** next to a property
4. Enable **Facetable** and configure the settings (aggregated, title, description, order)
5. For `date` or `date-time` properties, additional options appear:
   - **Facet Type**: Choose between Date Histogram, Date Range, or Terms (exact values)
   - **Interval** (histogram only): Group by Day, Week, Month, Quarter, or Year
   - **Display Format** (histogram only): Custom PHP date format for bucket labels
   - **Use default ranges** (date range only): Toggle predefined ranges (Last 7/30/90 days, Last year, Older)
6. Click **Save** on the schema

## Automatic Facet Type Detection

The system determines the appropriate facet type from the property definition:

| Property Type | Format | Facet Type |
|---------------|--------|------------|
| `string` | (none) | `terms` |
| `string` | `date` or `date-time` | `date_histogram` |
| `string` | `email`, `uri`, `uuid` | `terms` |
| `integer` / `number` | — | `terms` (also supports `range`) |
| `boolean` | — | `terms` |
| `array` | — | `terms` (each array element becomes a bucket) |

## Facet Types

### 1. Terms Aggregation

For categorical data — status, priority, category, etc.

Request:
```bash
curl "http://localhost:8080/index.php/apps/openregister/api/objects/5/24?_facets[status][type]=terms&_facets[priority][type]=terms" \
  -u admin:admin
```

Response (facets section):
```json
{
  "status": {
    "type": "terms",
    "buckets": [
      { "key": "active", "results": 134, "label": "active" },
      { "key": "pending", "results": 45, "label": "pending" },
      { "key": "inactive", "results": 23, "label": "inactive" }
    ]
  }
}
```

### 2. Date Histogram

For time-based data with configurable intervals (`day`, `week`, `month`, `quarter`, `year`).

Request:
```bash
curl "http://localhost:8080/index.php/apps/openregister/api/objects/5/24?_facets[@self][created][type]=date_histogram&_facets[@self][created][interval]=month" \
  -u admin:admin
```

Response:
```json
{
  "@self": {
    "created": {
      "type": "date_histogram",
      "config": {
        "interval": "month",
        "format": "F Y"
      },
      "buckets": [
        { "key": "2024-01", "results": 45, "label": "January 2024", "from": "2024-01-01", "to": "2024-01-31" },
        { "key": "2024-02", "results": 67, "label": "February 2024", "from": "2024-02-01", "to": "2024-02-29" },
        { "key": "2024-03", "results": 52, "label": "March 2024", "from": "2024-03-01", "to": "2024-03-31" }
      ]
    }
  }
}
```

The `from`/`to` fields on each bucket define the date boundaries, which the frontend uses to construct range query parameters (`property[>=]` / `property[<=]`).

### 3. Date Range

For time-based data with predefined ranges (e.g., "Last 7 days", "Last month"):

Request (configured via schema, no extra query params needed):
```bash
curl "http://localhost:8080/index.php/apps/openregister/api/objects/5/24?_facets[published_date][type]=date_range" \
  -u admin:admin
```

Response:
```json
{
  "published_date": {
    "type": "date_range",
    "buckets": [
      { "key": "last_7_days", "results": 12, "label": "Last 7 days", "from": "2026-03-06", "to": "2026-03-13" },
      { "key": "last_30_days", "results": 45, "label": "Last 30 days", "from": "2026-02-11", "to": "2026-03-13" },
      { "key": "last_90_days", "results": 89, "label": "Last 90 days", "from": "2025-12-14", "to": "2026-03-13" },
      { "key": "last_year", "results": 230, "label": "Last year", "from": "2025-03-13", "to": "2026-03-13" },
      { "key": "older", "results": 58, "label": "Older", "to": "2025-03-13" }
    ]
  }
}
```

### 4. Range Aggregation

For numeric data with custom buckets.

Request:
```bash
curl "http://localhost:8080/index.php/apps/openregister/api/objects/5/24?_facets[price][type]=range&_facets[price][ranges][0][to]=100&_facets[price][ranges][1][from]=100&_facets[price][ranges][1][to]=500&_facets[price][ranges][2][from]=500" \
  -u admin:admin
```

Response:
```json
{
  "price": {
    "type": "range",
    "buckets": [
      { "key": "0-100", "to": 100, "results": 120 },
      { "key": "100-500", "from": 100, "to": 500, "results": 80 },
      { "key": "500+", "from": 500, "results": 15 }
    ]
  }
}
```

## API Usage

### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `_facets` | object | Facet configuration — specifies which fields to facet and how |
| `_facetable` | boolean | When `true`, returns the list of available facetable fields (discovery) |
| `_limit` | integer | Use `_limit=0` for facet-only queries (no object results) |

### Facetable Field Discovery

Discover which fields are available for faceting:

```bash
curl "http://localhost:8080/index.php/apps/openregister/api/objects/5/24?_facetable=true&_limit=0" \
  -u admin:admin
```

Response:
```json
{
  "results": [],
  "total": 150,
  "facetable": {
    "@self": {
      "register": {
        "type": "categorical",
        "facet_types": ["terms"],
        "description": "Register that contains the object"
      },
      "schema": {
        "type": "categorical",
        "facet_types": ["terms"]
      },
      "created": {
        "type": "date",
        "facet_types": ["date_histogram", "range"],
        "intervals": ["day", "week", "month", "year"]
      },
      "updated": {
        "type": "date",
        "facet_types": ["date_histogram", "range"],
        "intervals": ["day", "week", "month", "year"]
      },
      "owner": {
        "type": "categorical",
        "facet_types": ["terms"]
      }
    },
    "object_fields": {
      "status": {
        "type": "terms",
        "title": "Status",
        "facetConfig": {
          "aggregated": true,
          "title": "Current Status",
          "description": null,
          "order": 1
        }
      },
      "category": {
        "type": "terms",
        "title": "Category",
        "facetConfig": {
          "aggregated": true,
          "title": null,
          "description": null,
          "order": null
        }
      }
    },
    "non_aggregated_fields": [
      {
        "field": "priority",
        "schemaId": 24,
        "facetType": "terms",
        "facetConfig": {
          "aggregated": false,
          "title": "Priority Level",
          "description": null,
          "order": 3
        },
        "title": "Priority"
      }
    ]
  }
}
```

The three sections:
- `@self` — Metadata fields always available (register, schema, created, updated, owner)
- `object_fields` — Aggregated facetable fields from schema properties
- `non_aggregated_fields` — Non-aggregated fields tracked with their schema context

### Requesting Facets

Request specific facets by field name and type:

```bash
curl "http://localhost:8080/index.php/apps/openregister/api/objects/5/24?_facets[@self][register][type]=terms&_facets[status][type]=terms&_facets[@self][created][type]=date_histogram&_facets[@self][created][interval]=month" \
  -u admin:admin
```

### Combined Search and Facets

Facets work alongside search filters and pagination:

```bash
curl "http://localhost:8080/index.php/apps/openregister/api/objects/5/24?status=active&_search=customer&_limit=25&_page=1&_facets[@self][register][type]=terms&_facets[status][type]=terms&_facets[priority][type]=terms" \
  -u admin:admin
```

Response includes both results and facets:
```json
{
  "results": ["..."],
  "total": 150,
  "page": 1,
  "pages": 6,
  "facets": {
    "@self": {
      "register": {
        "type": "terms",
        "buckets": [
          { "key": 5, "results": 120, "label": "Publications Register" },
          { "key": 6, "results": 30, "label": "Events Register" }
        ]
      }
    },
    "status": {
      "type": "terms",
      "buckets": [
        { "key": "active", "results": 134, "label": "active" },
        { "key": "inactive", "results": 16, "label": "inactive" }
      ]
    },
    "priority": {
      "type": "terms",
      "buckets": [
        { "key": "high", "results": 50, "label": "high" },
        { "key": "medium", "results": 70, "label": "medium" },
        { "key": "low", "results": 30, "label": "low" }
      ]
    }
  }
}
```

## Disjunctive Faceting

Each facet shows counts as if its own filter were not applied. This prevents facet options from disappearing when selected.

Example: if the user filters by `status=active`, the status facet still shows counts for `inactive` and `pending` — so the user can change their selection without losing options.

```bash
# User has selected status=active
curl "http://localhost:8080/index.php/apps/openregister/api/objects/5/24?status=active&_facets[status][type]=terms&_facets[priority][type]=terms" \
  -u admin:admin
```

- **Status facet**: Shows ALL statuses (not just active) — counts computed without the status filter
- **Priority facet**: Shows priorities filtered by `status=active` — other filters still apply

## Aggregated vs Non-Aggregated Facets

### Aggregated (`"aggregated": true`, default)

Values from all schemas sharing the same property name are combined into a single facet. This is useful for properties like `status` or `type` that appear across multiple schemas with the same meaning.

Example: If both "Product" and "Module" schemas have a `status` property with `"aggregated": true`, a single "Status" facet shows all status values from both schemas combined.

### Non-Aggregated (`"aggregated": false`)

The facet is computed per-schema. Non-aggregated fields are tracked separately in the `non_aggregated_fields` array with their schema context. Use this when the same property name has different meanings across schemas.

## Array Faceting

When a property of type `array` is marked as facetable, the system creates separate facet buckets for each array element:

```json
{
  "tags": {
    "type": "array",
    "items": { "type": "string" },
    "facetable": true
  }
}
```

Given objects:
```json
[
  { "id": 1, "tags": ["grocery", "pharmacy"] },
  { "id": 2, "tags": ["grocery", "electronics"] },
  { "id": 3, "tags": ["pharmacy"] }
]
```

Facet result:
```json
{
  "tags": {
    "type": "terms",
    "buckets": [
      { "key": "grocery", "results": 2 },
      { "key": "pharmacy", "results": 2 },
      { "key": "electronics", "results": 1 }
    ]
  }
}
```

## UUID Label Resolution

The faceting system automatically resolves UUIDs in facet buckets to human-readable names.

**Process:**
1. Detects bucket values containing hyphens (UUID format)
2. Batch-loads all UUIDs in a single database query
3. Checks in-memory cache, then distributed cache, then database
4. Extracts names from common fields (`naam`, `name`, `title`, `contractNummer`, `achternaam`)
5. Sorts facet buckets alphabetically by resolved label (A-Z)
6. Falls back to the UUID if no name can be resolved

Before resolution:
```json
{
  "customer": {
    "buckets": [
      { "key": "f47ac10b-58cc-4372-a567-0e02b2c3d479", "results": 42, "label": "f47ac10b-58cc-4372-a567-0e02b2c3d479" }
    ]
  }
}
```

After resolution:
```json
{
  "customer": {
    "buckets": [
      { "key": "f47ac10b-58cc-4372-a567-0e02b2c3d479", "results": 42, "label": "Acme Corporation" }
    ]
  }
}
```

**Performance:**
- Cached UUIDs: <10ms
- Uncached batch (100 UUIDs): <100ms
- Service is lazy-loaded — only initialized when UUIDs are detected

## Performance

### Response Time Impact

| Scenario | Overhead |
|----------|----------|
| Search only (no faceting) | Baseline |
| With facets (`_facets`) | ~10ms |
| With discovery (`_facetable=true`) | ~15ms |
| Combined facets + discovery | ~25ms |
| With UUID resolution (100 UUIDs, cached) | ~10ms |
| With UUID resolution (100 UUIDs, uncached) | ~100ms |

### Optimizations

- **Schema-based discovery**: No object data analysis required — instant field detection from schema definitions
- **Pre-computed facets**: The `facets` column on the `openregister_schemas` table stores pre-computed facet configurations, eliminating ~15ms runtime analysis per request
- **Database-level aggregations**: Uses SQL `GROUP BY` for efficient counting
- **Indexed metadata fields**: `deleted`, `published`, `created`, `updated`, `organisation`, `owner`
- **Batch UUID resolution**: Single query for all UUIDs (no N+1 problem)
- **Multi-tier caching**: In-memory → distributed cache → database
- **Disjunctive optimization**: Excludes only the relevant filter per facet query
- **`_limit=0`**: Use for facet-only queries to skip fetching objects

### Fallback Strategy

When faceting on a filtered dataset returns empty results (due to restrictive filters), the system automatically falls back to collection-wide facets so the user still sees useful filter options.

## Metadata Facets (@self)

In addition to object property facets, every search automatically supports metadata facets under the `@self` namespace:

| Field | Type | Description |
|-------|------|-------------|
| `@self.register` | terms | Register that contains the object |
| `@self.schema` | terms | Schema the object belongs to |
| `@self.owner` | terms | User who created the object |
| `@self.organisation` | terms | Organisation the object belongs to |
| `@self.created` | date_histogram | Object creation date |
| `@self.updated` | date_histogram | Last modification date |

Metadata facets automatically resolve IDs to human-readable labels (register titles, schema names, etc.).

## Troubleshooting

### No Facets Appear

1. Check that the schema property has `"facetable": true` or a facetable object
2. Verify the register and schema are selected in the query
3. Ensure the schema contains at least one facetable property
4. Check that objects exist for the schema

### Facets Show No Data

1. Verify object data contains the facetable fields
2. Check that property names match between schema definition and stored objects
3. Ensure the property has a reasonable number of distinct values

### Labels Showing as IDs

1. For metadata fields (`register`, `schema`, `organisation`): verify the entities exist and have `title`/`name` properties set
2. For object fields with UUIDs: check that referenced objects exist and have name fields (`naam`, `name`, `title`)
3. Review logs for label resolution errors

### Performance Issues

1. Ensure database indexes exist on `deleted`, `published`, `created`, `updated`, `organisation`, `owner`
2. Limit the number of facet fields to essentials
3. Use `_limit=0` for facet-only queries
4. Check cardinality — high-cardinality fields (thousands of unique values) are slow and unhelpful as facets

## Best Practices

1. **Be selective** — Only make properties facetable if they have a manageable number of distinct values and are useful for filtering
2. **Use clear titles** — Set human-readable `title` values so users understand each filter
3. **Order thoughtfully** — Use the `order` field to put the most frequently used facets first
4. **Use aggregation wisely** — Enable `"aggregated": true` only when the same property name truly represents the same concept across schemas
5. **Test after configuring** — Verify facets appear and work correctly in the search interface
6. **Prefer schema-based discovery** — Marking fields as facetable in schemas is faster and more predictable than runtime object analysis
