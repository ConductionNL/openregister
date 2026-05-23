<!-- Example output — test-persona-henk for OpenRegister (Nextcloud app) -->

## Persona Test Report: Henk Bakker (Elderly Citizen)

**App:** OpenRegister  
**Date:** 2026-04-10  
**Tester model:** claude-sonnet-4-6

### Can Henk use this app confidently? CONDITIONALLY — usable with effort, not independently

---

### Text Size & Readability

| Check | Status | Notes |
|-------|--------|-------|
| Base font size ≥ 16px | PARTIAL | Body text 16px ✓; table content 13px ✗ |
| Text can be enlarged to 200% without loss | PASS | Layout reflows at 200% zoom |
| Line height ≥ 1.5 | PASS | 1.6 line height on paragraphs |
| Sufficient color contrast (4.5:1) | PARTIAL | Primary text passes; placeholder text 2.8:1 fails |
| No text in images | PASS | All text is real HTML text |

### Navigation Simplicity

| Check | Status | Notes |
|-------|--------|-------|
| Maximum 3 clicks to any key action | PASS | Create register: 2 clicks |
| Breadcrumbs or clear location indicator | PASS | Breadcrumb shown: App > Registers > Schema |
| Back button works as expected | PASS | Browser back works; no state lost |
| Consistent navigation placement | PASS | Left sidebar always visible |
| Search is prominently placed | PARTIAL | Search exists but is small and hard to find |

### Forms & Input

| Check | Status | Notes |
|-------|--------|-------|
| Labels above fields (not inside) | PARTIAL | Most fields have labels; "Name" label disappears when typing |
| Required fields clearly marked | PASS | Red asterisk (*) on required fields |
| Date input user-friendly | FAIL | ISO date input field — Henk typed "10 april 2026" and got a validation error |
| Confirmation after save | PASS | Green success banner appears after saving |
| Undo option available | MISSING | No undo after delete |

### Error Handling

| Check | Status | Notes |
|-------|--------|-------|
| Error messages in plain Dutch | PARTIAL | "Validation failed" still appears in English on some fields |
| Error placement near the field | PASS | Errors shown directly below each field |
| No technical error codes shown | FAIL | "HTTP 422" visible in one error modal |

---

### Issues Found

| # | Category | Issue | Severity | Henk would say... |
|---|----------|-------|----------|-------------------|
| 1 | Forms | Date picker requires ISO format — natural dates not accepted | HIGH | "I typed 10 april 2026 like a normal person and it said I was wrong. I gave up." |
| 2 | Text size | Table content text is 13px | HIGH | "I have to lean in close to read the table. My eyes aren't what they used to be." |
| 3 | Error messages | Technical error code "HTTP 422" shown to users | MEDIUM | "I don't know what HTTP 422 means. Is something broken? Should I call someone?" |

---

### Henk's Verdict

"The navigation is clear and I could find my way around. But when I tried to fill in a date, it wouldn't accept what I typed. And some text in the tables is really small. If someone helped me set it up I could probably use it, but on my own I'd get stuck."

### Recommendations for Elderly Citizen Usability

1. Replace ISO date inputs with a date picker widget or accept multiple date formats (dd-mm-yyyy, dd month yyyy)
2. Increase table cell font size to 15px minimum; offer a "large text" display option
3. Replace all technical error messages ("HTTP 422", "Validation failed") with plain Dutch explanations
