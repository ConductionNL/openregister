<!-- Example output — test-api for OpenRegister (Nextcloud app) -->

## API Test Report: openregister — registers resource

### Overall: PARTIAL — NLGov compliance violations found

---

### Endpoint Coverage

| Method | Endpoint | Status | Notes |
|--------|----------|--------|-------|
| GET | /api/registers | PASS | Returns 200 with results array |
| GET | /api/registers/{id} | PASS | Returns 200 with full object |
| POST | /api/registers | PASS | Returns 201 with created object |
| PUT | /api/registers/{id} | PASS | Returns 200 with updated object |
| DELETE | /api/registers/{id} | PASS | Returns 200; subsequent GET returns 404 |
| GET | /api/schemas | PASS | Returns 200 with results array |
| GET | /api/schemas/{id} | PASS | Returns 200 with full object |
| POST | /api/schemas | PASS | Returns 201 with created object |
| PUT | /api/schemas/{id} | PASS | Returns 200 with updated object |
| DELETE | /api/schemas/{id} | PASS | Returns 200 |
| GET | /api/objects/{register}/{schema} | PASS | Returns 200 with paginated results |
| POST | /api/objects/{register}/{schema} | PASS | Returns 201 with created object |

---

### NLGov API Design Rules v2 Compliance

| Rule | Status | Details |
|------|--------|---------|
| URL patterns (lowercase, plural, hyphens) | COMPLIANT | `/api/registers`, `/api/schemas`, `/api/objects` — all correct |
| Pagination metadata | VIOLATION | `total` and `pages` missing — response has `results` and `count` but not `total`, `page`, `pageSize` |
| Filtering (filter[field]=value) | COMPLIANT | `?filter[name]=test` works correctly |
| Sorting (sort=-field) | COMPLIANT | `?sort=-created,name` returns correctly ordered results |
| Error response format | VIOLATION | Error responses use `{"message":"..."}` — missing `type`, `title`, `status`, `instance` fields per RFC 7807 |
| Content-Type header | COMPLIANT | All responses return `Content-Type: application/json` |
| HTTP method semantics | COMPLIANT | Unsupported methods return 405; OPTIONS returns CORS headers |

---

### Error Handling

| Scenario | Expected | Actual | Status |
|----------|----------|--------|--------|
| Missing required field (`name`) | 400 | 422 | PARTIAL — wrong status code but error is descriptive |
| Invalid UUID in ID | 404 | 404 | PASS |
| Non-existent resource | 404 | 404 | PASS |
| Unauthenticated request | 401 | 401 | PASS |
| Unauthorized user (non-admin endpoint) | 403 | 403 | PASS |
| Unsupported HTTP method (PATCH) | 405 | 405 | PASS |
| Malformed JSON body | 400 | 400 | PASS |

---

### Edge Cases

| Test | Status | Notes |
|------|--------|-------|
| Unicode text (Dutch ë, ü; Arabic العربية) | PASS | Stored and returned correctly with UTF-8 encoding |
| Special characters (`<>&"'`) | PASS | Properly escaped in JSON output; no XSS vectors observed |
| Empty string for required field | FAIL | `name: ""` returns 201 instead of 400 — empty names allowed |
| Very long string (10,000 chars) | PASS | Returns 400 with "Value too long" message |
| Empty JSON body `{}` | PASS | Returns 422 with list of missing required fields |
| Future/past dates | PASS | ISO dates accepted; no validation of date plausibility |
| Zero/negative integers | PASS | Handled as valid integers |

---

### Sequential Load Test (20 requests to GET /api/registers)

| Request | Response Time (ms) |
|---------|-------------------|
| Requests 1–5 | 45–62ms |
| Requests 6–10 | 48–70ms |
| Requests 11–15 | 51–74ms |
| Requests 16–20 | 49–68ms |

No progressive slowdown. No 500 errors. Rate limiting not triggered within 20 sequential requests (expected — 20 is below threshold).

---

### Issues Found

| # | Severity | Endpoint | Description |
|---|----------|----------|-------------|
| 1 | HIGH | GET /api/registers | Pagination response missing `total`, `page`, `pages`, `pageSize` — NLGov API rule violation |
| 2 | HIGH | All POST endpoints | Validation errors return 422 instead of 400 — NLGov rule requires 400 for client errors |
| 3 | HIGH | All error responses | Error body missing `type`, `title`, `instance` fields (RFC 7807) — NLGov requirement |
| 4 | MEDIUM | POST /api/registers | Empty string accepted as valid `name` — should return 400 |
| 5 | LOW | GET /api/objects/{r}/{s} | No `X-RateLimit-*` headers present — not strictly required but recommended |

---

### Recommendation

**NEEDS FIXES** — 3 HIGH severity NLGov API Design Rules v2 violations. Pagination format and error response structure must be corrected before this API can be listed as compliant on developer.overheid.nl.

```
API_TEST_RESULT: FAIL  CRITICAL_COUNT: 3  SUMMARY: NLGov violations — pagination missing total/page/pages fields, validation errors return 422 not 400, error bodies lack RFC 7807 type/title/instance
```
