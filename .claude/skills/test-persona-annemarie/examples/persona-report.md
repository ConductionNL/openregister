<!-- Example output — test-persona-annemarie for OpenRegister (Nextcloud app) -->

## Persona Test Report: Annemarie de Vries (VNG Standards Architect)

**App:** OpenRegister  
**Date:** 2026-04-10  
**Tester model:** claude-sonnet-4-6

### Would Annemarie recommend this to municipalities? CONDITIONALLY

---

### GEMMA Compliance

| Aspect | Status | Notes |
|--------|--------|-------|
| Reference component mapping | MAPPED | Maps to GEMMA reference component "Registratiecomponent" |
| Layer compliance | COMPLIANT | Operates within the data layer; no layer violations detected |
| Information model alignment | GAPS | Object fields don't consistently use GEMMA information model field names |
| Business function mapping | UNCLEAR | Bedrijfsfunctie mapping not documented in publiccode.yml |

### Common Ground Alignment

| Principle | Status | Notes |
|-----------|--------|-------|
| Data at source | YES | No copying observed; all access via API |
| API-first | YES | UI fully powered by REST API; no direct database calls |
| Component independence | YES | Deployable as standalone Nextcloud app |
| Open standards | PARTIAL | Uses OpenAPI but not all endpoints follow NLGov v2 patterns |

### NLGov API Design Rules v2

| Rule | Status | Details |
|------|--------|---------|
| URL patterns | COMPLIANT | Lowercase, plural nouns, hyphens ✓ |
| Pagination | COMPLIANT | results/total/page/pages/pageSize all present |
| Error format | VIOLATION | Errors return `message` field instead of `type/title/status/detail/instance` |
| Filtering/sorting | COMPLIANT | `?filter[field]=value` and `?sort=-created` work correctly |
| Versioning | MISSING | No version in URL or Accept header |
| OpenAPI spec | PRESENT | `/api/v1/openapi.json` exists and is mostly accurate |

### Interoperability Assessment

| Standard | Status | Notes |
|----------|--------|-------|
| FSC readiness | NOT READY | No FSC metadata endpoint found |
| ZGW compatibility | N/A | Not a case management component |
| Standard schemas | CUSTOM | Custom schema definitions; no mapping to national standards |

### Openness

| Artifact | Status |
|----------|--------|
| publiccode.yml | PRESENT |
| EUPL-1.2 license | PRESENT |
| Dutch documentation | PARTIAL — README in English only |
| Contributing guide | PRESENT |

---

### Issues Found

| # | Standard | Issue | Severity | Annemarie would say... |
|---|----------|-------|----------|------------------------|
| 1 | NLGov API v2 | Error responses missing `type/title/status/detail/instance` format | HIGH | "This is non-negotiable for APIs used in government interoperability chains." |
| 2 | NLGov API v2 | No API versioning in URL or headers | HIGH | "How will municipalities handle migration when breaking changes are introduced?" |
| 3 | GEMMA | Information model field names not aligned with GEMMA reference | MEDIUM | "Inconsistent naming makes automated interoperability much harder." |

---

### Annemarie's Verdict

"OpenRegister shows good architectural discipline — data at the source, API-first, deployable independently. But the two NLGov API violations are blockers for formal adoption by municipalities that need to connect this to other Common Ground components. Fix the error response format and add versioning, and I could recommend this to VNG members."

### Recommendations for Standards Compliance

1. Fix error response format: return `{type, title, status, detail, instance}` per RFC 7807 / NLGov API Design Rules §4.3
2. Add API versioning: include `/v1/` in all resource URLs and document upgrade paths
3. Map object schema fields to GEMMA information model and document the mapping in `publiccode.yml`
