<!-- Example output — test-counsel synthesis report for OpenRegister -->

# Test Counsel Report: openregister

**Date:** 2026-04-10
**Environment:** http://nextcloud.local
**Method:** 8-persona browser, API, and documentation testing against OpenSpec specifications
**Personas:** Henk Bakker, Fatima El-Amrani, Sem de Jong, Noor Yilmaz, Annemarie de Vries, Mark Visser, Priya Ganpat, Jan-Willem van der Berg

---

## Overall Results

| Persona | Features Tested | PASS | PARTIAL | FAIL | Not Implemented |
|---------|----------------|------|---------|------|-----------------|
| Henk Bakker | 14 | 8 | 4 | 2 | 0 |
| Fatima El-Amrani | 12 | 5 | 4 | 3 | 0 |
| Sem de Jong | 16 | 10 | 4 | 2 | 0 |
| Noor Yilmaz | 15 | 12 | 2 | 1 | 0 |
| Annemarie de Vries | 18 | 11 | 5 | 2 | 0 |
| Mark Visser | 14 | 10 | 3 | 1 | 0 |
| Priya Ganpat | 20 | 14 | 4 | 2 | 0 |
| Jan-Willem van der Berg | 13 | 6 | 4 | 3 | 0 |
| **Total** | **122** | **76** | **30** | **16** | **0** |

---

## Critical Issues (found by 3+ personas)

| # | Issue | Severity | Found by | Recommendation |
|---|-------|----------|----------|----------------|
| 1 | Color-only status indicators (green/red dots) — no text label | HIGH | Fatima, Henk, Noor, Jan-Willem | Add text label alongside color dot: "● Actief" / "● Inactief" |
| 2 | Technical jargon ("schema", "objecten", "UUID") used without explanation | HIGH | Fatima, Henk, Jan-Willem | Add glossary tooltips or inline help text on first encounter |
| 3 | No CSV/Excel export from objects list | MEDIUM | Mark, Jan-Willem, Priya | Add export button to objects list with CSV and JSON options |
| 4 | Object search takes 2.1s for 1000+ objects | MEDIUM | Sem, Priya, Mark | Implement server-side search with database index |
| 5 | Error messages mix Dutch and English | MEDIUM | Fatima, Henk, Jan-Willem | Standardize all user-facing errors to Dutch |

---

## Spec vs Implementation Gap Analysis

| Spec Feature | Implemented? | Working? | Persona Feedback |
|-------------|-------------|---------|-----------------|
| CRUD for registers | YES | YES | Mark, Jan-Willem: smooth and discoverable |
| CRUD for schemas | YES | YES | Mark: efficient; Henk: field type dropdown unclear |
| CRUD for objects | YES | YES | All personas can create objects |
| Pagination for objects | YES | PARTIAL | Priya: response missing NLGov pagination fields |
| Search | YES | PARTIAL | Sem/Priya: 2.1s response unacceptable; Annemarie: no search on schema properties |
| REST API / OpenAPI spec | YES | PARTIAL | Priya/Annemarie: spec available but outdated — 3 endpoints undocumented |
| NL Design System themes | YES | PARTIAL | Sem: one hardcoded `background: white` breaks dark mode |
| Multi-tenancy / org isolation | YES | YES | Noor: data isolation verified; org stamping works |
| RBAC | YES | YES | Noor: access control enforced correctly |
| Audit logging | YES | YES | Noor: activity log captures all mutations |
| publiccode.yml | NO | — | Annemarie: file not found — required for NL government listing |
| CSV import | NO | — | Mark, Jan-Willem: frequently needed for data migration |
| Webhooks | NO | — | Mark, Priya: required for event-driven integration |
| In-app help / onboarding | NO | — | Henk, Fatima, Jan-Willem: critical missing piece |

---

## Per-Persona Highlights

### Henk Bakker (Elderly Citizen)
- **Can Henk use this?** WITH DIFFICULTY
- **Top blocker**: Date fields require ISO format — "10 april 2026" returns validation error
- **Quote**: "De knoppen zijn duidelijk en ik kan mijn weg vinden. Maar dat datumveld — die foutmelding begrijp ik niet."

### Fatima El-Amrani (Low-Literate Migrant)
- **Can Fatima use this?** NO — independently
- **Top blocker**: App uses technical jargon throughout; mobile table text at 11px
- **Quote**: "Ik weet niet wat een schema is. Kan iemand het in gewone woorden uitleggen?"

### Sem de Jong (Young Digital Native)
- **Would Sem use this daily?** YES — with complaints
- **Top blocker**: Object search performance (2.1s); 3.8MB JS bundle
- **Quote**: "Search is genuinely broken at scale. The API itself is solid but 2.1 seconds is embarrassing in 2026."

### Noor Yilmaz (Municipal CISO)
- **Is this secure enough for municipalities?** CONDITIONALLY
- **Top blocker**: PHP path exposed in one error response (medium risk)
- **Quote**: "BIO2 audit logging is present and multi-tenancy works correctly. The PHP path in the 500 response needs to be fixed for production."

### Annemarie de Vries (VNG Standards Architect)
- **Is this NLGov compliant?** CONDITIONALLY
- **Top blocker**: Pagination response format wrong; `publiccode.yml` missing
- **Quote**: "The API is close to NLGov compliant but the pagination format needs to be fixed. And we can't list this on OSPO without a publiccode.yml."

### Mark Visser (MKB Software Vendor)
- **Would Mark recommend to municipal clients?** YES — with reservations
- **Top blocker**: No webhooks; no CSV import
- **Quote**: "Good API quality and CRUD works well. But every RFP I see asks for webhooks and CSV import. Fix those two things."

