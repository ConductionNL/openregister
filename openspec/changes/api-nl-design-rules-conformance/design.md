# Design: api-nl-design-rules-conformance

Read at openregister development c53dd0685c.

## D-1: one rule catalogue, pinned to ruleset 2.2.1

A new `lib/Service/Oas/NlGovRuleCatalogue.php` holds the 34 rules of NLGov REST API Design Rules 2.2.1 (https://gitdocumentatie.logius.nl/publicatie/api/adr/2.2.1/). Each entry has:

- `id`, for example `/core/no-trailing-slash`;
- `title`, the rule's one-line title;
- `kind`: `document` (checkable from the OpenAPI document), `response` (checkable only from a live response), `functional` (a design guideline no machine can decide) or `module` (geospatial, signing, encryption);
- `deviation`: null, or a reason string when Open Register breaks the rule on purpose.

The ruleset version is a class constant, `RULESET_VERSION = '2.2.1'`. Moving to a later ruleset is a code change with a test, not a setting.

The two constants that encode rules today, `ALLOWED_HTTP_METHODS` (`lib/Service/OasService.php:129`) and `ALLOWED_STATUS_CODES` (`:144`), move behind the catalogue so one class owns every rule.

Kinds, from the 2.2.1 document's "how to test" notes:

| kind | rules |
|---|---|
| document | `/core/no-trailing-slash`, `/core/path-segments-kebab-case`, `/core/query-keys-camel-case`, `/core/date-time/format`, `/core/date-time/date-omit-time-portion`, `/core/http-methods`, `/core/error-handling/problem-details`, `/core/error-handling/invalid-input`, `/core/doc-openapi`, `/core/doc-openapi-contact`, `/core/publish-openapi`, `/core/uri-version`, `/core/semver`, `/core/transport/tls` |
| response | `/core/version-header`, `/core/transport/security-headers`, `/core/date-time/timezone` |
| functional | `/core/naming-resources`, `/core/naming-collections`, `/core/interface-language`, `/core/hide-implementation`, `/core/http-safety`, `/core/http-response-code`, `/core/stateless`, `/core/nested-child`, `/core/resource-operations`, `/core/error-handling/all-errors`, `/core/doc-language`, `/core/deprecation-schedule`, `/core/transition-period`, `/core/transport/cors`, `/core/transport/no-sensitive-uris` |
| module | `/core/modules/geospatial`, `/core/modules/signing`, `/core/modules/encryption` |

`/core/http-response-code` is functional in 2.2.1. The existing whitelist check (`lib/Service/OasService.php:2178-2190`) stays as a warning, and the report shows the rule as `manual` with that warning attached, because a whitelist cannot decide whether a code is semantically right.

## D-2: every document rule is checked in pass 6

`validateNlGovRules()` (`lib/Service/OasService.php:2147-2194`) grows from two checks to one check per `document` rule. Each check writes through the existing report (`lib/Service/Oas/OasValidationReport.php:85` `addError`, `:106` `addWarning`) with a new code `CODE_NLGOV_RULE = 'nlgov_rule'` and a new `rule` field on the issue. Existing codes (`:47-65`) stay so no consumer of `x-validation-summary` breaks.

What each check reads:

- `/core/no-trailing-slash`, `/core/path-segments-kebab-case`: every key of `paths`.
- `/core/query-keys-camel-case`: every parameter with `in: query`. The generated `_extend`, `_filter`, `_unset`, `_search` (`lib/Service/OasService.php:1025-1060`) fail it.
- `/core/date-time/format`, `/core/date-time/date-omit-time-portion`: every schema property with `format` `date`, `date-time` or `time`.
- `/core/error-handling/problem-details`: every 4xx and 5xx response declares `application/problem+json` and references the `Error` component, which `BaseOas.json` already defines with the RFC 7807 fields.
- `/core/error-handling/invalid-input`: every POST, PUT and PATCH declares a 400 response.
- `/core/doc-openapi`: `openapi` starts with `3.`. `/core/doc-openapi-contact`: `info.contact` has `name`, `url` and `email` (`BaseOas.json` sets all three).
- `/core/semver`: `info.version` passes `lib/Formats/SemVerFormat.php`. `BaseOas.json` has `"1.0"`, so this fails until the base document says `1.0.0`. Fixing that string is in this change (task 2.3) because it is not a contract change.
- `/core/uri-version`: the first server URL ends in `/v{major}`. It does not, see D-4.
- `/core/publish-openapi`: the document is served as JSON at a stable URL. Open Register serves it at `/api/registers/{id}/oas` (`appinfo/routes.php:1670`) and `/api/versions/{version}/oas` (`:1685-1689`), not at `openapi.json`. The check reports the served URL and the rule's expected location side by side.
- `/core/transport/tls`: every server URL is `https://`. On a development instance on plain HTTP this fails, and the report says the instance URL it read.

## D-3: the document carries the marker

After pass 6, `createOas()` (`lib/Service/OasService.php:222`) writes a root key:

```json
"x-nl-api-design-rules": {
  "version": "2.2.1",
  "pass": ["/core/http-methods", "..."],
  "fail": ["/core/semver"],
  "deviation": ["/core/uri-version", "/core/query-keys-camel-case"],
  "manual": ["/core/naming-resources", "..."]
}
```

The marker lists only document rules and functional rules. Response rules appear in the report (D-5), not in the marker, because the document is cached by ETag (`lib/Controller/OasController.php:159-172`) and a probe result would make the ETag change without the document changing. This closes the unticked task in the archived `2026-05-01-openapi-generation`.

## D-4: a deviation is named, never passed

Some rules Open Register breaks deliberately, and changing that is a contract break:

- `/core/uri-version`: hydra ADR-002 fixes the URL pattern as `/index.php/apps/{app}/api/{resource}`. The version is negotiated by the `API-Version` header instead (`lib/Service/ApiVersion/ApiVersionNegotiator.php:61`).
- `/core/query-keys-camel-case`: the underscore prefix marks a reserved query key, and every client sends `_limit` and `_page`.

A deviation is reported as `deviation` with its reason, in the report and in the marker. It is never counted as `pass`. The catalogue is the only place a deviation is declared: there is no setting to add one, so an administrator cannot turn a failing rule green.

## D-5: the report endpoint

`OasController` gets `conformance(string $id)` and `conformanceAll()`, routed as `GET /api/registers/{id}/oas/conformance` and `GET /api/registers/oas/conformance`, next to `appinfo/routes.php:1670-1671`. The body:

```json
{
  "ruleset": "2.2.1",
  "generatedAt": "2026-09-27T10:00:00Z",
  "rules": [
    {"id": "/core/semver", "title": "...", "kind": "document", "result": "fail",
     "findings": [{"path": "info.version", "message": "\"1.0\" is not a semantic version"}]}
  ],
  "counts": {"pass": 11, "fail": 1, "deviation": 2, "manual": 18, "not-probed": 3}
}
```

`result` is one of `pass`, `fail`, `deviation`, `manual`, `not-probed`. Response rules read `not-probed` until an administrator has probed (D-6), and then carry the time of the probe.

Both routes are `#[PublicPage]` with the same `#[AnonRateLimit(limit: 30, period: 60)]` as `generate()` (`lib/Controller/OasController.php:114`), because the report is derived from the public document and says nothing the document does not.

## D-6: the response probe is an administrator action

A document cannot show a response header. `lib/Service/Oas/NlGovResponseProbe.php` sends a small fixed set of requests to the instance's own absolute URL, using Nextcloud's `OCP\SetupCheck\CheckServerResponseTrait` pattern for self-requests:

- one GET on a collection path of the register, to read `API-Version` (`/core/version-header`), the security headers (`/core/transport/security-headers`) and the offset of every `date-time` value (`/core/date-time/timezone`);
- one GET on a nil UUID, `00000000-0000-0000-0000-000000000000`, to read the error's `Content-Type` (`/core/error-handling/problem-details` at response level).

At most five requests per probe, each with a 10 second timeout. The probe runs as the calling administrator, so it reads what that administrator may read and never widens RBAC.

`/core/version-header` is expected to fail: `ApiVersionMiddleware::afterController()` (`lib/Middleware/ApiVersionMiddleware.php:189-199`) sends the negotiated version id, and `ApiVersion` documents that id as digits only (`lib/Service/ApiVersion/ApiVersion.php:165`). The rule asks for the full version. The report says so. Fixing it is `api-as-a-versioned-surface`'s decision.

The last probe result is stored in `IAppConfig` under `nlgov_probe_{registerId}` with its timestamp, and the report reads it. Route: `POST /api/registers/{id}/oas/conformance/probe`. The method carries no `#[NoAdminRequired]`, so Nextcloud's security middleware refuses a non-administrator with 403 before the method runs. The occ command `openregister:api:conformance {register} [--probe]` prints the same report for CI and operators.

## D-7: the dialog on the register list

`src/views/register/RegistersIndex.vue` has row actions that download the OAS (`:641`) and open it in Redoc (`:666`). A third row action, "Check Dutch API design rules", opens `src/dialogs/register/NlGovConformanceDialog.vue`. The dialog lists the rules grouped by result, shows the findings per rule, and shows a "Probe responses" button only to administrators. It uses `NcDialog` and lives in `src/dialogs/` per the modal isolation rule.

## D-8: CI lints with the official ruleset

`.spectral.yml` extends `spectral:oas` only. This change vendors the official ruleset from https://static.developer.overheid.nl/adr/ruleset.yaml as `tests/oas/adr-ruleset-2.2.1.yaml` (pinned, so CI needs no network and does not move when Logius publishes). `.spectral.yml` extends both. The broken `validate-oas` script (`package.json:23-24` calls a `scripts/download-oas.sh` that is not in the repo) is replaced by a script that writes the generated document from the running CI instance and lints it. The Newman job in `.github/workflows/api-test-coverage.yml` already boots an instance, so the lint step runs there. Declared deviations are turned off in `.spectral.yml` by rule name with a comment naming D-4, so CI fails only on a real regression.

## Risks

- **Security.** The report is public like the document. It adds no data: every finding points at a path, parameter or header already in the public document. The probe is administrator-only, sends at most five bounded requests to the instance itself, and never follows a redirect off-host.
- **Performance.** Pass 6 walks the document once. The existing performance requirement in `oas-validation` ("OAS generation with validation completes within time budget", under 2 seconds for 20 schemas) applies; the unit test for D-2 includes that 20-schema fixture. The report endpoint reuses `createOas()`; it does not generate twice.
- **Honesty of the instrument (hydra ADR-115).** `manual` and `not-probed` are separate results from `pass`, and the counts show them, so a report with 11 passes cannot be read as 34.
- **Multitenancy.** `createOas()` reads registers with `_rbac: false, _multitenancy: false` (`lib/Service/OasService.php:231-233`). That is today's behaviour for the public document and this change does not widen it; the report covers exactly the registers the document covers.
