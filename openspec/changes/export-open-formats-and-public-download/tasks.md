# Tasks: export-open-formats-and-public-download

## 1. Formats

- [ ] 1.1 Add `ExportService::exportToTsv()` and the `tsv` branch in `ObjectsController::export()` (tab delimiter, quoting as design D-1, UTF-8 with BOM, `text/tab-separated-values`). Verify: `tests/Unit/Service/ExportServiceTsvTest.php` round-trips a value with a tab, a line break and a quote through PhpSpreadsheet's reader.
- [ ] 1.2 Add `ExportService::exportToXml()` with `XMLWriter` and the `xml` branch, implementing the existing scenario "Export to XML" plus the `<property name="...">` fallback for invalid names and `xsi:nil` for null. Verify: `tests/Unit/Service/ExportServiceXmlTest.php` validates the output with `DOMDocument::loadXML()`, covers arrays, nested objects, an `@self` block and a property named `2e-adres`.

## 2. Public download

- [ ] 2.1 Add `ExportRightService::refusalForAnonymous()` (effective export grant must include `public`, else 401 `not-public`), make the route `@PublicPage` with `#[AnonRateLimit(limit: 10, period: 60)]` and an explicit `#[UserRateLimit]`, and answer 406 to an anonymous `pdf` or Excel request. Verify: `tests/Unit/Service/Export/ExportRightServiceAnonymousTest.php` covers an explicit `export: ["public"]`, a read fallback with `public`, and a schema without it; hydra gates route-auth, semantic-auth and no-admin-idor pass.
- [ ] 2.2 Cap an anonymous read at 10,000 rows with `_page`, and send `X-Total-Count`, `X-Page`, `X-Pages` and `Link rel="next"` (design D-4); record the anonymous export in the audit trail (D-5). Verify: `tests/Unit/Service/ExportServiceAnonymousPagingTest.php`; a Newman collection `tests/newman/openregister-public-export.postman_collection.json` asserts 200 with the headers for a public schema, 401 for a private one and 406 for `format=pdf`.

## 3. Dialog

- [ ] 3.1 Offer CSV, TSV, JSON, XML and Excel in `src/modals/register/ExportRegister.vue` and derive the fallback file name from the chosen format. Verify: `src/modals/register/ExportRegister.spec.js` asserts the five options and the `.tsv` and `.xml` fallback names.

## 4. Docs and end-to-end test

- [ ] 4.1 Document the formats, the public download rule, the row cap and paging headers, and the release-note behaviour change in `docs/features/data-import-export.md`, with curl examples for an anonymous TSV and XML download. Verify: `npm run build` in `docs/` succeeds.
- [ ] 4.2 Add `tests/e2e/ci/public-export.spec.ts`: an anonymous request downloads a public schema filtered on one field as TSV and as XML and gets only public rows and columns; the same request on a private schema gets 401; a signed-in user picks XML in the export dialog and gets a `.xml` file. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- No anonymous export contains a row or a column the public principal cannot read through the list endpoint.
- A signed-in export in the existing formats is byte-for-byte what it is today.