### Priya Ganpat (ZZP Developer)
- **Would Priya integrate against this API?** YES — after fixes
- **Top blocker**: NLGov pagination format violation; OpenAPI spec out of date
- **Quote**: "The API is well-structured and mostly consistent. Fix the pagination response format and update the OpenAPI spec — currently 3 endpoints are missing."

### Jan-Willem van der Berg (Small Business Owner)
- **Can Jan-Willem get things done?** CONDITIONALLY
- **Top blocker**: No onboarding; technical terms unexplained
- **Quote**: "Zodra iemand me heeft uitgelegd wat registers en schema's zijn, kan ik het gebruiken. Maar dat eerste scherm gaf me nul uitleg."

---

## Testing Categories

### Accessibility & Readability

| Issue | Severity | Personas | Spec Reference |
|-------|----------|----------|---------------|
| Color-only status indicators | HIGH | Fatima, Henk, Noor | WCAG 1.4.1 |
| Technical jargon without explanation | HIGH | Fatima, Henk, Jan-Willem | NL Design System plain-language |
| Table text 11–13px on mobile | MEDIUM | Fatima, Henk | WCAG 1.4.3 |
| `aria-live` missing on toast notifications | MEDIUM | Henk | WCAG 4.1.3 |
| Date field requires ISO format | HIGH | Henk, Fatima | Usability |

### Security & Compliance

| Issue | Severity | Personas | Standard |
|-------|----------|----------|----------|
| PHP path in 500 error response | MEDIUM | Noor | BIO2 / OWASP A05 |
| Empty name allowed on create | LOW | Noor, Priya | Input validation |

### API Quality & Standards

| Issue | Severity | Personas | NLGov Rule |
|-------|----------|----------|-----------|
| Pagination response missing total/page/pages | HIGH | Priya, Annemarie | NLGov API rule 7 |
| Error format not RFC 7807 | HIGH | Priya, Annemarie | NLGov API rule 12 |
| Validation returns 422 instead of 400 | MEDIUM | Priya | NLGov API rule 10 |
| OpenAPI spec missing 3 endpoints | MEDIUM | Priya, Annemarie | NLGov API rule 1 |
| publiccode.yml absent | MEDIUM | Annemarie | OSPO / NLGov requirement |

### UX & Performance

| Issue | Severity | Personas | Notes |
|-------|----------|----------|-------|
| Object search 2.1s at 1000+ items | MEDIUM | Sem, Priya, Mark | Client-side filter scan |
| 3.8MB JS bundle | MEDIUM | Sem | No code splitting |
| No onboarding / empty state guidance | HIGH | Jan-Willem, Henk, Fatima | No wizard, no sample data |
| No CSV/Excel export | MEDIUM | Mark, Jan-Willem | Common user expectation |
| No webhooks | MEDIUM | Mark, Priya | Event-driven integrations blocked |
| Hardcoded `background: white` in card | LOW | Sem | Breaks dark mode |

### Language & Content

| Issue | Severity | Personas | Notes |
|-------|----------|----------|-------|
| English error messages | MEDIUM | Fatima, Henk, Jan-Willem | Mixed Dutch/English |
| "UUID" shown to end users | MEDIUM | Fatima, Jan-Willem | Show human-readable identifier instead |
| No in-app help text | HIGH | Henk, Fatima, Jan-Willem | No tooltips, no help links |

---

## Console Errors Summary

| Error | Occurrences | Pages | Severity |
|-------|-------------|-------|----------|
| `Vue warn: missing required prop 'id'` | 4 | Objects list, Schema detail | MEDIUM |
| Uncaught TypeError in status filter dropdown | 1 | Objects list | HIGH |
| No other errors | — | — | — |

---

## Recommendations

### CRITICAL (fix immediately)

None.

### HIGH (fix before next release)

1. Add text labels to color-only status indicators — affects Fatima, Henk, Noor, Jan-Willem
2. Add onboarding flow for new users with plain-Dutch explanation of registers/schemas/objects — affects Henk, Fatima, Jan-Willem
3. Fix NLGov pagination format: add `total`, `page`, `pages`, `pageSize` to collection responses — affects Priya, Annemarie
4. Add `aria-live="polite"` to toast notification container — affects Henk (screen reader)
5. Replace or supplement ISO date input with human-friendly date picker — affects Henk, Fatima

### MEDIUM (improve when possible)

1. Fix error responses to RFC 7807 format with `type`, `title`, `status`, `instance` — affects Priya, Annemarie
2. Add `publiccode.yml` to repository root — affects Annemarie (NLGov listing requirement)
3. Implement server-side search for objects — affects Sem, Priya, Mark
4. Add CSV/Excel export from objects list — affects Mark, Jan-Willem
5. Standardize all error messages to Dutch — affects Fatima, Henk, Jan-Willem
6. Fix PHP path exposure in 500 response on malformed JSON — affects Noor
7. Update OpenAPI spec to include all endpoints — affects Priya, Annemarie
8. Apply code splitting to schema editor module — affects Sem

---

## Suggested OpenSpec Changes

| Change Name | Description | Related Issues | Personas Affected |
|-------------|-------------|---------------|------------------|
| fix-status-indicators | Replace color-only dots with color + text labels "● Actief" / "● Inactief" | #1 | Fatima, Henk, Noor, Jan-Willem |
| add-onboarding | New user onboarding wizard + empty state guidance with plain-Dutch explanations | #3 | Jan-Willem, Henk, Fatima |
| fix-nlgov-pagination | Fix collection API response format to include total/page/pages/pageSize | #4 | Priya, Annemarie |
| add-publiccode | Add publiccode.yml to openregister repository root | #5 | Annemarie |
| add-csv-export | Add CSV/Excel export button to objects list | #6 | Mark, Jan-Willem |
| improve-search | Implement server-side full-text search with database index | #7 | Sem, Priya, Mark |
