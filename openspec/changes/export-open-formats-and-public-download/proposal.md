---
kind: code
---

# Proposal: export-open-formats-and-public-download

## Summary

A visitor to an open data catalogue can download the published rows of a schema, filtered to what they need, as CSV, TSV, JSON or XML, without an account. They get exactly what the schema lets the public read, and nothing an administrator has not granted for export. A signed-in user gets the same new formats in the export dialog. A public download is bounded: at most 10,000 rows per file, paged, and rate limited, so an open catalogue stays up when a crawler finds it.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| opencatalogi | od-table-download | Download the rows of a published table, filtered to what you need, as CSV, TSV, JSON or XML. | partial |

**od-table-download** (row in opencatalogi's matrix, owned here because built.owner is ConductionNL/openregister)

- Demand: changelog, https://github.com/ckan/ckan/pull/9027 (the row's origin).
- Competitor yes cells:
  - ckan (CKAN), no evidence URL, source path cited: "source read at ckan-2.12.0: kept from the mining reader: /datastore/dump/<resource_id> takes format csv, tsv, json or xml plus filters and q (ckanext/datastore/blueprint.py:40,45-52), streamed with keyset pagination per CHANGELOG.rst:61-71 (#9027)."
  - dkan (DKAN), no evidence URL, source path cited: "source read at 4.1.3: kept from the mining reader: /api/1/datastore/query/{dataset}/{index}/download (modules/dkan_datastore/dkan_datastore.routing.yml:112-120) streams the query result, conditions applied, as CSV (modules/dkan_datastore/src/Controller/QueryDownloadController.php:134-140) or JSON (:175-181). CSV and JSON only, no TSV or XML; kept at yes because the sentence reads the formats as alternatives."

## Why

The export exists for signed-in users and in three of the four formats the row names.

- `GET /api/objects/{register}/{schema}/export` (`appinfo/routes.php:1175`) is `ObjectsController::export()` (`lib/Controller/ObjectsController.php:5456-5610`). It takes `format` (or `type`) and the same filters as the list, and produces `csv`, `json`, `pdf`, or Excel by default. There is no `tsv` and no `xml` branch.
- XML is already a requirement: `data-import-export` "The system MUST support structured export to CSV, Excel (XLSX), JSON, XML, and ODS formats", scenario "Export to XML" (`openspec/specs/data-import-export/spec.md:201` and `:227-232`). It is specified and not built; this change builds it and does not specify it again. TSV is specified nowhere.
- The route is `@NoAdminRequired` and not `@PublicPage` (`lib/Controller/ObjectsController.php:5442-5444`), and the export right refuses a caller without a session with 401 `not-authenticated` (`lib/Service/Export/ExportRightService.php:94-105`). A public reader can read published records as JSON through opencatalogi's publication endpoints, page by page, but cannot download a file.
- The export dialog offers Excel and CSV only (`src/modals/register/ExportRegister.vue:89-92`), although the server already produces JSON.
- `ExportService::fetchObjectsForExport()` reads with `_limit` 999999 (`lib/Service/ExportService.php:824-828`). That is tolerable behind a login and not in front of the internet.

## What changes

- Two formats on the export route: `tsv` (tab-separated, `text/tab-separated-values`) as a new requirement, and `xml` as the existing requirement's first implementation.
- The route becomes reachable without a session. An anonymous caller may export a schema only when its export grant includes the `public` principal: the explicit `export` list, or `read` where the schema declares no `export` key, as `ExportRightService` already resolves for signed-in users.
- An anonymous export reads as the public principal: public rows only, property-level RBAC as an anonymous reader, no `@self` administration columns, no PDF or Excel.
- An anonymous export is capped at 10,000 rows per file, paged with `_page`, and rate limited per address. The response says how many rows match and which page this is.
- The export dialog offers CSV, TSV, JSON, XML and Excel.
- A public export writes the same `export completed` audit row a signed-in export does, with the actor recorded as anonymous.

## Consumers

- opencatalogi: a publication page links "Download as CSV, TSV, JSON or XML" to this route for a schema the catalogue publishes.
- Every app whose schemas grant `public` read (opencatalogi's publication register, ORI, and others) gets the download for free; each administrator decides by the `export` grant.

## ADRs

- openregister ADR-006 (publish is an RBAC scope): "public" is a grant in the schema's authorization, never a data field; the download follows the grant.
- hydra ADR-082 (public endpoint throttling) and ADR-054 (public surface hardening): an anonymous rate limit and a row cap on the now-public route.
- hydra ADR-108 (public surface placement): the public route stays Open Register's object export; opencatalogi links to it and adds no second export.
- hydra ADR-058 (bounded object queries): the anonymous read is paged at 10,000.
- openregister ADR-003 (immutable audit trail): every export, anonymous included, is an audit row.

## Impact

- Extends the capability `data-import-export`.
- Affected code: `lib/Controller/ObjectsController.php` (`export()`, its attributes), `lib/Service/ExportService.php` (`exportToTsv()`, `exportToXml()`, the anonymous cap and paging), `lib/Service/Export/ExportRightService.php` (an anonymous check), `src/modals/register/ExportRegister.vue`.
- Behaviour change to name in the release note: on an instance upgraded through `GrantExportWhereReadIsGranted` (`lib/Repair/GrantExportWhereReadIsGranted.php:124-156`), a schema that grants `read` to `public` already carries `export: [..., "public"]`, so its public download switches on with this change. An administrator who wants the data browsable but not downloadable removes `public` from `export`.
- Otherwise backwards compatible: signed-in exports behave as today, with two more formats.
- Size: M.

## Out of scope

- Parsing a tabular file attached to a publication into rows. That is opencatalogi's.
- ODS export, which the same existing requirement lists; it is a separate task for the owner of that requirement.
- PDF and Excel for anonymous callers. They are rendered documents, not open data formats, and they cost the most to build.
