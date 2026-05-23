<!-- Example output — test-accessibility for OpenRegister (Nextcloud app) -->

## Accessibility Report: openregister

### Compliance Level: STATUS B — Partial compliance (EN 301 549 / WCAG 2.1 AA)

---

### Automated Scan (axe-core)

| Page | Violations | Critical | Serious | Moderate | Minor |
|------|-----------|----------|---------|----------|-------|
| Dashboard | 3 | 0 | 2 | 1 | 0 |
| Registers list | 2 | 0 | 1 | 0 | 1 |
| Schema detail | 4 | 0 | 2 | 2 | 0 |
| Objects list | 5 | 1 | 2 | 1 | 1 |
| Settings | 1 | 0 | 0 | 1 | 0 |
| **Total** | **15** | **1** | **7** | **5** | **2** |

---

### Critical Violations (MUST FIX — legally required)

| # | Rule | Impact | Description | Elements | Help |
|---|------|--------|-------------|----------|------|
| 1 | color-contrast | serious | Placeholder text in filter input has 2.8:1 contrast ratio — fails 4.5:1 requirement | 3 inputs | https://dequeuniversity.com/rules/axe/4.9/color-contrast |
| 2 | image-alt | serious | Status icon in objects list has no `alt` attribute | 1 image | https://dequeuniversity.com/rules/axe/4.9/image-alt |
| 3 | label | critical | Date range filter input has no associated `<label>` | 1 input | https://dequeuniversity.com/rules/axe/4.9/label |

---

### Keyboard Navigation

| Page | Fully Navigable | Focus Order | Focus Visible | Keyboard Traps |
|------|----------------|-------------|---------------|----------------|
| Dashboard | YES | LOGICAL | YES | NONE |
| Registers list | YES | LOGICAL | YES | NONE |
| Schema detail | YES | LOGICAL | YES | NONE |
| Objects list | PARTIAL | ISSUES | YES | NONE |
| Create object modal | YES | LOGICAL | YES | NONE |
| Settings | YES | LOGICAL | YES | NONE |

**Objects list focus order issue**: Tab skips the status filter dropdown — it receives focus during Tab traversal but arrow-key navigation of its options does not work.

---

### Manual WCAG Checks

| Criterion | Status | Notes |
|-----------|--------|-------|
| 1.1.1 Non-text content | PARTIAL | Navigation icons lack `alt` or `aria-label`; "delete" icon has no text alternative |
| 1.3.1 Info and relationships | PASS | Headings use correct `h1`–`h3` hierarchy; form fields have `<label>` except date range filter |
| 1.3.2 Meaningful sequence | PASS | Reading order logical when CSS disabled |
| 1.4.1 Use of color | FAIL | Status indicators in objects list use color only (green dot = active, red dot = inactive) — no text label |
| 1.4.3 Contrast | PARTIAL | Body text passes (7.2:1); placeholder text fails (2.8:1); table cell text 13px at 3.1:1 insufficient |
| 1.4.11 Non-text contrast | PASS | Buttons and input borders meet 3:1 |
| 2.1.1 Keyboard | PARTIAL | Status filter dropdown not fully keyboard navigable |
| 2.4.1 Bypass blocks | PASS | Skip-to-main-content link present in Nextcloud shell |
| 2.4.2 Page titled | PASS | Each page has descriptive `<title>` |
| 2.4.3 Focus order | PARTIAL | See objects list focus order issue above |
| 2.4.6 Headings and labels | PASS | Headings are descriptive |
| 2.4.7 Focus visible | PASS | Focus ring visible on all interactive elements |
| 3.1.1 Language of page | PASS | `<html lang="nl">` set correctly |
| 3.3.1 Error identification | PASS | Form errors identify the field and describe the issue |
| 3.3.2 Labels or instructions | PARTIAL | Most fields have labels; date range input uses placeholder only |
| 3.3.3 Error suggestion | PARTIAL | "Dit veld is verplicht" clear; "Ongeldige UUID" gives no fix suggestion |
| 4.1.2 Name, role, value | PARTIAL | Most components correct; status filter missing `aria-expanded` state |
| 4.1.3 Status messages | FAIL | "Object opgeslagen" toast notification has no `aria-live` region — screen readers miss it |

---

### NL Design System Theme Compatibility

| Theme | New Violations | Readable | Contrast OK |
|-------|---------------|----------|-------------|
| Default Nextcloud | 0 (baseline) | YES | PARTIAL |
| Rijkshuisstijl | 2 | YES | YES |
| Utrecht gemeente | 1 | YES | YES |

**Rijkshuisstijl**: 2 new violations — card component with hardcoded `background: white` shows as white-on-white in dark context; action button border becomes invisible.

---

### Toegankelijkheidsverklaring Readiness

- **Status**: B (partial compliance)
- **Criteria met**: 41/50
- **Critical gaps**:
  - Color-only status indicators (1.4.1)
  - Missing `aria-live` on toast notifications (4.1.3)
  - Placeholder-only date range label (3.3.2)
  - Status filter missing keyboard support (2.1.1)
- **Improvement plan needed for**: status indicators, toast notifications, date filter accessibility, placeholder contrast

### Recommendation

**NEEDS FIXES BEFORE RELEASE**

Three issues are legally required under the Besluit digitale toegankelijkheid overheid (mandatory since 2018):
1. Add text labels to all color-only status indicators
2. Add `aria-live="polite"` to toast notification container
3. Add `<label>` to date range filter input and fix placeholder contrast

```
ACCESSIBILITY_TEST_RESULT: FAIL  CRITICAL_COUNT: 3  SUMMARY: 15 axe violations across 5 pages — color-only status, missing aria-live on toasts, and unlabelled date filter are legally required fixes
```
