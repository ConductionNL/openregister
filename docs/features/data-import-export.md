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
| PDF | `ExportService.exportToPdf()` | Rendered table, row-limited; carries the organisation's house style (see below) |
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

### House Style in PDF Exports

A PDF export carries the organisation's house style when the **thematiq** app is
installed and enabled. Set it once in thematiq's admin settings, in the
**Documents** block; every PDF the instance generates then uses it:

- **Logo** — the document logo (or, without one, the active token set's logo)
  is drawn at the left of the header on every page. It is read in-process and
  embedded in the file: never fetched over the network. Only PNG, JPEG and SVG
  up to 1 MB are used, and an SVG with scripts, event handlers or references
  outside itself is left out.
- **Table header** — coloured with the token set's primary colour and primary
  text colour.
- **Footer** — the organisation name, the document footer line and the
  accessibility and privacy statement links, on every page above the page
  number.

The house style is read for the requesting user, so a group-mapped token set
applies; an export without a user uses the instance default. Without thematiq,
or when the house style cannot be read, the export has the plain layout and
still succeeds. Custom fonts are not applied yet: the export keeps its default
font.

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

## Encrypted Instance Sets

An instance set, and the restorable copy written before a destruction, can be encrypted so that it opens only where its key is present. Nextcloud's own encryption is not used: it is keyed off the instance secret, and a set must open on another instance.

### The format

```
ORSETENC1\n                    magic and format version
{header JSON}\n                cleartext, authenticated
[secretstream header, 24 bytes]
[chunk][chunk]...[final chunk] 64 KiB of plaintext each
```

Each chunk is sealed with XChaCha20-Poly1305 (libsodium's `secretstream`, from PHP's `ext-sodium`, which Open Register requires). The last chunk carries the final tag, so a truncated file is refused as truncated and is never read as a shorter set. The header says only what a reader needs to start: `version`, `cipher` (`xchacha20poly1305-secretstream`), `chunkSize`, and either the passphrase parameters (`kdf: argon2id13`, `opslimit`, `memlimit`, `salt`) or the export key's fingerprint (`keyFingerprint`, the first 16 hex characters of the key's BLAKE2b hash). It names no instance, no counts and no dates, because anyone holding the file can read it. The header is authenticated with the first chunk, so a changed parameter or fingerprint makes the set fail.

A set is checked to its final tag before anything is written from it. An altered file, a truncated file and a wrong key all give the same answer, "this file was changed or the key is wrong", naming no offset.

### Two kinds of key

- **Passphrase.** At least 12 characters, typed for one export or load, stretched with Argon2id at the moderate limits into a 32-byte key, and never stored. A background job never sees it: the request derives the key and hands the job a one-use key that is deleted when the job ends, and swept after 24 hours if the job died.
- **Export key.** A random 32-byte key of one organisation, kept in the credential broker as an inject-only credential under the provider `openregister-export-key`. Only that organisation can use it.

### Managing export keys

Administrators manage the export keys of their active organisation in **Administration settings → Open Register → Export keys**, or through the API:

| Route | What it does |
| --- | --- |
| `GET /api/export-keys` | The organisation's keys, newest first, as `{id, fingerprint, createdAt}` |
| `POST /api/export-keys` | Create a key; `201` with `{id, fingerprint, recovery}` |
| `POST /api/export-keys/{id}/rotate` | A new key for new sets; the old one stays for loading old sets. `201` with the new key's `recovery` and `previousId` |
| `DELETE /api/export-keys/{id}` | Delete a key; sets encrypted with it can no longer be opened here |

The routes are administrator-only and answer `409` when the caller has no active organisation. A key that is not in the organisation's own list answers `404`, whether it does not exist or belongs to another organisation. Creating, rotating, deleting and each use of a key write an audit row (`export.key-created`, `export.key-rotated`, `export.key-deleted`, `export.key-used`) that names the key's fingerprint and never the key.

### Lost keys cannot be recovered

There is no escrow. The recovery string (the key, base64url) is shown once, when the key is created or rotated, with `Cache-Control: no-store`; the list never carries it, and a reload shows the key by fingerprint and date only. Store it somewhere else: a set encrypted with a key whose recovery string is lost opens only on the instance that still holds the key, and a set encrypted with a forgotten passphrase does not open at all.

### Encrypting the copy before a destruction

The archival setting `destructionCopyEncryption` decides whether the copy written before a destruction is encrypted:

| Value | Effect |
| --- | --- |
| `off` (default) | The copy is written as `<uuid>.json`, as before |
| `when-key` | Encrypted with the organisation's newest usable export key when it has one, plain otherwise |
| `required` | Encrypted, as `<uuid>.json.orsetenc`; without a usable export key nothing is destroyed and the run names the organisation whose key is missing |

An unknown value reads as `required`. An encrypted copy is never also written in the clear, and the recorded destruction carries `copyKeyFingerprint`.

### Field-encrypted values

A property encrypted at field level is stored in an envelope only the source instance can open. A set carries such a value only inside encryption: an encrypted set holds the plaintext and the receiving instance re-encrypts it with its own key on load, and a plain set leaves the value out and lists the schema and property it left out. This applies to the instance set serialisation and load, which are being built under `import-preview-and-conflict-policy`.

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
