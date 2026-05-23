# BIO2 / NIS2 Security Checklist

Reference for security review in `team-architect`. Municipalities are essential entities under NIS2. BIO2 (established Sept 2025 by OBDO, based on ISO 27001:2023 / ISO 27002:2022) is mandatory self-regulation, becoming legally binding through the Cyberbeveiligingswet (Cbw) expected first half 2026.

---

## RBAC & Multi-Tenancy

OpenRegister has a dual-layer authorization system:
- **RBAC**: Role-based access control via Nextcloud organizations
- **Multi-tenancy**: Organization-scoped data isolation via `organisation` system field

Check for:
- [ ] All public endpoints check RBAC (via `$_rbac` parameter)
- [ ] Multi-tenancy filtering applied on list/read operations
- [ ] No data leaks between organizations
- [ ] Admin operations properly gated
- [ ] File uploads validate user permissions

---

## BIO2 / NIS2 Security Controls

**BIO2 Timeline:**
| Period | Status |
|--------|--------|
| Sept 2025 | BIO2 established |
| Sept 2025 – Cbw | Mandatory self-regulation (municipalities: BIO 1.04zv mandatory, BIO2 guiding) |
| First half 2026 | Cbw established; BIO2 becomes legally binding |

**BIO2 requires a functioning ISMS (Information Security Management System).** Check for:
- [ ] **Audit logging**: All data mutations logged (who, what, when, from where)
- [ ] **Access control**: Principle of least privilege applied
- [ ] **Data classification**: Sensitive data identified and protected appropriately
- [ ] **Encryption**: Sensitive data encrypted at rest and in transit (TLS 1.2+)
- [ ] **Session management**: Proper timeout, no session fixation vulnerabilities
- [ ] **Incident detection**: Suspicious activity can be detected from logs
- [ ] **Secure development**: ISO 27002:2022 Control 8.25 (Secure SDLC) and Control 8.28 (Secure Coding) applied
- [ ] **Vulnerability management**: Dependencies scanned for known CVEs

---

## Input Validation

- [ ] All user input validated/sanitized before use
- [ ] No raw SQL queries (use QBMapper/QueryBuilder with named parameters)
- [ ] JSON input decoded safely with error handling
- [ ] File uploads validate MIME types and size limits
- [ ] No command injection via user-controlled strings

---

## AVG/GDPR Data Protection

- [ ] Personal data handling minimized (collect only what's needed)
- [ ] BSN never stored in plaintext or exposed in URLs/logs
- [ ] Right to erasure supported (personal data can be deleted)
- [ ] Data processing purposes documented
- [ ] No PII in application logs

---

## NL Design System / Accessibility (WCAG)

- [ ] UI changes use CSS variables (no hardcoded colors)
- [ ] New components support nldesign theme tokens
- [ ] WCAG 2.1 AA contrast ratios maintained (legally required since 2018, EAA since June 2025)
- [ ] Interactive elements have proper ARIA attributes
- [ ] Keyboard navigation supported
- [ ] Works with screen readers (NVDA, VoiceOver)
