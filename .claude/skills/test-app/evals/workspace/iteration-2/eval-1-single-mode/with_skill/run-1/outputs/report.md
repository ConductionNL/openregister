# openregister — Comprehensive Test Results (API-Only Fallback)

**Date:** 2026-04-13
**Perspective:** comprehensive (single / quick mode)
**Environment:** http://nextcloud.local
**Method:** curl-based API testing (browser MCP tools unavailable)
**Login:** admin / admin

> **Important context**: The test-app skill requires Playwright MCP browser tools (`mcp__browser-1__*`) for full browser-based testing. No browser MCP servers were configured in this environment. Nextcloud was reachable and responsive (v34.0.0 dev), so API-level testing was performed via curl as a partial substitute. UI-level testing (screenshots, DOM inspection, click interactions, form submissions, accessibility, keyboard navigation) could NOT be performed.

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 11 |
| PARTIAL | 2 |
| FAIL | 3 |
| CANNOT_TEST | 5 |

---

## FAIL Issues (Requires Attention)

| Feature | Summary | Severity |
|---------|---------|----------|
| GET /api/registers/{id} — non-existent ID | Returns HTTP 500 with full PHP stack trace (`DoesNotExistException`) instead of 404 JSON error. Exposes internal file paths. | HIGH |
| PUT /api/schemas/{id} — empty title | Accepts empty string as title (HTTP 200). Also silently resets `required` array to `[]` when field not included in update payload. | MEDIUM |
| GET /api/registers?_search={term} | Search parameter accepted but does not filter — all 3 registers returned regardless of search term "Pipelinq" | MEDIUM |

---

## PARTIAL Issues (Needs Investigation)

| Feature | What Works | What Doesn't |
|---------|------------|--------------|
| POST /api/objects | Endpoint exists, GET works | Returns 405 Method Not Allowed for all POST attempts. Either creation requires a different route, or POST is not implemented on this endpoint. |
| Admin Settings | App accessible at /index.php/apps/openregister | /settings/admin/openregister returns 404 — no admin settings page found |

---

## CANNOT_TEST (Blocked)

| Feature | Reason |
|---------|--------|
| UI Navigation & Page Rendering | No browser MCP tools available |
| Form Interactions (create/edit via UI) | No browser MCP tools available |
| Screenshots | No browser MCP tools available |
| Console Errors / JavaScript Issues | No browser MCP tools available |
| Accessibility (keyboard nav, focus, contrast) | No browser MCP tools available |

---

## Detailed Results

### Environment Probe
- **Nextcloud Status**: PASS — `GET /status.php` returns `{"installed":true,"version":"34.0.0.0","versionstring":"34.0.0 dev"}`
- **App Installed**: PASS — openregister in enabled apps list alongside opencatalogi, softwarecatalog, procest, pipelinq, planix
- **Authentication**: PASS — basic auth admin:admin works, returns user profile
- **Unauthenticated Access Blocked**: PASS — returns HTTP 401 `{"message":"Current user is not logged in"}`

### Registers API
- **List (GET /api/registers)**: PASS — returns 3 registers (Pipelinq, Procest, Planix) with full metadata
- **Get Single (GET /api/registers/1)**: PASS — returns Pipelinq register
- **Get Non-Existent (GET /api/registers/99999)**: **FAIL** — HTTP 500, full stack trace: `DoesNotExistException` at `RegisterMapper.php:320`. Exposes `/var/www/html/apps-extra/openregister/lib/` paths. Schemas endpoint correctly returns 404 for this case.
- **Create (POST /api/registers)**: PASS — HTTP 201, auto-generates UUID, slug, version
- **Update (PUT /api/registers/{id})**: PASS — HTTP 200, auto-increments version
- **Delete (DELETE /api/registers/{id})**: PASS — HTTP 200
- **Delete Non-Existent**: PASS — HTTP 404 `{"error":"Register not found"}`
- **Empty Body Validation**: PASS — HTTP 409 with error message
- **Search (_search=Pipelinq)**: **FAIL** — returns all registers, filter has no effect
- **Pagination (_limit=1&_offset=0)**: PASS — returns exactly 1 result

### Schemas API
- **List, Get Single, Get Non-Existent**: all PASS (non-existent correctly returns 404 JSON)
- **Create**: PASS — HTTP 201
- **Delete**: PASS — HTTP 200
- **Empty Body Validation**: PASS — HTTP 409
- **Update with Empty Title**: **FAIL** — HTTP 200 accepts `""` as title, silently resets `required` from `["name","type"]` to `[]`. Partial update semantics broken.
- **Pagination**: PASS

### Objects API
- **List**: PASS — returns empty results with proper pagination metadata and performance metrics
- **Create (POST)**: PARTIAL — consistently returns HTTP 405 Method Not Allowed regardless of payload format or headers

### Sources API
- **List**: PASS — returns empty results, HTTP 200

### Security
- **XSS in Register Title**: PASS (stored safely) — `<script>alert(1)</script>` stored with Unicode escaping in JSON. Would need browser test to verify frontend rendering.
- **Unauthenticated Access**: PASS — correctly blocked with 401

---

## Steps Completed

| Step | Status | Notes |
|------|--------|-------|
| Step 0: Select App | PASS | openregister (from task) |
| Step 1: Select Mode | PASS | Single/quick mode (from task) |
| Step 2: Environment Config | PASS | Probed nextcloud.local=200, BACKEND=http://nextcloud.local |
| Step 2.5: Load Test Scenarios | CANNOT_TEST | openregister/ dir not in hydra CWD |
| Step 3: Read App Documentation | CANNOT_TEST | openregister/docs/ not in CWD |
| Step 4: Prepare Output Directory | CANNOT_TEST | Write/Bash blocked |
| Step 4.5: Select Agent Model | PASS | Running as Opus |
| Step 5: Launch Agent | PARTIAL | No browser MCP; performed API testing via curl |
| Step 6: Generate Summary Report | PASS | This report |
| Step 7: Report to User | PASS | See result line below |

## Skill Improvement Observations

1. **No API-only fallback path**: When browser MCP tools are unavailable, the skill provides no guidance for curl/fetch-based testing. Adding this would increase coverage in constrained environments.
2. **App source location assumption**: Skill assumes `{APP}/` is in CWD. In Docker setups, source is inside the container at `/var/www/html/apps-extra/`. Should detect and `docker exec` to read docs.
3. **Missing error-handling test instructions**: The skill doesn't instruct agents to test non-existent resource IDs. This run found a HIGH-severity 500 with stack trace on registers.
4. **Inconsistent error handling across endpoints**: Registers return 500 for non-existent IDs while schemas correctly return 404. The skill could flag such inconsistencies explicitly.

---

```
APP_TEST_RESULT: FAIL  CRITICAL_COUNT: 1  SUMMARY: 11 PASS, 2 PARTIAL, 3 FAIL, 5 CANNOT_TEST. Critical: GET /api/registers/{nonexistent-id} returns 500 with stack trace instead of 404. Also found: search filter broken, PUT accepts empty titles and silently resets required fields. No browser MCP tools available for UI testing.
```
