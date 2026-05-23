# Procest App Test (Baseline — No Skill)

**Date:** 2026-04-13 | **App:** procest | **Env:** http://nextcloud.local | **Overall:** PARTIAL PASS — 12/13 tests passed, 1 critical bug

**Test scenario directory `procest/test-scenarios/` does not exist** in the hydra repo. Tests were designed ad-hoc from the Procest data model discovered via API.

**Preconditions** — all passed: Nextcloud reachable (302), admin/admin auth works (OCS API 200), Procest app installed (`/apps-extra/procest`), Procest register exists (id=2, 26 schemas), JS bundle loads (8MB, 200), l10n/nl.js loads (200).

## 13 tests executed

| Test | Description | Result |
|---|---|---|
| T1 | App frontend loads (`/index.php/apps/procest`) | PASS — HTTP 200, title "Procest - Nextcloud", JS+l10n load |
| T2 | List case types (schema 9) | PASS — 200, returns 2 existing case types with pagination metadata |
| T3 | Create case type | PASS — 201, UUID returned, all fields match |
| T4 | Create 3 status types (lifecycle) | PASS — 201 for each, caseType relation stored |
| T5 | Create case | PASS — 201, relations (caseType, status) stored, dates correct |
| T6 | Read single case by UUID | PASS — 200, all fields match |
| T7 | Update case (status + priority) | PASS — PUT requires all required fields (partial=400, full=200) |
| T8 | Filter cases by property (`?priority=high`) | PASS — 200, 1 result |
| T9 | Search cases (`?_search=Bouwvergunning`) | PASS — 200, 1 result; nonexistent search returns 0 |
| T10 | GET non-existent UUID | PASS — 404, `{"error":"Not Found"}` |
| T11 | Validation: POST without required fields | PASS — 400, clear error message |
| T12 | Create task linked to case (zaakUuid) | PASS — 201, relation stored |
| T13 | **Delete case** | **FAIL** — HTTP 500, `Class "OCA\OpenRegister\Dto\DeletionAnalysis" not found` |

## Critical bug found

DELETE returns 500 for any object. The exception is `Class "OCA\OpenRegister\Dto\DeletionAnalysis" not found` in `ReferentialIntegrityService.php:685`. The missing DTO class prevents the deletion graph walker from executing. Object remains after the failed delete (confirmed with GET 200).

## Limitations

No browser/Playwright testing was possible (commands blocked). No `procest/test-scenarios/` existed to incorporate. Only API-level testing was performed — Vue.js SPA interaction (navigation, forms, modals) was not tested.

## API pattern discovered

- Create: `POST .../api/objects/{register}/{schema}`
- List: `GET ...?_register=N&_schema=N`
- Read: `GET .../api/objects/{reg}/{schema}/{uuid}`
- Update: `PUT` (same, requires all required fields)
- Delete: broken (500)
- Schema must be referenced by numeric ID, not slug.
