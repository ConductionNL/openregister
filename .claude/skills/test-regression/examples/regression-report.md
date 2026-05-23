<!-- Example output — test-regression for OpenRegister change: add-schema-versioning -->

## Regression Report: add-schema-versioning

### Overall: NO REGRESSIONS

---

### Apps Tested

| App | Core Functions | Navigation | Console Clean | Network Clean |
|-----|---------------|------------|---------------|---------------|
| openregister | PASS | PASS | PASS | PASS |
| opencatalogi | PASS | PASS | PASS | PASS |
| softwarecatalog | PASS | PASS | PASS | PASS |

**Scope rationale**: OpenRegister changed (schema layer) → all downstream apps tested.

---

### Core Functionality — OpenRegister

| Function | Status | Notes |
|----------|--------|-------|
| Dashboard loads with statistics | PASS | Register/schema/object counts correct |
| Registers list → click register → schemas | PASS | Navigation intact |
| Schemas list → click schema → properties | PASS | Schema detail renders correctly |
| Objects list → pagination → click object | PASS | Pagination works; detail view loads |
| Create new object | PASS | Form submits correctly; object appears in list |
| Edit object | PASS | Changes persist after reload |
| Delete object | PASS | Confirmation dialog; removed from list |
| Search objects | PASS | Returns relevant results < 2s |
| Sidebar opens/closes | PASS | No state issues |
| Settings page | PASS | Loads without errors |
| **New: Schema versioning** | PASS | Version list, create version, publish — all working |

---

### Core Functionality — OpenCatalogi

| Function | Status | Notes |
|----------|--------|-------|
| Dashboard loads | PASS | |
| Catalogi list → catalog → publications | PASS | |
| Publications list with pagination | PASS | |
| Search page — query → results | PASS | |
| Directory with organizations | PASS | |
| Create/edit publication flow | PASS | |
| Public pages load without auth | PASS | |

---

### Core Functionality — Software Catalogus

| Function | Status | Notes |
|----------|--------|-------|
| Dashboard loads | PASS | |
| Voorzieningen list → details | PASS | |
| Organisaties list → details | PASS | |
| Contracten list | PASS | |
| Contactpersonen list | PASS | |
| Create/edit flows | PASS | |

---

### Cross-App Integration

| Integration Point | Status | Notes |
|-------------------|--------|-------|
| OpenRegister → OpenCatalogi | PASS | Objects from registers accessible via catalog publications |
| OpenRegister → SoftwareCatalog | PASS | Voorzieningen data from registers flows correctly |
| Shared ObjectService | PASS | Returns correct objects for all consuming apps |
| SchemaService | PASS | Schema retrieval unaffected by versioning change |
| Event dispatching | PASS | Create/update/delete events still trigger listeners in OpenCatalogi |

---

### Regressions Found

None.

---

### New Console Errors

None — console clean across all pages and all three apps.

---

### New Network Errors

None — zero new 4xx/5xx responses observed compared to baseline.

---

### Data Integrity Check

- Test objects created during the regression run were deleted successfully
- No orphaned schema records found
- Database constraints (unique schema slug per register) still enforced

---

### Recommendation

**SAFE TO MERGE** — no regressions detected across OpenRegister, OpenCatalogi, and Software Catalogus. Schema versioning change is isolated to the schema layer and has no observable side effects on downstream apps.

```
REGRESSION_TEST_RESULT: PASS  CRITICAL_COUNT: 0  SUMMARY: No regressions — all core flows in OpenRegister, OpenCatalogi, and SoftwareCatalog pass; cross-app integration intact
```
