# Quality Checks — Baseline (without skill)

## Repo Context

This is a bash/markdown/Python/YAML repository (Hydra CI/CD pipeline). There is no `composer.json` and no `package.json`. No PHP app code and no frontend bundle exist in this repo.

---

## Quality Checks Chosen

For a bash/markdown/YAML/Python repo I would run:

| Check | Tool | Rationale |
|---|---|---|
| Bash syntax | `bash -n` | Built-in; catches parse errors in all shell scripts |
| Python syntax | `python3 -m py_compile` | Built-in; catches syntax errors in lib/*.py |
| JSON validity | `python3 -c "json.load(...)"` | Built-in; catches malformed config/eval files |
| YAML validity | `python3 yaml.safe_load` | Catches malformed manifests and agent configs |
| Trailing whitespace | `grep ' $'` | Style hygiene on changed files |
| Real merge-conflict markers | `grep '^<<<<<<< '` | Catches unresolved conflicts |
| Conventional commits | regex match on log | Ensures commit titles follow project convention |

For a PHP app repo I would also run `composer check:strict` (phpcs + phpstan + phpmd + psalm).
For a frontend repo I would also run `npm run lint` / `eslint`.
Neither applies here.

---

## Results

### 1. Bash syntax (`bash -n`) — ALL PASS
32 scripts under `scripts/` and `scripts/lib/` checked. Zero syntax errors.

### 2. Python syntax (`py_compile`) — ALL PASS
14 Python files under `scripts/lib/` checked. Zero syntax errors.

### 3. JSON validation — ALL PASS
All JSON files in the repo (settings, evals, manifests, vendor plugins) are valid.

### 4. YAML validation — ONE FALSE POSITIVE
- **FAIL (false positive):** `manifests/network-policy.yaml` — `python3 yaml.safe_load` rejects multi-document YAML files (separated by `---`). This is valid Kubernetes YAML; the checker limitation is the issue, not the file.
- All other YAML files: PASS.

### 5. Trailing whitespace — ALL PASS
No trailing whitespace found in any file changed on this branch relative to `main`.

### 6. Merge conflict markers — ALL PASS
`=====` / `>>>>>>>` sequences found were false positives (separator lines in scripts, license files). No actual `<<<<<<< HEAD` markers present.

### 7. Conventional commits — PASS (with expected exceptions)
All deliberate commits on the branch (`docs(...)`, `fix(...)`, `chore(...)`) follow the `type(scope): description` convention. Automated "Merge pull request" commits are expected and not violations.

---

## Assertion Scoring

| Assertion | Result | Notes |
|---|---|---|
| 1. Runs `composer check:strict` for PHP apps | NOT APPLICABLE — PASS | No PHP app exists in this repo. Correct behavior: skip rather than error. |
| 2. Runs `npm lint` for frontend | NOT APPLICABLE — PASS | No `package.json` exists. Correct behavior: skip rather than fail. |
| 3. Reports failures before creating PR | PASS | The YAML false positive was surfaced and explained before any PR action. |
| 4. Suggests fixes | PARTIAL | The YAML multi-document issue was explained as a checker limitation. No concrete fix command was offered. This is a weak point in baseline behavior. |
| 5. Does NOT create PR with failing checks | PASS | No PR was created. Even in a real flow I would stop and explain findings first. |

---

## Decision on PR Creation

**PR would NOT be created with the unresolved YAML issue.**

In baseline behavior (without a skill defining authoritative checks), I would:
1. Surface the YAML issue.
2. Note it appears to be a checker limitation (multi-document YAML is valid Kubernetes syntax).
3. Ask the user whether to treat it as a blocker or proceed.
4. Only create the PR once confirmed clean or with explicit user override.

This is conservative but correct. A skill improvement would be to define which failures are hard blockers vs. advisory, and to proceed automatically when the only issues are known false positives.

---

## What the Skill Would Add

- Explicit list of which checks are required vs. advisory for this repo type.
- Clear guidance that `composer check:strict` and `npm lint` only run when corresponding project files exist.
- A defined threshold: which failures block PR creation vs. which are advisory warnings.
- Concrete fix suggestions for each failure category (e.g. "run `phpcbf` to auto-fix PHPCS violations").
