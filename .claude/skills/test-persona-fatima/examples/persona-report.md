<!-- Example output — test-persona-fatima for OpenRegister (Nextcloud app) -->

## Persona Test Report: Fatima El-Amrani (Low-Literate Migrant Citizen)

**App:** OpenRegister  
**Date:** 2026-04-10  
**Tester model:** claude-sonnet-4-6

### Can Fatima use this app independently? NOT YET — requires significant plain-language improvements

---

### Language & Readability

| Check | Status | Notes |
|-------|--------|-------|
| Dutch as default language | PASS | App renders in Dutch when locale is nl_NL |
| B1-level Dutch (plain language) | FAIL | Terms like "register", "schema", "objecten" used without explanation |
| Jargon avoided | FAIL | "Publicatiecomponent", "catalogus" used without tooltips or explanations |
| Error messages in plain Dutch | PARTIAL | Some errors in English ("An unexpected error occurred") |
| Tooltips/help text present | MISSING | No help text visible on any form fields |

### Mobile Usability (Fatima uses a smartphone)

| Check | Status | Notes |
|-------|--------|-------|
| 375px viewport usable | PARTIAL | Main navigation accessible; list views require horizontal scroll |
| Touch targets ≥ 44px | PASS | Buttons are large enough for fingers |
| Forms usable on mobile keyboard | PARTIAL | Text inputs work; date pickers fail on Android |
| No tiny text (<14px) | FAIL | Table header text at 11px — too small |

### Visual Clarity

| Check | Status | Notes |
|-------|--------|-------|
| Icons have text labels | PARTIAL | "Add" button has icon + text; delete has icon only |
| Status shown with color AND text | FAIL | Status dots use color only (green/red) — no text label |
| Images/icons meaningful | PASS | App icon and navigation icons are recognizable |
| Empty states explained | PARTIAL | "No registers found" shown but no guidance on next steps |

### Error Recovery

| Check | Status | Notes |
|-------|--------|-------|
| Validation errors near the field | PASS | Red border + message shown below each field |
| Error messages actionable | PARTIAL | "This field is required" is clear; "Invalid UUID format" is not |
| Confirmation before delete | PASS | Delete confirmation modal appears |

---

### Issues Found

| # | Category | Issue | Severity | Fatima would say... |
|---|----------|-------|----------|---------------------|
| 1 | Language | App uses technical jargon throughout without explanation | HIGH | "I don't understand what a 'schema' is. What am I supposed to do here?" |
| 2 | Visual | Status indicators use color only — no text | HIGH | "I'm colorblind and even if I wasn't, I don't know what the green dot means." |
| 3 | Mobile | Table header text is 11px on mobile | MEDIUM | "I can't read this without zooming in, and then the table breaks." |

---

### Fatima's Verdict

"I tried to do what they asked but I don't understand most of the words. My phone shows tiny text on the tables. I don't know if I did something right or wrong because the green dot doesn't say anything. Someone should explain this in normal words."

### Recommendations for Plain-Language / Mobile Improvement

1. Add a glossary or inline tooltips for all technical terms: "register", "schema", "object", "catalogus"
2. Replace color-only status indicators with color + text label (e.g., "● Active" / "● Inactive")
3. Increase table header font size to minimum 14px and add responsive card view for mobile
