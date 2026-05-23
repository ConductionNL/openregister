# Hydra-Specific Persistence Vectors

This reference extends the generic persistence audit checklist with vectors specific to the Hydra agentic CI/CD pipeline. Load this when the `hydra` argument is passed to `/persistence-audit`.

## Architecture Context

Hydra is a 3-persona CI/CD pipeline that takes OpenSpec change proposals and turns them into validated PRs:

| Persona | Container | Role | PAT Scope |
|---------|-----------|------|-----------|
| Al Gorithm | `hydra-builder` | Implement + fix | `contents:write`, `pull-requests:write` |
| Juan Claude van Damme | `hydra-reviewer` | Code review | `pull-requests:write` only |
| Clyde Barcode | `hydra-security` | Security review | `pull-requests:write` only |

Pipeline: Issue labeled `ready-to-build` → Builder implements → Quality checks → Browser tests → Code Review + Security Review (parallel) → Fix loop (max 2) → PR ready (or yolo auto-merge).

## Hydra-Specific Checklist

### H1. Per-Persona PAT Scoping

**Files to check:** `secrets/.env`, `secrets/credentials.json`, `scripts/orchestrate.sh`, `scripts/hydra-supervisor.sh`

- [ ] Builder PAT (`GIT_TOKEN_BUILDER`) is scoped to `contents:write` + `pull-requests:write` only — no `admin` scope
- [ ] Reviewer PAT (`GIT_TOKEN_REVIEWER`) has only `pull-requests:write` — cannot push code
- [ ] Security PAT (`GIT_TOKEN_SECURITY`) has only `pull-requests:write` — cannot push code
- [ ] PATs are per-user GitHub accounts (not org-level tokens with broad scope)
- [ ] PAT expiry dates are set and tracked (GitHub fine-grained PATs support expiry)
- [ ] Compromising a reviewer PAT cannot escalate to code-write access
- [ ] PAT values are not logged in `logs/` directory output

### H2. Claude OAuth Token Management

**Files to check:** `secrets/credentials.json`, `scripts/lib/credentials.sh`

- [ ] Claude OAuth tokens (`claude_accounts` array) have priority ordering — compromising a lower-priority token does not grant access to higher-priority account
- [ ] Rate-limit state (`/tmp/hydra-rate-limits.json`) does not contain actual token values (only identifiers)
- [ ] Token fallback logic (`credentials.sh:148-250`) does not leak token values to logs during fallback
- [ ] `AUTH_ENV_PLACEHOLDER` replacement (`credentials.sh:326`) is not logged before exec
- [ ] Tokens injected via `-e CLAUDE_CODE_OAUTH_TOKEN=...` are not visible in `docker inspect` on the host after container exits

### H3. Container Isolation Boundaries

**Files to check:** `images/builder/Dockerfile`, `images/reviewer/Dockerfile`, `images/security/Dockerfile`, `images/*/entrypoint.sh`, `scripts/lib/entrypoint-common.sh`

- [ ] Builder container has `--cap-drop ALL` with only necessary capabilities re-added
- [ ] Reviewer container has NO Write/Edit tools — enforced at Claude Code permission level
- [ ] Security container has NO Write/Edit tools — only Read, Bash, Grep, Glob, and Semgrep MCP
- [ ] Egress is restricted via iptables allowlist (`entrypoint-common.sh:43-65`) — only DNS, GitHub, Anthropic API
- [ ] No shared volumes between builder and reviewer/security containers
- [ ] Each container gets a fresh `claude_tmp` directory (not reused across runs)
- [ ] GIT_TOKEN embedded in `git config --global` is scoped to the persona's PAT only
- [ ] Container filesystem is ephemeral — no persistent writable volume that survives container restart
- [ ] Builder cannot read reviewer/security container logs or vice versa

### H4. Yolo Auto-Merge Trust Chain

**Files to check:** `scripts/orchestrate.sh` (lines 945-993), `scripts/hydra-supervisor.sh`

