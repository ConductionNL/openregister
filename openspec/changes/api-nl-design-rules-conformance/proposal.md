---
kind: code
---

# Proposal: api-nl-design-rules-conformance

## Summary

An integrator or a tender assessor can ask Open Register which of the NLGov REST API Design Rules its own API meets. They get one report per register: every rule of ruleset 2.2.1, each marked pass, fail, deviation, manual or not probed, with the finding that decided it. The generated OpenAPI document carries the same result as an `x-nl-api-design-rules` marker. A functional administrator can probe the live responses for the rules a document cannot show, such as the `API-Version` header. CI lints the generated document with the official Spectral ruleset, so a regression shows up on the pull request.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | api-nl-design-rules | Offer an API that follows the Dutch API design rules, as the national API strategy requires. | partial |

**api-nl-design-rules** (openregister's matrix)

- Demand: tender, https://www.tenderned.nl/aankondigingen/overzicht/418890 (the row's origin).
- Competitor yes cells:
  - objects-api (Objects API and Objecttypes API), no evidence URL, source path cited: "source read at 4.2.1, not driven: a VNG Common Ground standard API built on commonground-api-common (objects-api:requirements/base.txt:65): APIVersionHeaderMiddleware sets API-version (objects-api:src/objects/conf/base.py:56, documented in objects-api:src/objects/api/v2/openapi.yaml:251-255), the vng_api_common exception handler for problem responses (objects-api:src/objects/conf/api.py:17), Accept-Crs and Content-Crs for geo (objects-api:src/objects/api/v2/openapi.yaml:96, :281), OAS 3.0.3 (objects-api:src/objects/api/v2/openapi.yaml:1); full ADR conformance not checked rule by rule".
- The ruleset: NLGov REST API Design Rules 2.2.1, https://gitdocumentatie.logius.nl/publicatie/api/adr/2.2.1/, with its Spectral linter ruleset at https://static.developer.overheid.nl/adr/ruleset.yaml.

## Why

Open Register already generates an OpenAPI 3.1 document per register and checks it. The check covers two of the 34 rules.

- `lib/Service/OasService.php:1926-1927` runs pass 6, `validateNlGovRules()`, which is defined at `lib/Service/OasService.php:2147-2194`. It checks `/core/http-methods` against `ALLOWED_HTTP_METHODS` (`:129`) and `/core/http-response-code` against `ALLOWED_STATUS_CODES` (`:144`). Nothing else from the ruleset is checked.
- The findings land in `lib/Service/Oas/OasValidationReport.php`, whose issue codes (`:47-65`) have no rule id. A reader cannot tell which rule a finding belongs to, or which rules were never looked at.
- `lib/Controller/OasController.php:151-153` attaches `x-validation-summary` only on `?validate=true`, and it counts issues. It does not list rules.
- The archived `2026-05-01-openapi-generation` left the task "The spec MUST comply with NL API Design Rules markers" unticked (`openspec/changes/archive/2026-05-01-openapi-generation/tasks.md:24`), and `openspec/specs/openapi-generation/spec.md:537` still says "No `x-nl-api-design-rules` extension".
- The generated document fails rules nobody checks today. `lib/Service/Resources/BaseOas.json` sets `info.version` to `"1.0"`, which is not a semantic version (`/core/semver`). Its server URL `/apps/openregister/api` has no major version (`/core/uri-version`). The query keys `_extend`, `_filter`, `_unset` and `_search` (`lib/Service/OasService.php:1025-1060`) are not camelCase (`/core/query-keys-camel-case`).
- The `API-Version` response header exists (`lib/Middleware/ApiVersionMiddleware.php:199`, registered at `lib/AppInfo/Application.php:741`), but the value is a digits-only major (`lib/Service/ApiVersion/ApiVersion.php:165`). `/core/version-header` asks for the full version.
- `.spectral.yml` extends `spectral:oas` only, and the `validate-oas` script in `package.json:23-24` calls `scripts/download-oas.sh`, which does not exist. No workflow in `.github/workflows/` runs Spectral.

So the rating stays partial. The API is described, but nobody can say which rules it meets.

## What changes

- A rule catalogue for ruleset 2.2.1 lists all 34 rules with id, title and kind: `document`, `response`, `functional` or `module`.
- Pass 6 of `validateOasIntegrity()` checks every document rule the Spectral ruleset tests, not two. Each finding carries its rule id.
- A rule Open Register breaks on purpose is a declared deviation with a reason, never a pass. Example: `/core/uri-version` conflicts with the fleet URL pattern in hydra ADR-002.
- The generated document carries `x-nl-api-design-rules`: the ruleset version and the rules that pass, fail or deviate.
- `GET /api/registers/{id}/oas/conformance` and `GET /api/registers/oas/conformance` return the per-rule report.
- A functional administrator runs `POST /api/registers/{id}/oas/conformance/probe`, or the occ command `openregister:api:conformance`, to check the response rules against the live instance.
- The register list gets a row action that opens a conformance dialog.
- CI lints the generated document with a pinned copy of the official Spectral ruleset.

## Consumers

- No fleet app calls the report. The row is Open Register's own: every leaf app's records are served from the object API this document describes, so a tender that asks a gemeente for NLGov conformance asks it of this surface.
- Tender assessors and integrators read the report and the marker directly.

## ADRs

- hydra ADR-002 (api): the fleet URL pattern `/index.php/apps/{app}/api/{resource}` has no version segment. That is why `/core/uri-version` is a declared deviation, not a silent fail.
- hydra ADR-091 (external API surface belongs to openconnector): this change is about Open Register's own REST surface. It does not add or check a ZGW or other statutory API shape.
- hydra ADR-082 (public endpoint throttling) and ADR-054 (public surface hardening): the conformance read is public like the OAS it is derived from, so it keeps the OAS endpoint's anonymous rate limit.
- hydra ADR-005 (security) and ADR-016 (routes): the probe route is administrator-only and declares its auth posture.
- hydra ADR-004 (frontend): the dialog lives in `src/dialogs/`.
- hydra ADR-115 (a green instrument is not a present feature): a rule that was not probed reads `not-probed`, never `pass`.
- openregister ADR-008 (shared format validators): the `/core/semver` check uses `lib/Formats/SemVerFormat.php`.

## Impact

- Extends the capability `oas-validation` (its requirement "NLGov API Design Rules Validation" checks four scenarios; this adds the full set and the report).
- Affected code: `lib/Service/OasService.php`, `lib/Service/Oas/OasValidationReport.php`, a new `lib/Service/Oas/NlGovRuleCatalogue.php` and `lib/Service/Oas/NlGovResponseProbe.php`, `lib/Controller/OasController.php`, `appinfo/routes.php`, a new occ command, `src/views/register/RegistersIndex.vue`, a new `src/dialogs/register/NlGovConformanceDialog.vue`, `.spectral.yml`, `package.json`, `.github/workflows/api-test-coverage.yml`.
- Backwards compatible. The document gains one extension key. Existing issue codes stay; a new `nlgov_rule` code is added beside them. Strict mode (`?strict=true`) keeps failing only on errors, and a new document rule reports a warning unless the rule is already an error today.
- Size: M.

## Out of scope

- Making every rule pass. Moving to URI versioning, renaming the underscore query keys, or sending a full semantic version in `API-Version` are contract changes. They belong to `api-as-a-versioned-surface` and a later contract version, and this report is what tells that change where to start.
- The NLGov modules (geospatial, signing, encryption). They are reported as `module` rules with result `manual`.
- Statutory APIs (ZGW, StUF) and their conformance. Per hydra ADR-091 those belong to openconnector (integriq).
