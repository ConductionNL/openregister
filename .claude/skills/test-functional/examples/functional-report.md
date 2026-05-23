<!-- Example output — test-functional for OpenRegister change: add-schema-versioning -->

## Functional Test Report: add-schema-versioning

### Overall: PASS

---

### Acceptance Criteria Results

| Task | Criterion | Action | Result | Evidence |
|------|-----------|--------|--------|----------|
| #1 | GIVEN a schema exists WHEN I click "Create new version" THEN a new draft version is created with the same fields | Navigated to schema detail, clicked "Create new version" button, verified new draft appeared in version list | PASS | screenshot_01 |
| #1 | GIVEN a schema version is in DRAFT WHEN I edit its fields and save THEN changes are saved only to the draft | Edited field in draft version, saved, verified published version unchanged | PASS | screenshot_02 |
| #2 | GIVEN a draft schema version WHEN I click "Publish" THEN version status changes to PUBLISHED and previous version is marked DEPRECATED | Clicked Publish on draft, confirmed status transition | PASS | screenshot_03 |
| #2 | GIVEN a published schema version WHEN another version is published THEN objects already linked to the old version show a migration warning | Created objects against v1, published v2, verified migration warning banner | PASS | screenshot_04 |
| #3 | GIVEN a schema has multiple versions WHEN I open the version history panel THEN all versions are listed with date and status | Opened version panel, verified v1 (DEPRECATED), v2 (PUBLISHED), v3 (DRAFT) listed | PASS | screenshot_05 |
| #3 | GIVEN a DEPRECATED version WHEN I click "Restore" THEN it creates a new DRAFT based on that version | Restored v1 (deprecated), verified new draft created with v1 fields | PASS | screenshot_06 |

---

### User Flow Tests

| Flow | Status | Notes |
|------|--------|-------|
| CRUD - Create (schema version) | PASS | Create new version from schema detail — works in 2 clicks |
| CRUD - Read | PASS | Version list renders correctly with all versions |
| CRUD - Update | PASS | Draft version editable; published version read-only with clear indication |
| CRUD - Delete | PASS | Delete draft: confirmation dialog appears, deletion removes from list |
| Navigation | PASS | Schema detail → version history → back → schema list — all transitions correct |
| Forms | PASS | Required fields validated; success banner shown after save |
| Loading states | PASS | Spinner shown during version list load; "No versions yet" empty state shown on new schemas |

---

### Console Errors

None — zero JavaScript errors observed across all tested pages and interactions.

---

### Network Errors

None — all API calls returned 2xx. Verified via `browser_network_requests`.

---

### Issues Found

| # | Severity | Description | Steps to Reproduce |
|---|----------|-------------|-------------------|
| 1 | LOW | "Create new version" button label in Dutch UI shows "Nieuwe versie aanmaken" but tooltip says "Create version" (English) | Navigate to schema detail in Dutch locale, hover over "Nieuwe versie aanmaken" button |
| 2 | LOW | Version history panel does not auto-scroll to the newest (draft) version when there are many versions — user must scroll manually | Create 8+ versions of a schema, then create a new draft |

---

### Regression Check

All other schema CRUD operations confirmed working after the versioning change:
- Existing schemas without versions load without errors
- Object creation against non-versioned schemas unaffected
- OpenCatalogi: publication schemas resolve correctly

---

### Recommendation

**APPROVE** — all 6 acceptance criteria pass. Two LOW severity polish issues found but they do not block functionality.

```
FUNCTIONAL_TEST_RESULT: PASS  CRITICAL_COUNT: 0  SUMMARY: All 6 acceptance criteria pass — schema versioning create/publish/deprecate/restore flows work correctly, 2 low-severity polish issues logged
```
