<!-- Example output — test-persona-priya for OpenRegister (Nextcloud app) -->

## Persona Test Report: Priya Ganpat (ZZP Developer / Integrator)

**App:** OpenRegister  
**Date:** 2026-04-10  
**Tester model:** claude-sonnet-4-6

### Would Priya recommend OpenRegister's API to integration clients? CONDITIONALLY

---

### API Documentation Quality

| Check | Status | Notes |
|-------|--------|-------|
| OpenAPI spec available | PASS | `/api/v1/openapi.json` — 47 endpoints documented |
| Interactive docs (Swagger/Redoc) | MISSING | Spec file present but no hosted UI |
| Request/response examples | PARTIAL | 60% of endpoints have examples; PUT/PATCH often missing |
| Error codes documented | PARTIAL | HTTP codes listed but error body format undocumented |
| Authentication documented | PASS | Bearer token and basic auth both described |

### API Usability (Developer Experience)

| Check | Status | Notes |
|-------|--------|-------|
| Consistent URL patterns | PASS | `GET /api/v1/registers`, `GET /api/v1/registers/{uuid}` |
| Pagination works correctly | PASS | `?page=2&pageSize=20` works; metadata in response body |
| Filtering | PASS | `?filter[status]=active` works |
| Sorting | PASS | `?sort=-created,name` works |
| Field selection | MISSING | No `?fields=` query parameter |
| Bulk operations | MISSING | No batch create/update endpoints |

### SDK and Integration Support

| Check | Status | Notes |
|-------|--------|-------|
| PHP SDK available | MISSING | No official SDK |
| Webhook support | MISSING | No webhook/event streaming found |
| API key management | PASS | Per-user API keys available in Nextcloud settings |
| CORS configured | PASS | Frontend can call API from other origins |

### Real API Tests (curl)

```
GET /api/v1/registers
→ 200 OK, 120ms, returns paginated list ✓

GET /api/v1/registers/{uuid}/schemas
→ 200 OK, 95ms, returns schemas ✓

POST /api/v1/registers with missing "name" field
→ 422 Unprocessable Entity — body: {"message":"Validation failed"}
→ Expected: {"type":"...", "title":"Validation Error", "status":422, "detail":"name is required"}  ✗

GET /api/v1/nonexistent
→ 404 Not Found — body: {"message":"Route not found"}
→ Expected RFC 7807 format ✗
```

---

### Issues Found

| # | Category | Issue | Severity | Priya would say... |
|---|----------|-------|----------|--------------------|
| 1 | DX | Error responses don't follow RFC 7807 — makes error handling unpredictable | HIGH | "I have to special-case every error type. That's 3 hours of defensive code per integration." |
| 2 | DX | No field selection (`?fields=`) — always returns full objects | MEDIUM | "For a list endpoint returning 1000 objects, I'm downloading 50x more data than I need." |
| 3 | DX | No interactive API docs (Swagger UI) | MEDIUM | "I can read JSON but my clients can't. They'll ask me to document everything manually." |

---

### Priya's Verdict

"The fundamentals are there — consistent URLs, pagination that actually works, filtering and sorting. I can build on this. But the error format is a real problem — every integration I build will need custom error handling code. Fix that first, add Swagger UI for documentation, and I'd confidently recommend this API to my clients."

### Recommendations for API/DX Improvement

1. Return RFC 7807 error format from all endpoints: `{type, title, status, detail, instance}`
2. Add `?fields=id,name,created` query parameter support to reduce payload size
3. Host Swagger UI at `/api/v1/docs` using the existing OpenAPI spec
