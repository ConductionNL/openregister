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
| TSV | `ExportService.exportToTsv()` | Tab-separated, UTF-8 with BOM; a value holding a tab, line break or quote is quoted |
| Excel (XLSX) | `ExportService.exportToExcel()` | Multi-schema export as multiple sheets |
| JSON | `ExportService.exportToJson()` | JSON array export |
| XML | `ExportService.exportToXml()` | Root `<objects>`, one `<object id="...">` per record; an invalid element name becomes `<property name="...">`, null is `xsi:nil="true"` |
| PDF | `ExportService.exportToPdf()` | Rendered table, row-limited |
| JSONL | Planned | Newline-delimited JSON for streaming |

The export dialog on the register page offers Excel, CSV, TSV, JSON and XML.

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

### Public download

The export route is reachable without an account. A visitor may export a
schema only when its effective **export** grant names `public`: the schema's
`export` list when it declares one, otherwise its `read` list.

| Situation | Answer |
|-----------|--------|
| The export grant names `public` | 200 with the file |
| It does not (for example `read: ["public"]` with `export: ["redactie"]`) | 401, rule `not-public` |
| `format` is `pdf`, `excel` or `ods` | 406, naming `csv`, `tsv`, `json` and `xml` |

An anonymous export holds only the rows and columns the public may read through
the list endpoint, without the administration `@self` columns. Every anonymous
export, served or refused, is written to the audit trail with actor `anonymous`,
its format and its row count.

**Paging.** An anonymous export returns at most 10,000 rows per response and
honours `_page` (default 1). The response carries:

| Header | Value |
|--------|-------|
| `X-Total-Count` | rows matching the export over all pages |
| `X-Page` | this page |
| `X-Pages` | the number of pages |
| `Link` | `<...&_page=N+1>; rel="next"`, only while a later page exists |

Anonymous callers are limited to 10 exports per minute per address. A signed-in
export keeps its current behaviour: unpaged, no paging headers.

```bash
# A public schema as TSV, filtered on one field
curl -OJ 'https://example.org/index.php/apps/openregister/api/objects/publicaties/besluit/export?format=tsv&thema=milieu'

# The same as XML, second page
curl -OJ 'https://example.org/index.php/apps/openregister/api/objects/publicaties/besluit/export?format=xml&_page=2'

# Read the paging headers only
curl -sD - -o /dev/null 'https://example.org/index.php/apps/openregister/api/objects/publicaties/besluit/export?format=csv'
```

> **Release note.** On an instance that ran the repair step granting `export`
> wherever `read` was granted, every schema whose `read` included `public` now
> has `public` in `export` too, and so becomes downloadable without an account.
> To keep a schema out of the public download, remove `public` from its
> `export` list.

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

## Seeding from a Mounted Directory

An installation can be seeded at start-up from the operator's own data files, without anyone opening the admin. Name the directory in Nextcloud's `config.php`, typically a volume mounted into the container:

```php
'openregister.seed_directory' => '/seed',
```

Every `*.json` file directly in that directory (not in sub-directories) is imported in lexical order, so `10-registers.json` imports before `20-objects.json`. Each file is a configuration in the same format the configuration import takes (`components.registers`, `components.schemas`, and objects under `components.objects` or a top-level `objects`), and it goes through the same import. Writes carry the audit cause `seed`.

The import runs on three occasions:

- on install and after every upgrade (a repair step);
- every five minutes from a background job, but only when the SHA-256 over the directory's file names and contents changed since the last run;
- on demand with `occ openregister:seed:import`, which exits `0` on success, `2` on a partial run and `1` on a failed run or when no directory is configured.

Imports are idempotent: registers, schemas and objects are matched by slug or uuid and updated or skipped, never duplicated. A register or schema is updated when its `version` is raised, as in any configuration import; seed objects follow their file's content.

Safety: a file that resolves outside the directory (a symlink leading out) or is larger than 50 MB is refused and named without being read. A file that does not parse is named and the other files still import. A relative or unreadable directory is reported and nothing is imported.

### The seed report

Every run stores a report: the directory, its digest, the moment, and per file the counts of imported, skipped and failed registers, schemas and objects, with each failure named (file, entity, slug, reason). The run is `success` only when nothing failed, `failed` when something failed and nothing imported (or the directory could not be read), and `partial` otherwise. The last report is returned by `GET /api/settings/seed` (administrators only), written to the log and printed by the occ command.

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