- [ ] Yolo auto-merge requires ALL phases to pass (build, quality, browser, code review, security review) — no phases are skipped
- [ ] `gh pr merge --admin` is used — verify this flag is appropriate and the PAT scope justifies it
- [ ] Auto-merge approval message (`"Approved by Hydra pipeline (yolo)"`) is distinguishable from human approval in the audit trail
- [ ] The `yolo` label can only be set by authorized users (not by any pipeline container)
- [ ] Removing the `yolo` label after merge (`swap_labels "yolo" ""`) prevents re-triggering
- [ ] There is no race condition between label check and merge execution
- [ ] Commit signature verification is enforced (or explicitly documented as not required) on yolo merges

### H5. Label State Machine Integrity

**Files to check:** `scripts/lib/labels.sh`, `scripts/reconcile.sh`, `openspec/changes/pipeline-label-state-machine/`

- [ ] Label transitions are enforced in code — not just documented
- [ ] `swap_label_atomic()` prevents concurrent label modifications (or handles races gracefully)
- [ ] `reconcile.sh` runs frequently enough that invalid label states cannot persist long enough to complete a pipeline (current: 10-minute interval)
- [ ] An attacker with `push` scope cannot set `code-review:pass` + `security-review:pass` labels directly (bypassing actual reviews)
- [ ] Label validation (`validate_single_stage()`) rejects impossible state combinations
- [ ] Metadata labels (`yolo`, `openspec`, `fix-iteration:*`) cannot be used to skip stages
- [ ] Auto-fix actions in reconcile.sh are logged with timestamp and reason
- [ ] Label changes made outside the pipeline (via GitHub UI) are detected and flagged

### H6. Log Integrity and Audit Trail

**Files to check:** `logs/` directory, `scripts/lib/labels.sh` (`_label_log`), `scripts/hydra-supervisor.sh`, `scripts/reconcile.sh`

- [ ] Pipeline logs (`logs/pipeline-{ISSUE}-{TIMESTAMP}/`) capture all stage transitions
- [ ] Label transitions are logged with timestamp via `_label_log` function
- [ ] Supervisor activity is logged to `logs/supervisor.log`
- [ ] Reconciliation violations are logged to `logs/reconcile.log`
- [ ] Logs are written to tamper-evident / append-only / external storage (currently only `logs/`, which is gitignored for safety — the gap is absence of off-host durable storage, not the `.gitignore` entry itself)
- [ ] Log files are not writable by the pipeline containers (only by the host supervisor)
- [ ] Token values (`sk-ant-*`, `ghp_*`, `github_pat_*`) are masked or excluded from all log output
- [ ] There is a mechanism to detect log deletion or tampering
- [ ] Yolo auto-merge actions are logged with the specific issue number and all label states at merge time

### H7. Secret Rotation and Incident Response

**Files to check:** `docs/operations/`, `secrets/.env.example`, `secrets/credentials.example.json`

- [ ] There is a documented process for rotating all three GitHub PATs without pipeline downtime
- [ ] There is a documented process for rotating Claude OAuth tokens
- [ ] Rotating one persona's PAT does not require rotating the others
- [ ] After PAT rotation, all active containers using the old token are terminated (not allowed to complete)
- [ ] The `credentials.json` format supports hot-reload (or requires explicit restart — documented either way)
- [ ] Rate-limit state at `/tmp/hydra-rate-limits.json` is cleared after token rotation

## Risk Severity Matrix (Hydra-Specific)

| Vector | Persistence Duration | Access Level | Severity |
|--------|---------------------|-------------|----------|
| Builder PAT compromise | Until manual rotation | Code write to all target repos | **CRITICAL** |
| Reviewer PAT compromise | Until manual rotation | Can approve PRs (but not write code) | **HIGH** |
| Claude OAuth token leak | Until manual rotation | Can spawn Claude sessions with org billing | **HIGH** |
| Label manipulation (yolo + pass) | 10-min window before reconcile | Auto-merge without review | **CRITICAL** |
| Log tampering on host | Until discovered | Erase evidence of prior compromise | **HIGH** |
| Container escape via builder | Session lifetime | Host filesystem access | **CRITICAL** |
| Rate-limit state poisoning | Until cleared | Denial of service on specific tokens | **MEDIUM** |
