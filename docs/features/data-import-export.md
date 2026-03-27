# Data Import & Export

## Overview

OpenRegister provides a comprehensive import/export pipeline for bulk data operations and configuration portability. The core pipeline handles CSV, Excel (XLSX), JSON, and XML data files, as well as register configuration export/import in OpenAPI 3.0.0 format. All operations are RBAC-aware and include deduplication, error reporting, and Solr index warmup after large imports.

## Supported Formats

### Data Import

| Format | Handler | Notes |
|--------|---------|-------|
| CSV | `ImportService.importFromCsv()` via PhpSpreadsheet | Auto-detects delimiter; BOM-safe |
| Excel (XLSX) | `ImportService.importFromExcel()` via PhpSpreadsheet | Multi-sheet support (sheet name → schema slug) |
| JSON | `ImportService.importFromJson()` | Expects JSON array of objects |
| XML | `ImportService.importFromXml()` | Each child element of the root = one record |
| ODS | Planned | OpenDocument Spreadsheet |

### Data Export

| Format | Handler | Notes |
|--------|---------|-------|
| CSV | `ExportService.exportToCsv()` | RBAC-aware column selection |
| Excel (XLSX) | `ExportService.exportToExcel()` | Multi-schema export as multiple sheets |
| JSON | Planned | JSON array export |
| XML | Planned | XML export with configurable root element |
| JSONL | Planned | Newline-delimited JSON for streaming |

### Configuration Export/Import

| Format | Handler | Notes |
|--------|---------|-------|
| OpenAPI 3.0.0 (YAML/JSON) | `Configuration/ExportHandler` and `Configuration/ImportHandler` | Full register + schemas + hooks + authorization |

## Import Pipeline

### Single-Schema Import

```
POST /api/objects/{register}/{schema}/import
Content-Type: multipart/form-data

file=@data.csv
```

The import pipeline:

1. Detects file type from extension
2. Reads all rows via PhpSpreadsheet (CSV/XLSX) or JSON/XML parser
3. Runs schema validation on each row
4. Calls `SaveObjects` with `ChunkProcessingHandler` (chunks of configurable size, default 100)
5. Returns a summary with `found`, `created`, `updated`, `unchanged`, and `errors` counts

### Multi-Schema Excel Import

When an Excel file contains multiple sheets:

- `processMultiSchemaSpreadsheetAsync()` maps each sheet title to a schema slug
- Each sheet is processed as a separate schema import
- Errors per sheet are reported independently

### Deduplication

The import pipeline supports deduplication:

- If an object with matching primary key (configurable, defaults to `_uuid`) already exists, it is updated rather than created
- The `unchanged` count in the summary reports rows where the existing data already matched the import data
- Deduplication efficiency is reported as a percentage

### Column Mapping

Column headers in the source file are matched to schema properties:

- Exact match (case-insensitive) to property name
- `@self.*` columns are admin-only: `@self.uuid`, `@self.created`, `@self.updated`, `@self.owner`
- Unmapped columns are ignored with a warning in the import summary

### Bulk Performance

`ChunkProcessingHandler` provides significant performance improvements over individual saves:

- 60-70% fewer database calls (batch inserts/updates per chunk)
- 2-3x faster throughput for large datasets
- Schema validation analysis cached across all objects in a chunk via `BulkValidationHandler`
- Inverse relation resolution batched via `BulkRelationHandler`

## Export Pipeline

### Column Selection

Exported columns are determined by:

1. Schema property definitions (only defined properties are exported)
2. RBAC: `PropertyRbacHandler.canReadProperty()` filters out properties the requesting user cannot read
3. Relation names: `resolveUuidNameMap()` performs a two-pass resolution to replace UUID values with human-readable names in relational columns
4. Admin-only `@self.*` metadata columns are included only for admins

```
GET /api/objects/{register}/{schema}/export?format=xlsx&_search=actief
```

Export respects all query parameters (filtering, sorting) — you export exactly what you see in the UI.

### Configuration Export

Export a complete register configuration (schemas, hooks, RBAC, etc.) for environment promotion:

```
GET /api/registers/{id}/export
```

Produces an OpenAPI 3.0.0 YAML/JSON with:

- Register metadata
- All schema definitions with properties, types, and authorization
- Hook configurations
- Workflow deployment specifications
- Slug-based references (portable across environments)

### Configuration Import

```
POST /api/registers/import
Content-Type: multipart/form-data

file=@register-config.yaml
```

Idempotent: importing the same configuration twice produces the same result (no duplicates).

## Import Summary Response

```json
{
  "summary": {
    "found": 500,
    "created": 312,
    "updated": 145,
    "unchanged": 38,
    "errors": 5,
    "deduplication_efficiency": "37.6%"
  },
  "errors": [
    { "row": 42, "field": "postcode", "error": "Does not match pattern ^[0-9]{4}[A-Z]{2}$" }
  ]
}
```

## Solr Index Warmup

After a large import, `ImportService.scheduleSmartSolrWarmup()` schedules a background job via `IJobList` to reindex the imported objects in Solr. This ensures search results are immediately accurate after bulk loads.

## Standards

| Standard | Role |
|----------|------|
| OpenAPI 3.0.0 | Configuration export/import format |
| CSV (RFC 4180) | Comma-separated value interchange |
| OOXML (ECMA-376) | Excel XLSX format |
| JSON Schema | Validation during import |

## Related Features

- [Registers & Schemas](registers-and-schemas.md) — configuration import/export
- [Object Storage & Lifecycle](object-storage.md) — objects created/updated during import
- [Access Control (RBAC)](access-control.md) — column visibility on export
- [Search, Filtering & Faceting](search-and-faceting.md) — search filters apply to export
- [Workflow Automation](workflow-automation.md) — import-time workflow triggers
