<!-- Example output — test-security for OpenRegister (Nextcloud app) -->

## Security Test Report: openregister

### Overall Risk: LOW

---

### RBAC & Authorization

| Test | Status | Details |
|------|--------|---------|
| Admin-only endpoints protected | PASS | `/settings/users` and admin API routes return 403 for non-admin user |
| Horizontal access control | PASS | User A cannot retrieve User B's objects via UUID manipulation (returns 404) |
| Privilege escalation blocked | PASS | Non-admin cannot promote themselves; role modification endpoint requires admin |
| RBAC annotations consistent | PASS | All non-admin endpoints have `@NoAdminRequired`; admin endpoints lack it and enforce correctly |

---

### Multi-Tenancy Isolation

| Test | Status | Details |
|------|--------|---------|
| Data isolation between orgs | PASS | Organisation A objects not visible in Organisation B's list views |
| Org field auto-stamping | PASS | Created objects automatically receive creator's `organisation` UUID |
| Cross-tenant API access blocked | PASS | Org B user querying Org A object UUID by ID receives 404 |
| Org field override blocked | PASS | POST with explicit `organisation` set to different org's UUID — silently overridden to own org UUID |
| Bulk operations respect org scope | PASS | Bulk delete only affects own organisation's objects |

---

### Input Validation

| Vector | Status | Details |
|--------|--------|---------|
| XSS (reflected) | PASS | `<script>alert('XSS')</script>` in name field — stored and rendered as escaped text |
| XSS (stored) | PASS | Saved XSS payload in description — displays as literal text on detail page; no alert execution |
| SQL injection | PASS | `' OR '1'='1` in filter parameter — parameterized queries prevent injection; returns empty results |
| SQL injection (search) | PASS | `'; DROP TABLE--` in search — no SQL error; returns empty results |
| JSON injection (prototype pollution) | PASS | `{"__proto__":{"admin":true}}` payload rejected — returns 422 with validation error |
| Command injection | PASS | No server-side execution of submitted data — shell metacharacters stored as literal text |

---

### CORS & CSRF

| Test | Status | Details |
|------|--------|---------|
| CORS allowlist | PASS | `http://evil.example.com` as Origin receives no `Access-Control-Allow-Origin` header |
| CORS credentials | PASS | No endpoint returns `Access-Control-Allow-Origin: *` with `Access-Control-Allow-Credentials: true` |
| CSRF token enforcement | PASS | POST to form endpoint without requesttoken returns 401 |
| `@NoCSRFRequired` only on API endpoints | PASS | All API endpoints have `@NoCSRFRequired`; web form endpoints do not |
| Preflight OPTIONS for public endpoints | PASS | OPTIONS returns correct CORS headers for public API endpoints |

---

### Authentication & Session

| Test | Status | Details |
|------|--------|---------|
| Brute force protection | PASS | After 5 failed logins, Nextcloud rate-limits further attempts |
| Session cookie flags | PASS | `HttpOnly`, `Secure`, `SameSite=Lax` all present |
| Session invalidation on logout | PASS | Token invalid after logout; subsequent API call with old session returns 401 |
| Password not in network traffic | PASS | Login uses POST body only; no password in URLs or headers |

---

### Information Disclosure

| Test | Status | Details |
|------|--------|---------|
| No stack traces in errors | PARTIAL | In one case: `PUT /api/schemas/{id}` with invalid JSON returns raw PHP exception path in 500 response body — MEDIUM severity |
| No PII in browser console | PASS | Console clean — no user data, emails, or UUIDs logged |
| No unnecessary server headers | PASS | `X-Powered-By` header absent; Nextcloud version not in headers |
| No debug endpoints | PASS | No `/debug`, `/test`, or development endpoints accessible |

---

### BIO2 Compliance

| Control | Status | Notes |
|---------|--------|-------|
| Audit logging | PRESENT | Create/update/delete operations logged in Nextcloud activity log |
| Access control (least privilege) | OK | Default user role has read-only access; write requires explicit assignment |
| Encryption (TLS) | OK | HTTPS enforced in production; localhost dev exemption noted |
| Input validation | OK | All endpoints validate input; one exception: empty `name` field accepted (LOW) |
| Session management | OK | Follows Nextcloud session standards; timeout configurable |

---

### Vulnerabilities Found

| # | Severity | Category | Description | Remediation |
|---|----------|----------|-------------|-------------|
| 1 | MEDIUM | Information Disclosure | `PUT /api/schemas/{id}` with invalid JSON returns PHP exception path (`/var/www/html/...`) in 500 response | Catch malformed JSON in controller; return generic 400 error message |
| 2 | LOW | Input Validation | Empty string accepted as `name` on register and schema creation | Add `minLength: 1` validation to name field in controller |
| 3 | LOW | Information Disclosure | API error responses include `{"message":"..."}` format — leaks minor implementation details | Standardize to RFC 7807 format; review detail field content |

---

### Recommendation

**SECURE** — no CRITICAL or HIGH vulnerabilities found. Two LOW severity findings and one MEDIUM (PHP path disclosure on malformed JSON) should be addressed before production deployment. BIO2 audit logging is in place. RBAC, multi-tenancy isolation, and injection protections all pass.

```
SECURITY_TEST_RESULT: PASS  CRITICAL_COUNT: 0  SUMMARY: Low risk — RBAC, multi-tenancy, and injection protections pass; one MEDIUM (PHP path in 500 response) and two LOW findings to fix before production
```
