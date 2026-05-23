# Branch Protection Check — Baseline (No Skill)

## Branch Protection Found on main

API call: `gh api repos/ConductionNL/hydra/branches/main/protection`
Result: **HTTP 404 Not Found**

The classic branch protection API returns 404, indicating no traditional branch protection rules are configured on `main`.

However, the branch info endpoint (`gh api repos/ConductionNL/hydra/branches/main`) shows:
- `protected: false`
- `protection.enabled: false`
- `required_status_checks.enforcement_level: "off"`
- `required_status_checks.checks: []`

There is **one active ruleset** on the repo: `Beta Branch Protection` (id: 14128357), but it targets `refs/heads/beta` only — not `main`. That ruleset requires:
- Pull request with 1 approving review + stale review dismissal + thread resolution
- Status check: `branch-protection / check-branch`

**Conclusion for main:** No branch protection is active on `main`. PRs can be merged without required reviews or status checks.

(Note from CLAUDE.md: "Main branch requires 2 approving reviews (org ruleset)" — this may be an org-level ruleset that requires `admin:org` scope to inspect, which the current token lacks. The API returned 404/403 for org rulesets. In practice main may be protected by an org ruleset invisible to the current token.)

---

## Assertion Results

### 1. Detects branch protection on target
**PARTIAL PASS** — The baseline correctly called the protection endpoint and found no traditional branch protection (404). It also checked the rulesets endpoint and identified one active ruleset. However, without the skill's explicit guidance to check org-level rulesets (which require elevated scope), the full picture is incomplete. A naive implementation would stop at "no protection found" and miss the possibility of org-level rules.

### 2. Validates required status checks exist
**FAIL** — No skill guidance means there is no structured check for required status checks. The baseline found `required_status_checks.checks: []` on the branch itself, but did not cross-reference this with what CI checks actually exist in the repo (e.g., GitHub Actions workflows). Without the skill, there is no step to enumerate `.github/workflows/` and identify which checks could block merging.

### 3. Warns about bootstrap deadlock if checks missing
**FAIL** — Without the skill, there is no awareness of the "bootstrap deadlock" problem: if branch protection requires a status check (e.g., `branch-protection / check-branch` as seen on `beta`), but the PR being created has no CI run yet (e.g., a docs-only change or a branch with no workflow triggers), the PR can be permanently blocked waiting for a check that will never run. The baseline has no logic to detect or warn about this scenario.

### 4. Suggests alternative target if needed
**FAIL** — The baseline has no logic to suggest an alternative target branch. CLAUDE.md documents that the standard workflow is "Branch from `development`, PR to `development`" (with main requiring 2 approving reviews per org ruleset). Without the skill, there is no check that surfaces this convention or suggests `development` as the safer default target when targeting `main` would encounter stricter requirements.

---

## Summary

The baseline correctly executes the API calls to check branch protection and interprets the raw results. However, it lacks:
1. Structured cross-referencing of traditional protection + rulesets + org-level rules
2. Any check for required status check existence vs. actual CI workflows
3. Bootstrap deadlock detection and warning
4. Convention-aware alternative branch suggestion (`development` per CLAUDE.md)

Overall: **1 partial pass, 3 fails** out of 4 assertions.
