# Eval-3 Report: test-app Scenario Loading & Filtering

**Date:** 2026-04-13
**App:** sample-app (fixture)
**Fixture path:** `.claude/skills/test-app/evals/fixtures/sample-app/`

## Step 2.5: Load Test Scenarios

Found 4 scenario files in `test-scenarios/`:

**Frontmatter parsed:**

| ID | Title | status | priority | perspective | test-commands |
|---|---|---|---|---|---|
| TS-001 | List objects on the dashboard | active | HIGH | functional | [test-app, test-functional] |
| TS-002 | Unauthenticated access is blocked | active | HIGH | security | [test-app, test-security] |
| TS-003 | Export objects to CSV | draft | MEDIUM | functional | [test-app] |
| TS-004 | Run regression suite | active | LOW | regression | [test-regression] |

**Filter applied (status=active AND test-commands contains test-app):**

- TS-001: INCLUDED (active + test-app in commands)
- TS-002: INCLUDED (active + test-app in commands)
- TS-003: EXCLUDED (status is `draft`, not `active`)
- TS-004: EXCLUDED (`test-app` not in test-commands list)

**Filtered output (2 of 4):**
```
Found 2 test scenario(s) for sample-app:
  TS-001  [HIGH]  functional  — List objects on the dashboard
  TS-002  [HIGH]  security    — Unauthenticated access is blocked
```

**User prompt simulated:** "Yes, include all" selected.

## Per-Scenario Test Results

### TS-001: List objects on the dashboard — PASS
- Given: User logged in as admin — credentials available (admin/admin)
- Given: At least one object exists — precondition is stated and verifiable
- When: User navigates to sample-app dashboard — URL resolvable at `http://nextcloud.local/index.php/apps/sample-app`
- Then: List of objects is rendered — specific, verifiable UI assertion
- Then: Each row shows title, created date, status — concrete column assertions

### TS-002: Unauthenticated access is blocked — PASS
- Given: User is NOT logged in — fresh browser session, no auth
- When: Request to `/api/objects/1/1` without auth headers — specific endpoint, testable via fetch()
- Then: Response is HTTP 401 — concrete status code assertion
- Then: Response body contains no object data — verifiable negative assertion

## Excluded Scenarios

- **TS-003:** NOT_TESTED (correctly excluded — status: draft)
- **TS-004:** NOT_TESTED (correctly excluded — test-app not in test-commands)

## Summary

| ID | Filter | Verdict |
|---|---|---|
| TS-001 | INCLUDED | PASS |
| TS-002 | INCLUDED | PASS |
| TS-003 | EXCLUDED | NOT_TESTED |
| TS-004 | EXCLUDED | NOT_TESTED |

Filter accuracy: 2 included, 2 excluded — matches the expected outcome defined in the fixture README.

```
APP_TEST_RESULT: PASS  CRITICAL_COUNT: 0  SUMMARY: Scenario filter correctly selected TS-001 and TS-002 (active + test-app); both scenarios are well-formed and testable.
```
