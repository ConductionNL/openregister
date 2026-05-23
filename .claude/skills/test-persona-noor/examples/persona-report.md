<!-- Example output — test-persona-noor for OpenRegister (Nextcloud app) -->

## Persona Test Report: Noor Yilmaz (Municipal CISO / Functional Admin)

**App:** OpenRegister  
**Date:** 2026-04-10  
**Tester model:** claude-sonnet-4-6

### Would Noor approve this for municipal deployment? CONDITIONALLY — security review required

---

### Authentication & Session Management

| Check | Status | Notes |
|-------|--------|-------|
| Login required for all data | PASS | Unauthenticated requests return 401 |
| Session timeout active | PASS | Session expires after 30 minutes of inactivity |
| CSRF protection | PASS | All state-changing requests require CSRF token |
| Password policy enforced | PASS | Nextcloud-level policy applies |

### Role-Based Access Control (RBAC)

| Check | Status | Notes |
|-------|--------|-------|
| Admin / non-admin separation | PASS | Non-admin users cannot access admin settings |
| Register-level permissions | PASS | `registers.write` required to create/edit registers |
| Object-level permissions | PARTIAL | Read permissions enforced but no row-level security |
| Multi-tenancy isolation | FAIL | Organisation A can read Organisation B's schema names via API |

### Audit Trail

| Check | Status | Notes |
|-------|--------|-------|
| Create events logged | PASS | Object creation logged with user, timestamp, IP |
| Modify events logged | PASS | All field changes recorded in audit log |
| Delete events logged | PASS | Deletions logged with reason field |
| Log export available | PARTIAL | CSV export available but no SIEM integration (Syslog/CEF) |
| Log tampering protection | NOT VERIFIED | Logs stored in database — no append-only mechanism found |

### Data Handling (AVG/GDPR)

| Check | Status | Notes |
|-------|--------|-------|
| PII in logs | PASS | Object content not included in log entries |
| Data retention policy | MISSING | No configurable retention period for objects |
| Right to erasure | PARTIAL | Hard delete available but no anonymization option |

### BIO2 Security Controls

| Control | Status | Notes |
|---------|--------|-------|
| Input validation server-side | PASS | Schema enforces data types on API input |
| No stack traces in error responses | PASS | Production errors return generic messages |
| Rate limiting on API | MISSING | No rate limiting found on public API endpoints |
| Dependency vulnerability scan | NOT CHECKED | No automated scan in CI found |

---

### Issues Found

| # | Category | Issue | Severity | Noor would say... |
|---|----------|-------|----------|-------------------|
| 1 | RBAC | Organisation A can enumerate schema names from Organisation B | HIGH | "This is a data classification violation. Even metadata can be sensitive." |
| 2 | AVG/GDPR | No configurable data retention policy | HIGH | "We need to demonstrate compliance with retention schedules during ENSIA audits." |
| 3 | Security | No rate limiting on API endpoints | MEDIUM | "Without rate limiting, a compromised account can exfiltrate the entire register." |

---

### Noor's Verdict

"The authentication and audit logging are solid — that's the right foundation. But the multi-tenancy gap is a showstopper for shared environments. I can't approve this for a shared platform until Organisation A is properly isolated from Organisation B. The data retention issue also needs to be solved before we can pass our ENSIA self-evaluation."

### Recommendations for Security Compliance

1. Fix multi-tenancy: add organisation-level scope filter to all API endpoints so schemas/objects from other organisations are not visible
2. Add configurable data retention: allow admins to set per-register retention periods with automatic archiving/deletion
3. Implement API rate limiting: per-user throttle on read and write endpoints (e.g. 1000 requests/minute)
