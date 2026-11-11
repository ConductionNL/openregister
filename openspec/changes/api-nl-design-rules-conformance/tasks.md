# Tasks: api-nl-design-rules-conformance

## 1. Rule catalogue

- [ ] 1.1 Add `lib/Service/Oas/NlGovRuleCatalogue.php` with the 34 rules of ruleset 2.2.1 (id, title, kind, deviation reason) and move `ALLOWED_HTTP_METHODS` and `ALLOWED_STATUS_CODES` out of `OasService` behind it. Verify: `tests/Unit/Service/Oas/NlGovRuleCatalogueTest.php` asserts 34 unique ids, a kind on every rule, and a reason on exactly the deviations named in design D-4.

## 2. Document checks in pass 6

- [ ] 2.1 Add `CODE_NLGOV_RULE` and a `rule` field to `OasValidationReport` issues, keeping the existing codes. Verify: `OasValidationReportTest` asserts `toSummary()` still returns the old keys and each issue now carries `rule` when set.
- [ ] 2.2 Path and query rules in `validateNlGovRules()`: no-trailing-slash, path-segments-kebab-case, query-keys-camel-case, uri-version. Verify: `tests/Unit/Service/OasServiceNlGovPathRulesTest.php` with a fixture path `/objects/foo/` failing and `_extend` reported as a deviation.
- [ ] 2.3 Schema, error and document rules: date-time/format, date-time/date-omit-time-portion, error-handling/problem-details, error-handling/invalid-input, doc-openapi, doc-openapi-contact, semver (through `SemVerFormat`), publish-openapi, transport/tls; set `info.version` in `BaseOas.json` to `1.0.0`. Verify: `tests/Unit/Service/OasServiceNlGovDocumentRulesTest.php`, including the 20-schema fixture staying under the 2 second budget.
- [ ] 2.4 Write the `x-nl-api-design-rules` root marker in `createOas()`. Verify: `GET /api/registers/{id}/oas` returns 200 and the body has `x-nl-api-design-rules.version` `2.2.1`; the ETag is unchanged between two calls with no schema change.

## 3. Response probe

- [ ] 3.1 Add `lib/Service/Oas/NlGovResponseProbe.php` (at most five self-requests, 10 second timeout each, as the calling administrator) for version-header, transport/security-headers, date-time/timezone and problem-details at response level; store the result in `IAppConfig` under `nlgov_probe_{registerId}`. Verify: `tests/Unit/Service/Oas/NlGovResponseProbeTest.php` with a mocked `IClientService` asserts the request cap and that a digits-only `API-Version` fails the rule.
- [ ] 3.2 Add the occ command `openregister:api:conformance {register} [--probe]` printing the report as a table or `--output=json`. Verify: `tests/Unit/Command/ApiConformanceCommandTest.php` asserts exit 0 and one line per rule.

## 4. Report API

- [ ] 4.1 Add `OasController::conformance()`, `conformanceAll()` and `probe()` with routes `GET /api/registers/{id}/oas/conformance`, `GET /api/registers/oas/conformance` and `POST /api/registers/{id}/oas/conformance/probe`; the two reads are `#[PublicPage]` with `#[AnonRateLimit(limit: 30, period: 60)]`, the probe is administrator-only. Verify: `tests/Unit/Controller/OasControllerConformanceTest.php`; a Newman request in `tests/newman/` asserts 200 anonymous on the read and 403 for a non-admin on the probe; hydra gates route-auth and route-reachability pass.

## 5. Register list dialog

- [ ] 5.1 Add `src/dialogs/register/NlGovConformanceDialog.vue` (NcDialog) and a "Check Dutch API design rules" row action in `src/views/register/RegistersIndex.vue`; the "Probe responses" button shows only for administrators. Verify: `src/dialogs/register/NlGovConformanceDialog.spec.js` renders a report fixture grouped by result.

## 6. CI lint

- [ ] 6.1 Vendor the official ruleset as `tests/oas/adr-ruleset-2.2.1.yaml`, extend it from `.spectral.yml` with the D-4 deviations turned off by name, replace the broken `validate-oas` script in `package.json`, and add a lint step to `.github/workflows/api-test-coverage.yml` after the instance boots. Verify: the step fails on a branch that adds a trailing-slash path and passes on development.

## 7. Docs and end-to-end test

- [ ] 7.1 Document the report, the marker, the probe, the occ command and each declared deviation with its reason in `docs/features/api-generation.md`. Verify: `npm run build` in `docs/` succeeds and the page names ruleset 2.2.1.
- [ ] 7.2 Add `tests/e2e/ci/nl-api-design-rules.spec.ts`: an anonymous read of the report and the marker, an administrator probing from the register list dialog, and a non-administrator refused on the probe. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- No rule in the report reads `pass` unless a check ran and found nothing.
- The report and the marker agree for every document rule.
