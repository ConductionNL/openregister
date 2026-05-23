<!-- Example output — test-persona-mark for OpenRegister (Nextcloud app) -->

## Persona Test Report: Mark Visser (MKB Software Vendor)

**App:** OpenRegister  
**Date:** 2026-04-10  
**Tester model:** claude-sonnet-4-6

### Would Mark recommend OpenRegister to his municipal clients? YES — with minor workflow improvements

---

### Business Workflow Efficiency

| Check | Status | Notes |
|-------|--------|-------|
| CRUD for registers — complete | PASS | Create, read, update, delete all work correctly |
| CRUD for schemas — complete | PASS | Schema management fully functional |
| CRUD for objects — complete | PASS | Object creation, editing, deletion all work |
| Bulk operations | PARTIAL | Bulk delete available; no bulk edit |
| Search across all objects | PASS | Full-text search returns results in < 200ms |
| Import from CSV | MISSING | No import functionality found |

### Integration Capabilities

| Check | Status | Notes |
|-------|--------|-------|
| REST API documented | PASS | OpenAPI spec at `/api/v1/openapi.json` |
| API authentication options | PASS | API keys and bearer tokens supported |
| Webhooks for event-driven workflows | MISSING | No webhook endpoints found |
| Export to standard formats | PARTIAL | JSON export available; no CSV or XML |
| Multi-tenancy for multiple clients | PARTIAL | Organisation isolation present but not configurable per-schema |

### Configuration & Customization

| Check | Status | Notes |
|-------|--------|-------|
| Custom field types in schemas | PASS | String, integer, date, boolean, array all supported |
| Validation rules on fields | PARTIAL | Required/optional supported; regex patterns not available |
| Custom views / saved filters | MISSING | No saved filter or custom view functionality |
| Branding per municipality | MISSING | NL Design System themes apply globally, not per-tenant |

### Stability & Reliability

| Check | Status | Notes |
|-------|--------|-------|
| No errors in 30-min session | PASS | Zero unexpected errors during testing |
| Data saved correctly | PASS | All creates/edits persisted correctly across page reload |
| Performance under normal load | PASS | All operations < 1s with 500 objects |

---

### Issues Found

| # | Category | Issue | Severity | Mark would say... |
|---|----------|-------|----------|-------------------|
| 1 | Integration | No webhook support | MEDIUM | "All my clients use event-driven architectures. Without webhooks I have to poll the API every minute — that's not a real integration." |
| 2 | Data | No CSV import | MEDIUM | "Every migration project starts with a CSV import. Without it I need to build a custom script for every client." |
| 3 | Configuration | No regex validation on schema fields | LOW | "My clients need to enforce formats like postal codes. Right now I'd have to validate at the application layer." |

---

### Mark's Verdict

"This is a solid product. My implementation teams would be happy with the API quality and the CRUD workflows work correctly without surprises. The two things I'd tell Conduction to fix before my next big municipal client — webhooks and CSV import. Those come up in every RFP. Everything else I can work around."

### Recommendations for MKB Vendor Use Cases

1. Add webhook support: POST to configured URL on create/update/delete events, with retry logic
2. Add CSV import to registers with field mapping wizard
3. Add regex validation option to string fields in schema builder
