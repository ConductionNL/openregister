<!-- Example output — test-persona-janwillem for OpenRegister (Nextcloud app) -->

## Persona Test Report: Jan-Willem van der Berg (Small Business Owner)

**App:** OpenRegister  
**Date:** 2026-04-10  
**Tester model:** claude-sonnet-4-6

### Can Jan-Willem get things done without help? CONDITIONALLY — needs better onboarding

---

### First Impression & Onboarding

| Check | Status | Notes |
|-------|--------|-------|
| Purpose of app is clear on first view | FAIL | Landing page shows empty register list with no explanation |
| Getting-started guidance present | MISSING | No onboarding wizard, tooltip tour, or "start here" prompt |
| Sample data or example available | MISSING | No demo register or example schema |
| Help documentation linked | PARTIAL | Nextcloud help icon present but links to generic docs |

### Core Workflows

| Check | Status | Notes |
|-------|--------|-------|
| Create register — discoverable | PASS | "Add register" button clearly visible |
| Create schema — discoverable | PASS | "Add schema" accessible from register detail |
| Add object — discoverable | PARTIAL | "Add object" only visible after schema is created — unclear to new users |
| Search objects | PASS | Search bar works, returns results quickly |
| Export data | MISSING | No CSV/Excel export found |

### Language & Terminology

| Check | Status | Notes |
|-------|--------|-------|
| Plain Dutch (no jargon) | FAIL | "schema", "objecten", "UUID" shown without explanation |
| Field labels descriptive | PARTIAL | "Type" field on schema unclear — dropdown options are technical |
| Button labels actionable | PASS | "Opslaan", "Annuleren", "Verwijderen" — clear and correct |
| Success/error messages in Dutch | PARTIAL | Most in Dutch; some validation errors still in English |

### Efficiency

| Check | Status | Notes |
|-------|--------|-------|
| Common tasks in ≤ 3 clicks | PASS | Create register: 2 clicks; add object: 3 clicks |
| Keyboard shortcut for save | MISSING | Must click "Save" button; Ctrl+S not supported |
| Bulk operations available | MISSING | Cannot select multiple objects for bulk delete/edit |
| Recent items accessible | MISSING | No "recently viewed" or "favorites" |

---

### Issues Found

| # | Category | Issue | Severity | Jan-Willem would say... |
|---|----------|-------|----------|-------------------------|
| 1 | Onboarding | No guidance for new users — empty state gives no direction | HIGH | "I logged in for the first time and had no idea what to do. I nearly gave up immediately." |
| 2 | Language | Technical terms used without explanation | HIGH | "What's the difference between a 'schema' and an 'object'? I'd need someone to explain it to me." |
| 3 | Efficiency | No data export to Excel/CSV | MEDIUM | "My accountant needs data in Excel. If I can't export it, the whole thing is useless to me." |

---

### Jan-Willem's Verdict

"Once someone explained to me what registers and schemas are, I could use it. The buttons are clear and saving works properly. But if I'd tried this myself I wouldn't have gotten past the first screen. And I really need to export to Excel — that's a dealbreaker for my administration."

### Recommendations for Small Business Usability

1. Add an onboarding flow for new users: explain what a register, schema, and object are in plain Dutch with one concrete example
2. Add CSV/Excel export to the objects list view
3. Add Ctrl+S keyboard shortcut for save across all forms
