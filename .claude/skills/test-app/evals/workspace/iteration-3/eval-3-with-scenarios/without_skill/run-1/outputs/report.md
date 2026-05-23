# Sample App Test (Baseline — No Skill)

**Date:** 2026-04-13 | **App:** sample-app | **Env:** http://nextcloud.local | **Overall:** FAIL — environment unavailable, 0 tests executed

## Scenario discovery and filtering

Found 4 test scenarios in `test-scenarios/`:

| ID | Title | Status | test-commands | Included? | Reason |
|---|---|---|---|---|---|
| TS-001 | List objects on the dashboard | active | test-app, test-functional | Yes | Active + includes test-app |
| TS-002 | Unauthenticated access is blocked | active | test-app, test-security | Yes | Active + includes test-app |
| TS-003 | Export objects to CSV | draft | test-app | No | Status is draft, not active |
| TS-004 | Run regression suite | active | test-regression | No | test-app not in test-commands |

**Filtering approach:** I manually read the frontmatter of each scenario and applied two filters:
1. **Status filter:** only `status: active` scenarios are eligible
2. **Command filter:** only scenarios whose `test-commands` list includes `test-app`

This produced 2 eligible scenarios: TS-001 and TS-002.

## Environment check

| Check | Result |
|---|---|
| Docker containers running | FAIL — `docker ps` shows 0 containers |
| http://nextcloud.local reachable | FAIL — curl exit code 7 (connection refused / host unreachable) |
| http://nextcloud.local/login reachable | FAIL — same, HTTP 000 |

The Nextcloud environment is completely unavailable. No Docker containers are running.

## Scenario results

### TS-001: List objects on the dashboard — NOT EXECUTED

**Steps:**
- Given: User logged in as admin, at least one object exists
- When: Navigate to sample-app dashboard
- Then: List of objects rendered with title, created date, status

**Result:** Could not execute. Nextcloud unreachable. No HTTP request could be made to verify the dashboard or any API endpoint.

### TS-002: Unauthenticated access is blocked — NOT EXECUTED

**Steps:**
- Given: User is NOT logged in
- When: Request `/api/objects/1/1` without auth headers
- Then: HTTP 401, no object data in body

**Result:** Could not execute. Nextcloud unreachable. No HTTP request could be made to test unauthenticated access.

## Summary

| Metric | Value |
|---|---|
| Scenarios found | 4 |
| Scenarios filtered in | 2 (TS-001, TS-002) |
| Scenarios filtered out | 2 (TS-003: draft status, TS-004: wrong test-command) |
| Scenarios executed | 0 |
| Scenarios passed | 0 |
| Scenarios failed | 0 |
| Scenarios blocked | 2 (environment unavailable) |

## Limitations

- The Nextcloud environment at http://nextcloud.local is not running. Docker shows zero containers.
- No tests could be executed — neither browser-based (TS-001) nor API-based (TS-002).
- This is a fixture/synthetic app (`sample-app`), not a real installed Nextcloud app, so even with a running Nextcloud instance the app may not be installed.
- Without a skill, the filtering was done manually by reading each scenario's YAML frontmatter. No automated scenario discovery or filtering pipeline was used.
