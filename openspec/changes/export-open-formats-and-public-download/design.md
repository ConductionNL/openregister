# Design: export-open-formats-and-public-download

Read at openregister development c53dd0685c.

## D-1: two formats on the route that exists

`ObjectsController::export()` (`lib/Controller/ObjectsController.php:5456-5610`) branches on `format` (falling back to `type`, default `excel`): `csv` at `:5504`, `json` at `:5526`, `pdf` at `:5547`, then Excel. Two branches are added before the Excel default:

- **`tsv`**: `ExportService::exportToTsv()` builds the same spreadsheet `exportToCsv()` builds (`lib/Service/ExportService.php:247-268`) and writes it with PhpSpreadsheet's `Csv` writer set to a tab delimiter. A value containing a tab, a line break or a double quote is quoted, the same rule Python's `excel-tab` dialect and CKAN's TSV follow, so any spreadsheet opens it. UTF-8 with a byte order mark, like the CSV. Served as `text/tab-separated-values; charset=utf-8`, file `{register}_{schema}_{datetime}.tsv`.
- **`xml`**: `ExportService::exportToXml()` implements the existing requirement's scenario (`openspec/specs/data-import-export/spec.md:227-232`): a root `<objects>`, one `<object>` per record with `id` as an attribute, each property a child element named after the property, arrays as repeated children, nested objects as nested elements, `null` as an empty element with `xsi:nil="true"`. A property name that is not a valid XML name (it starts with a digit, holds a space, or is `@self`) becomes `<property name="...">` so no data is dropped and the document stays well formed. Built with `XMLWriter` streaming into memory rather than `DOMDocument`, so 10,000 rows do not hold two copies. Served as `application/xml; charset=utf-8`.

`exportToXml()` and `exportToTsv()` read rows through `fetchObjectsForExport()` (`:788`), so every filter, sort, `_columns` selection and the property-level RBAC that the other formats honour applies to them unchanged.

## D-2: who may export without a session

The route gets `@PublicPage` and `#[AnonRateLimit(limit: 10, period: 60)]` beside a `#[UserRateLimit]` at today's effective ceiling, so a signed-in caller is not throttled by the anonymous limit (the pitfall `create()` documents at `:3235-3246`).

`ExportRightService::refusalFor()` (`lib/Service/Export/ExportRightService.php:94-105`) refuses a caller without a session with 401. It gains `refusalForAnonymous(Schema $schema)`: the anonymous caller may export when the schema's effective export grant includes the `public` principal. The effective grant is resolved exactly as for a signed-in user: the `export` list when the schema declares one, otherwise the `read` list (`:139-144`, `FALLBACK_ACTION`). Anything else answers 401 with rule `not-public`, so a reader learns they need an account, not that the schema exists privately.

On an instance that ran `GrantExportWhereReadIsGranted` (`lib/Repair/GrantExportWhereReadIsGranted.php:124-156`), every schema whose `read` included `public` now carries `export` with `public` in it. Those schemas become downloadable by the public with this change. That is the row's intent, "what the schema lets them read", and the release note names it with the one-line way to narrow it: remove `public` from `export`.

## D-3: what an anonymous export contains

`fetchObjectsForExport()` passes the caller's session to `ObjectService::searchObjects()`. For an anonymous caller that means:

- rows: only those the `public` principal may read, the same set the public list endpoint returns (`MagicRbacHandler` evaluates `public` at `lib/Db/MagicMapper/MagicRbacHandler.php:783-786`);
- columns: property-level RBAC as an anonymous reader, so a property restricted to a group is not a column;
- no `@self` administration columns, which `getHeaders()` already shows to administrators only;
- formats: `csv`, `tsv`, `json`, `xml`. `pdf` and Excel answer 406 for an anonymous caller, naming the four open formats.

## D-4: bounded for the internet

Today the export reads with `_limit` 999999 (`lib/Service/ExportService.php:824-828`). For an anonymous caller `fetchObjectsForExport()` reads at most 10,000 rows, honouring `_page` (default 1). The response carries `X-Total-Count`, `X-Page` and `X-Pages` headers, and a `Link` header with `rel="next"` while more pages exist, so a harvester can walk the set. A signed-in export keeps today's behaviour; bounding it is the job of the existing streaming requirement ("Export MUST support streaming for large datasets", `openspec/specs/data-import-export/spec.md:288`), not this change.

## D-5: audit

`recordExportCompleted()` (`lib/Controller/ObjectsController.php:5708`) already writes an audit row per completed export with register, schema, format and row count. For an anonymous export the row's user is `anonymous`, and the format and page are recorded. The client address is recorded as the audit trail already records it for other anonymous writes. No row content is logged.

## D-6: the dialog

`src/modals/register/ExportRegister.vue` offers Excel and CSV (`:89-92`) and names the downloaded file `.xlsx` or `.csv` when no `Content-Disposition` arrives (`:166`). It gains TSV, JSON and XML, and the fallback file name follows the chosen format.

## Risks

- **Exposure.** Only schemas whose export grant names `public` are downloadable, and only their public rows and columns. The behaviour change in D-2 is named in the release note.
- **Load.** Ten anonymous exports a minute per address and 10,000 rows per file. `operate-load-shedding`, when it lands, sheds exports first under database pressure because the route is marked sheddable there.
- **XML safety.** Values are written through `XMLWriter`, which escapes them; no entity or doctype is emitted, so the file is safe for a consumer with a naive parser.
