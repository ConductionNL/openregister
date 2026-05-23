<!-- Example output — test-scenario-run for OpenRegister TS-001 -->

# Scenario Results: TS-001 — Create a new register and add an object

**Date**: 2026-04-10
**App**: openregister
**Environment**: http://nextcloud.local
**Agent**: browser-1
**Overall**: PASS

---

## Preconditions

| Precondition | Status | Notes |
|---|---|---|
| Nextcloud running at nextcloud.local | ✅ MET | Login page responds |
| Admin credentials valid (admin/admin) | ✅ MET | Login successful |
| OpenRegister app installed and active | ✅ MET | App appears in sidebar |
| No existing register named "Test Bevolkingsregister" | ✅ MET | Verified — register list empty of this name |

---

## Execution Summary

| Step | Action | Status | Notes |
|---|---|---|---|
| 1 | Navigate to OpenRegister app | ✅ | `http://nextcloud.local/index.php/apps/openregister/` loaded |
| 2 | Click "Registers" in sidebar | ✅ | Registers list page loads |
| 3 | Click "Nieuw register" button | ✅ | Create register modal opens |
| 4 | Fill in title: "Test Bevolkingsregister" | ✅ | Field accepts input |
| 5 | Fill in description: "Register voor testdata bevolking" | ✅ | Field accepts input |
| 6 | Click "Opslaan" | ✅ | Modal closes, success banner: "Register aangemaakt" |
| 7 | Verify register appears in list | ✅ | "Test Bevolkingsregister" visible in register list |
| 8 | Click on "Test Bevolkingsregister" | ✅ | Register detail page opens |
| 9 | Click "Nieuw schema" to create a schema | ✅ | Schema creation modal opens |
| 10 | Create schema "Persoon" with field "naam" (type: string, required) | ✅ | Schema saved successfully |
| 11 | Navigate to Objects → click "Nieuw object" | ✅ | Object creation form opens with Persoon schema fields |
| 12 | Fill in "naam": "Jan Jansen" | ✅ | Field accepts input |
| 13 | Click "Opslaan" | ✅ | Object saved; success banner shows |
| 14 | Verify object appears in objects list | ✅ | "Jan Jansen" visible in objects list |

---

## Acceptance Criteria

| Criterion | Status | Evidence |
|---|---|---|
| Register is created and visible in the register list | ✅ PASS | Screenshot TS-001-step-07.png: "Test Bevolkingsregister" in list |
| Register detail page opens when clicked | ✅ PASS | Screenshot TS-001-step-08.png: register detail renders |
| Schema "Persoon" can be created with a required string field | ✅ PASS | Screenshot TS-001-step-10.png: schema saved |
| Object creation form shows the schema fields | ✅ PASS | Screenshot TS-001-step-11.png: "naam" field visible |
| Object "Jan Jansen" is saved and appears in objects list | ✅ PASS | Screenshot TS-001-step-14.png: object in list |
| No JavaScript errors throughout | ✅ PASS | Console clean — zero errors captured |

---

## Console Errors

| Page/Step | Error | Severity |
|---|---|---|
| — | None | — |

---

## Screenshots

- `TS-001-step-07.png` — Register list showing "Test Bevolkingsregister"
- `TS-001-step-08.png` — Register detail page
- `TS-001-step-10.png` — Schema "Persoon" saved
- `TS-001-step-11.png` — Object creation form with schema fields
- `TS-001-step-14.png` — Objects list with "Jan Jansen"

---

## Notes

- The complete end-to-end flow from register creation to first object took 14 steps and approximately 90 seconds including screenshots.
- The "Nieuw object" button only appears after at least one schema exists — this is expected behavior documented in the specs.
- No cleanup needed: the test register and objects can serve as baseline data for other test scenarios.
