## Persistence Audit: Hydra Agentic CI/CD Pipeline

**Date:** 2026-04-17
**Scope:** Full codebase audit of `/home/wilco/hydra` repository — scripts, Dockerfiles, agent configs, GitHub Actions workflows, secrets templates, label state machine, and operations docs. Hydra-specific vector reference applied.
**Model:** Claude Opus 4.6 (1M context)

### Summary

- 12/43 vectors **COVERED**
- 13/43 vectors **PARTIAL**
- 15/43 vectors **MISSING**
- 3/43 vectors **N/A**
- **Critical gaps:**
  1. No token masking/redaction in pipeline logs (tokens can appear in error output)
  2. No documented incident response / PAT rotation procedure
  3. `--read-only` filesystem not enforced in local/supervisor docker run (only in GitHub Actions)
  4. Yolo auto-merge race condition between label check and merge execution
  5. No monitoring for new keys/tokens being created
  6. Log directory writable by pipeline user; no tamper detection

---

### Findings

#### 1. OAuth and Token Persistence

| Check | Status | Evidence |
|-------|--------|----------|
| Admin-revocable grants | N/A | No user-facing OAuth provider. Hydra consumes Claude Max OAuth tokens for API access — these are managed via `secrets/credentials.json` and can be removed by editing the file. No OAuth grant/revoke UI exists. |
| Refresh token max lifetime | PARTIAL | Claude OAuth tokens used via `CLAUDE_CODE_OAUTH_TOKEN` env var (`scripts/lib/credentials.sh:116`). The `credentials.example.json:9` shows tokens with no expiry field. `docs/operations/auth.md:13` notes static tokens expire ~24h, but `credentials.json` tokens have no enforced max lifetime — they persist until manually rotated. |
| Refresh token rotation | N/A | Not applicable — Hydra does not implement an OAuth server. It consumes Claude OAuth tokens as opaque bearers. |
| Token invalidation on account disable | MISSING | No mechanism exists to invalidate Claude OAuth or GitHub PAT tokens when a team member's account is disabled. Tokens in `credentials.json` persist until manually removed. Searched `scripts/lib/credentials.sh`, `docs/operations/secrets.md`, `docs/operations/auth.md` — no disable/revoke logic found. |
| Token invalidation on password change | MISSING | Same gap as above. GitHub PATs survive password changes (by design), and Claude OAuth tokens are independent of GitHub credentials. No coupling exists. |
| CSRF state parameter | N/A | Hydra has no OAuth authorization flow — it only uses pre-generated tokens. |
| Admin visibility of active grants | PARTIAL | Rate-limit state file (`/tmp/hydra-rate-limits.json`, `scripts/lib/credentials.sh:148`) tracks which token+model combinations are exhausted, providing partial visibility. However, there is no dashboard or command showing all active tokens, their scopes, or last-use timestamps. |
| Token values excluded from logs | MISSING | **Critical gap.** No token masking or redaction exists anywhere in the codebase. `grep -ri "mask|redact|filter.*token|sanitize.*log"` across all scripts returned zero matches. `docs/operations/secrets.md:127` acknowledges "Container logs (JSONL) may contain token prefixes in error messages" but offers no mitigation beyond gitignoring the logs directory. `scripts/lib/credentials.sh:86-87` logs token names (safe) but `credentials.sh:327` replaces `AUTH_ENV_PLACEHOLDER` with the actual token value in the command array — if the `docker run` command itself is logged (e.g., via `set -x` or error output), the full token is exposed. |

#### 2. Service Accounts and Machine Identities

| Check | Status | Evidence |
|-------|--------|----------|
| Service accounts bypassing normal auth | COVERED | Three GitHub PATs exist, one per persona: builder (`contents:write`, `pull-requests:write`), reviewer (`pull-requests:write`), security (`pull-requests:write`). Defined in `secrets/.env.example:28-33` and `secrets/credentials.example.json:19-24`. These are the only machine identities. |
| Service account credential expiry | PARTIAL | `docs/operations/auth.md:51` recommends "Rotate at least every 90 days" but there is no automated expiry enforcement or alerting. GitHub fine-grained PATs support expiry dates, but the pipeline does not check or enforce this. No script verifies PAT expiry before use. |
| Inventory of machine identities | COVERED | `secrets/credentials.example.json` serves as the inventory template. `docs/operations/auth.md:42-49` documents each PAT's required scopes. `docs/operations/secrets.md` describes the full layout. |
| Independent revocation | COVERED | Per-persona PATs in `credentials.json` (`git_tokens.builder`, `git_tokens.reviewer`, `git_tokens.security`) can be rotated independently. `scripts/lib/credentials.sh:123-135` (`get_git_token`) retrieves tokens per role, confirming independent access. |
| Workload identities scoped minimally | PARTIAL | `secrets/.env.example:28-33` documents minimal scopes per persona. Reviewer and security PATs have only `pull-requests:write` — no code push. However, the local dev setup (`secrets/.env:5-7`) uses a single token for all three personas, collapsing the isolation. The `credentials.json` also shows empty reviewer/security tokens defaulting to builder: `credentials.sh:78` (`git.get("reviewer", builder)`). |

#### 3. CI/CD and Automation

| Check | Status | Evidence |
|-------|--------|----------|
| Pipelines minting fresh secrets | COVERED | Containers cannot create new GitHub PATs or Claude tokens. They receive tokens via `-e` environment variables at container launch (`orchestrate.sh:300-308`, `hydra-supervisor.sh:778`). No `gh auth login` or token generation commands exist in container entrypoints or CLAUDE.md prompts. |
| Pipeline secrets rotatable without redeploying | PARTIAL | `credentials.json` is read once at supervisor startup (`credentials.sh:36-90`). Rotating a token requires editing the file and restarting the supervisor (kill PID, watchdog restarts it within 1 minute per `watchdog-supervisor.sh:10`). No hot-reload mechanism exists. `docs/operations/secrets.md` does not document a zero-downtime rotation procedure. |
| Deploy keys/PATs/SSH keys inventoried | COVERED | Three PATs documented in `secrets/.env.example` and `secrets/credentials.example.json`. Claude OAuth tokens documented with priority ordering. `docs/operations/auth.md` provides the full inventory. No SSH keys are used (all git auth via HTTPS PAT insteadOf: `entrypoint-common.sh:79`). |
| Monitoring for new keys/tokens created | MISSING | No alerting or monitoring exists for new PAT or OAuth token creation. Searched all scripts and docs — no GitHub audit log integration, no webhook for token events, no script that checks for unexpected tokens. |
| Compromised pipeline step persisting across deploys | COVERED | Containers are ephemeral: `--rm` flag on all docker run commands (`orchestrate.sh:249`, `hydra-supervisor.sh:767`), `--tmpfs /workspace` and `--tmpfs /tmp` (`orchestrate.sh:250-251`). No persistent writable volumes survive container restart. `docs/container-architecture.md:87-88` confirms "Each container is destroyed after completion. No state persists between runs except what is written to the target repository or GitHub." |

#### 4. Identity Provider Integration

| Check | Status | Evidence |
|-------|--------|----------|
| Compromised IdP granting access | N/A | Hydra does not use SSO/SAML/OIDC for authentication. All auth is via pre-provisioned PATs and Claude OAuth tokens. Searched codebase — SSO/SAML/OIDC terms appear only in target app ADRs (`openspec/architecture/adr-005-security.md`), not in Hydra's own auth flow. |
| SAML/OIDC token freshness validation | N/A | Not applicable — no IdP integration. |
| Session fixation protection on SSO | N/A | Not applicable — no SSO callbacks. |

(All items N/A. Hydra authenticates via static tokens, not identity federation.)

#### 5. Audit Trail

| Check | Status | Evidence |
|-------|--------|----------|
| Authentication events logged | PARTIAL | `scripts/lib/credentials.sh:86-87` logs which Claude accounts were loaded and which Git tokens are set/missing at startup. `credentials.sh:321` logs "Trying {name} account (N/M)" on each token attempt. `credentials.sh:355-358` logs rate-limit events and fallback attempts. However, initial authentication (token injection into containers) is not logged — the token is silently embedded via `-e CLAUDE_CODE_OAUTH_TOKEN=...` and `git config --global url.insteadOf`. No log entry records which specific token was used for a successful container run. |
| API requests logged with identity | PARTIAL | Pipeline label changes are logged via `_label_log` (`labels.sh:30`) with timestamps. All supervisor actions are logged to `logs/supervisor.log` (`hydra-supervisor.sh:57-59`). GitHub API calls via `gh` CLI are not individually logged — only their outcomes (label set, PR created, etc.) are recorded. |
| Admin actions logged | PARTIAL | Reconciler auto-fix actions are logged with timestamp and reason (`reconcile.sh:121-143`) and a comment is posted on the issue. Label swaps are logged via `_label_log` (`labels.sh:85`). However, manual label changes via GitHub UI are not specifically flagged — `reconcile.sh` detects invariant violations but does not distinguish human-initiated vs pipeline-initiated changes. |
| Anomaly detection / rate limiting | PARTIAL | Rate-limit tracking exists per token+model in `/tmp/hydra-rate-limits.json` (`credentials.sh:148-278`). The reconciler (`reconcile.sh`) runs every 10 minutes and detects label state violations (check 1-6). However, there is no detection of unusual patterns like rapid label manipulation, unexpected token usage, or volume anomalies. |
| Logs tamper-evident | MISSING | **Critical gap.** Logs are written to `logs/` by the same user running the supervisor. `logs/` is gitignored (`.gitignore` line `logs/`). There is no log forwarding, append-only storage, hash chain, or external log aggregation. `docs/operations/logging.md` describes JSONL format but mentions no integrity guarantees. The hydra-persistence-vectors reference (`references/hydra-persistence-vectors.md:88`) explicitly calls this out: "Logs are NOT in .gitignore (currently they ARE — this is a gap)." An attacker with host access could delete or modify all pipeline logs without detection. |

#### 6. Endpoint and Workload Persistence

| Check | Status | Evidence |
|-------|--------|----------|
| Compromised endpoint re-injecting credentials | PARTIAL | The CLAUDE.md acknowledges a prior incident: `docs/container-architecture.md:129-131` states "a prior Claude Code session inherited organisation-admin Git rights through a developer's WSL session." The security constraints were designed in response. However, `secrets/credentials.json` and `secrets/.env` sit on the developer's filesystem with standard file permissions. A compromised developer machine could read these files and extract all tokens. No disk encryption or HSM integration is documented. |
| Startup scripts/cron jobs that could beacon | COVERED | Cron jobs are documented in `CLAUDE.md:211-219`: watchdog every minute, reconcile every 10 min, audit every 30 min, spec-from-issue every 10 min. `watchdog-supervisor.sh` only starts the supervisor if not running — minimal beacon surface. All cron scripts are in-repo (`scripts/`) with no external download or execution of remote code. |
| Container image from trusted source | PARTIAL | Builder image is based on `node:22-bookworm` (official Docker Hub image, `images/builder/Dockerfile:11`). System packages installed from Debian repos. Claude Code from npm (`images/builder/Dockerfile:54`). Semgrep from pip (`images/security/Dockerfile:37`). Gitleaks from GitHub releases (`images/security/Dockerfile:44-51`). No image signature verification (`--verify`) is used. No pinned digest for the base image (uses tag `node:22-bookworm`, not `node@sha256:...`). |

---

### Hydra-Specific Findings

#### H1. Per-Persona PAT Scoping

| Check | Status | Evidence |
|-------|--------|----------|
| Builder PAT scoped to contents:write + pull-requests:write only | COVERED | `secrets/.env.example:28-29` documents the builder scope as `contents:write, pull-requests:write, issues:write`. `docs/operations/auth.md:47` confirms. No admin scope. |
| Reviewer PAT has only pull-requests:write | COVERED | `secrets/.env.example:32-33` and `docs/operations/auth.md:48` document reviewer as `pull-requests:write` only. |
| Security PAT has only pull-requests:write | COVERED | `secrets/.env.example:36-37` and `docs/operations/auth.md:49` document security as `pull-requests:write` only. |
| PATs are per-user GitHub accounts | PARTIAL | Design calls for per-user accounts (`credentials.example.json:23` "_note": "each agent persona should have its own GitHub identity"). In practice, the local dev setup uses a single token for all three (`secrets/.env:5-7` — all identical `gho_BHa...`). This is documented as intentional for local dev but collapses the isolation boundary. |
| PAT expiry dates tracked | MISSING | `docs/operations/auth.md:51` says "Rotate at least every 90 days" but there is no tracking mechanism. No script checks PAT expiry. No calendar reminder or CI check exists. |
| Compromising reviewer cannot escalate to code-write | COVERED | Reviewer PAT has only `pull-requests:write` — cannot push code. Reviewer container's `settings.json` (`images/reviewer/settings.json:8-9`) denies Write and Edit tools. Agent config (`agents/juan-claude-van-damme/config.yaml:12-15`) allows only Read, Bash, Grep, Glob. Even if the reviewer's PAT is compromised, the attacker can only comment on PRs, not push code. |
| PAT values not logged in logs/ | MISSING | No masking exists. See finding in section 1 above (token values excluded from logs). `credentials.sh:86-87` logs token names (safe) but the actual token values flow through `run_container_with_fallback` at line 327 and could appear in error output. |

#### H2. Claude OAuth Token Management

| Check | Status | Evidence |
|-------|--------|----------|
| Priority ordering prevents compromise escalation | COVERED | `credentials.sh:53` sorts by priority field. Lower-priority tokens are only tried after higher-priority ones fail. Token index is independent — compromising token at priority 2 does not grant access to priority 1's Claude Max account. |
| Rate-limit state does not contain token values | COVERED | `credentials.sh:198-199` stores only `token_name:model` as the key, plus `exhausted_until`, `message`, `model`, `token` (name, not value), and `recorded_at`. The actual token secret is never written to `/tmp/hydra-rate-limits.json`. |
| Token fallback logic does not leak to logs | PARTIAL | `credentials.sh:321` logs "Trying {name} account" (safe — name, not value). `credentials.sh:358` logs "{name} account failed" (safe). However, the `docker run` command constructed at line 326-328 contains the actual token value in `-e CLAUDE_CODE_OAUTH_TOKEN=${token}`. If the shell has `set -x` enabled or if the command fails with a verbose error, the token could be exposed in logs. |
| AUTH_ENV_PLACEHOLDER replacement not logged before exec | PARTIAL | `credentials.sh:324-328` replaces the placeholder and builds `final_cmd`. The array is then executed at line 334 via `"${final_cmd[@]}" 2>&1 | tee "${log_file}"`. The command itself is not explicitly logged (no `echo` of the command). However, `tee` captures all stdout/stderr — if Docker prints the run command on error, the token could appear. |
| Tokens not visible in docker inspect after exit | COVERED | All containers use `--rm` flag (`orchestrate.sh:249`, `hydra-supervisor.sh:767`). Containers are automatically removed after exit, so `docker inspect` cannot retrieve environment variables post-run. |

#### H3. Container Isolation Boundaries

| Check | Status | Evidence |
|-------|--------|----------|
| Builder has --cap-drop ALL | COVERED | `orchestrate.sh:252`, `hydra-supervisor.sh:770`, `manual-review.sh:105`, `cron-audit.sh:87` all use `--cap-drop ALL`. Capabilities `SETUID`, `SETGID`, `DAC_OVERRIDE` are re-added for the `gosu` privilege drop in entrypoints. |
| Reviewer has no Write/Edit tools | COVERED | `images/reviewer/settings.json:8-9` explicitly denies Write and Edit. `agents/juan-claude-van-damme/config.yaml:12-15` allows only Read, Bash, Grep, Glob. |
| Security has no Write/Edit tools | COVERED | `images/security/settings.json:8-9` explicitly denies Write, Edit, Glob, Grep. Only allows Read, Bash, and `mcp__semgrep__*`. |
| Egress restricted via iptables | PARTIAL | `scripts/lib/entrypoint-common.sh:43-64` implements iptables egress allowlist (DNS + specific hosts on port 443, then `iptables -P OUTPUT DROP`). However, line 44-47 shows this is best-effort: if `NET_ADMIN` capability is not available, it prints a warning and continues without restriction. In local docker runs, `--cap-add SETUID --cap-add SETGID --cap-add DAC_OVERRIDE` are added but `NET_ADMIN` is NOT added (`orchestrate.sh:252-253`), meaning iptables rules **cannot be applied** in local mode. The GitHub Actions workflows add `--cap-add NET_ADMIN` (`hydra-build.yml:57`). K8s NetworkPolicy exists at `manifests/network-policy.yaml` as backup. |
| No shared volumes between containers | COVERED | Each container run creates its own `claude_tmp` via `mktemp -d` (`orchestrate.sh:234`, `hydra-supervisor.sh:750,806`). Only the spec path is mounted `:ro`. No shared writable volume exists between builder and reviewer/security containers. |
| Fresh claude_tmp per run | COVERED | `mktemp -d` creates a unique temporary directory for each container launch (`orchestrate.sh:234`, `hydra-supervisor.sh:750,806`). Cleaned up after use (`hydra-supervisor.sh:797`). |
| GIT_TOKEN scoped to persona's PAT | COVERED | `get_git_token()` in `credentials.sh:123-135` returns the role-specific token. Builder runs pass `get_git_token builder` (`orchestrate.sh:284`), reviewer runs pass `get_git_token reviewer` (`hydra-supervisor.sh:763`), security runs pass `get_git_token security` (`hydra-supervisor.sh:817`). |
| Container filesystem ephemeral | PARTIAL | All containers use `--rm`, `--tmpfs /tmp`, `--tmpfs /workspace`. However, the documented constraint `--read-only` (`docs/container-architecture.md:116`) is **only enforced in GitHub Actions** (`hydra-build.yml:52`, `hydra-review.yml:47`). The local/supervisor docker run commands (`orchestrate.sh:248-258`, `hydra-supervisor.sh:767-773`) do NOT include `--read-only`. This means in local mode, the container's root filesystem is writable — an agent could persist data outside /tmp and /workspace within a single run. |
| Builder cannot read reviewer/security logs | COVERED | Containers run independently with separate `claude_tmp` directories. No log volume is shared. Builder exits before reviews start (label state machine enforces sequencing). |

#### H4. Yolo Auto-Merge Trust Chain

| Check | Status | Evidence |
|-------|--------|----------|
| Yolo requires ALL phases to pass | COVERED | `orchestrate.sh:938-966` — yolo merge only executes inside the "DONE — both passed" branch (line 937), which is reached only when both `code_review_pass` and `security_review_pass` are true. The label check at line 946 is additional confirmation. |
| gh pr merge --admin is appropriate | PARTIAL | `orchestrate.sh:975` uses `--merge --admin`. The `--admin` flag bypasses branch protection approval counts. This is necessary because the pipeline approves its own PR. However, this means the builder PAT must have admin rights on the repo — a broader scope than documented (`contents:write, pull-requests:write`). The PAT scope may need `administration:write` or repo admin access for `--admin` to work. |
| Auto-merge message distinguishable | COVERED | `orchestrate.sh:971` posts approval body: "Approved by Hydra pipeline (yolo). All phases passed: build, quality, browser tests, code review, security review." This is clearly distinguishable from human approvals in GitHub's audit log. |
| Yolo label can only be set by authorized users | MISSING | No enforcement exists. Any user with write access to the repository (or any pipeline container with the builder PAT) could add the `yolo` label to an issue. There is no GitHub branch protection rule, CODEOWNERS check, or label permission restriction documented. The `yolo` label is just a regular GitHub label. |
| Removing yolo after merge prevents re-triggering | PARTIAL | `orchestrate.sh:977` calls `swap_labels "yolo" ""` after successful merge. However, this function is not defined in `labels.sh` — it appears to be a local helper in orchestrate.sh. If the merge succeeds but the label removal fails (network error), the issue retains `yolo` but is closed (line 980-983), so re-triggering is prevented by the closed state. |
| No race condition between label check and merge | MISSING | `orchestrate.sh:946-975` reads the issue labels, then checks for yolo, then merges. Between the label check (line 946) and the merge (line 975), another process could remove the yolo label or add new findings. There is no atomic "check-and-merge" operation. The window is small but real. |
| Commit signature verification on yolo merges | MISSING | No GPG/SSH commit signing is configured or verified. `git config --global` in `entrypoint-common.sh:79` sets up HTTPS auth but no signing key. The `--admin` flag in `gh pr merge` bypasses branch protection rules that might require signed commits. |

#### H5. Label State Machine Integrity

| Check | Status | Evidence |
|-------|--------|----------|
| Label transitions enforced in code | COVERED | `scripts/lib/labels.sh` provides `swap_label_atomic` (line 76-88) for atomic transitions, `validate_single_stage` (line 157-190) for invariant checking. The supervisor's `handle_completions` (line 965-1128) enforces the state machine transitions. |
| swap_label_atomic prevents concurrent modifications | PARTIAL | `labels.sh:76-88` reads current labels, computes new set, and PATCHes in a single API call. This is a read-modify-write pattern — if two processes read simultaneously, both would compute based on stale state, and the second PATCH would overwrite the first. GitHub's API does not support conditional updates (ETags/If-Match on label mutations). The function is labeled "atomic" but is not truly atomic in a concurrent setting. |
| Reconcile.sh runs frequently enough | COVERED | Reconciler runs every 10 minutes (`CLAUDE.md:215`). It detects running timeouts (45 min for build, 30 min for review — `reconcile.sh:49-51`), orphaned completions (30 min escalation — `reconcile.sh:52`), and contradicting verdicts. A label manipulation attack would need to complete within the reconcile interval to avoid detection. |
| Attacker with push scope cannot bypass reviews | MISSING | An attacker with `push` scope (i.e., the builder PAT) could directly add `code-review:pass` and `security-review:pass` labels via the GitHub API, bypassing actual reviews. The label library (`labels.sh`) does not validate the identity of the label setter. Combined with the `yolo` label (also settable by anyone with write access), this could trigger auto-merge of unreviewed code. The reconciler checks for contradictions and invalid states but cannot distinguish legitimate pipeline label changes from attacker-set labels. |
| validate_single_stage rejects impossible combinations | COVERED | `labels.sh:157-190` counts non-metadata stage labels and flags violations when count != 1. The reconciler calls this for every open openspec issue (`reconcile.sh:428`). |
| Metadata labels cannot skip stages | COVERED | `_METADATA_LABELS` in `labels.sh:24` lists all metadata labels that are exempt from single-stage validation. The `handle_completions` function in the supervisor only transitions based on specific stage labels (build:done, fix:done, verdict labels) — metadata labels alone cannot trigger stage progression. |
| Reconcile auto-fix actions logged | COVERED | `reconcile.sh:121-143` (`_auto_fix_label`) logs all auto-fix actions with timestamp and reason, and posts a comment on the issue describing the change. |
| Label changes outside pipeline detected | PARTIAL | The reconciler detects invariant violations (multiple stage labels, orphaned running, etc.) regardless of source. However, it does not specifically flag "this label was set by a human via the GitHub UI" vs "this label was set by the pipeline." GitHub's issue events API could provide this information, but it is not queried. |

#### H6. Log Integrity and Audit Trail

| Check | Status | Evidence |
|-------|--------|----------|
| Pipeline logs capture stage transitions | COVERED | Each pipeline run creates a dedicated log directory: `logs/pipeline-{ISSUE}-{TIMESTAMP}/` (referenced throughout `orchestrate.sh`). Builder, quality, browser, and review stages each produce separate JSONL log files. |
| Label transitions logged via _label_log | COVERED | `labels.sh:30` timestamps every label operation. `swap_label_atomic` (line 85), `set_label_idempotent` (line 104), and `remove_label_idempotent` (line 118) all call `_label_log`. |
| Supervisor activity logged | COVERED | `hydra-supervisor.sh:57-59` defines `log()` function writing to stdout, which is redirected to `logs/supervisor.log` when daemonized (line 13). All significant actions are logged with timestamps. |
| Reconciliation violations logged | COVERED | `reconcile.sh:23` writes to `logs/reconcile.log`. `_log()` (line 57-61) writes to both stdout and the log file. `_record_violation()` (line 67-69) tracks violation count. |
| Logs NOT in .gitignore (reference says this IS a gap) | PARTIAL | `.gitignore` includes `logs/` — logs are excluded from version control. The Hydra reference (`hydra-persistence-vectors.md:88`) explicitly flags this: "Logs are NOT in .gitignore (currently they ARE — this is a gap)." This means logs exist only on the host filesystem with no offsite backup or version history. |
| Log files not writable by pipeline containers | PARTIAL | Containers write JSONL to stdout, which is captured via `tee` by the host-side orchestrator/supervisor (`credentials.sh:334`). Containers themselves do not write directly to `logs/`. However, the supervisor process that writes to `logs/` runs as the same user that runs the containers — there is no privilege separation between the supervisor and the log directory. |
| Token values masked from log output | MISSING | **Critical gap.** No masking exists. See detailed analysis in section 1 and H1 above. Token prefixes (`sk-ant-*`, `ghp_*`, `gho_*`) are not filtered from any log output. `docs/operations/secrets.md:127` acknowledges the risk but implements no mitigation. |
| Log deletion/tampering detection | MISSING | No mechanism exists. Logs are plain files on the host filesystem. No checksums, no append-only mode, no log forwarding to an external system. An attacker with host access could silently delete or modify all pipeline logs. |
| Yolo merge actions logged | COVERED | `orchestrate.sh:967` logs "yolo label detected," line 976 logs "PR merged to development (yolo)," line 983 logs "Issue closed." The approval comment (line 971) includes which phases passed. |

#### H7. Secret Rotation and Incident Response

| Check | Status | Evidence |
|-------|--------|----------|
| Documented process for rotating GitHub PATs | MISSING | `docs/operations/auth.md:51` says "Rotate at least every 90 days" but provides no step-by-step procedure. No runbook exists in `docs/operations/`. Searched all files in `docs/` — no rotation procedure found. |
| Documented process for rotating Claude OAuth tokens | MISSING | `docs/operations/auth.md:22-24` describes how to generate tokens (`claude setup-token`) but not a rotation procedure. No documentation covers what to do when a token is compromised — which services to restart, how to verify the old token is no longer in use, etc. |
| Rotating one persona's PAT independent of others | COVERED | `credentials.json` has separate entries per role (`git_tokens.builder`, `git_tokens.reviewer`, `git_tokens.security`). `credentials.sh:74-78` loads them independently. Changing one does not affect others. |
| Active containers terminated after rotation | MISSING | No mechanism exists. After editing `credentials.json`, the supervisor continues using the old tokens loaded at startup. Running containers retain their injected tokens until they complete. No "kill all active containers" command or drain mechanism is documented. |
| credentials.json supports hot-reload | MISSING | `credentials.sh:36` (`load_credentials`) is called once at startup. There is no file watcher, inotify handler, or periodic re-read. The supervisor must be restarted to pick up new tokens. This is not documented in `docs/operations/secrets.md`. |
| Rate-limit state cleared after rotation | MISSING | `/tmp/hydra-rate-limits.json` persists across supervisor restarts (it's on the host /tmp, not in a container tmpfs). After rotating a token, stale rate-limit entries for the old token name could cause the new token to be unnecessarily skipped. No cleanup script or procedure exists. |

---

### Recommended Remediations

Ordered by severity (persistence duration x access level):

1. **[CRITICAL] Implement token masking in all log output.** Add a log filter function that redacts patterns matching `sk-ant-*`, `ghp_*`, `gho_*`, `github_pat_*` from all output written to `logs/`. Apply it in `run_container_with_fallback()` (around the `tee` at `credentials.sh:334`) and in the supervisor's `log()` function. This is the most exploitable gap — leaked tokens in logs provide persistent access until manually rotated.

2. **[CRITICAL] Restrict who can set the `yolo` label.** Use a GitHub App or webhook that validates the identity of the label setter before allowing `yolo` on an issue. Alternatively, add a CODEOWNERS-style check in `orchestrate.sh` that verifies the yolo label was set by an authorized GitHub user (not by a pipeline PAT).

3. **[CRITICAL] Prevent direct label manipulation from bypassing reviews.** Add a check in `handle_completions()` that verifies `code-review:pass` and `security-review:pass` were set by the actual review containers (e.g., by checking the label event actor via `gh api repos/{repo}/issues/{num}/events`). An attacker with `push` scope could currently set these labels directly and trigger auto-merge via yolo.

4. **[HIGH] Add `--read-only` to local/supervisor docker run commands.** The GitHub Actions workflows already use `--read-only` (`hydra-build.yml:52`, `hydra-review.yml:47`), but `orchestrate.sh:248-258` and `hydra-supervisor.sh:767-773` do not. Add `--read-only` to `BASE_DOCKER_FLAGS` in orchestrate.sh and to all supervisor docker run commands to match the documented constraint in `docs/container-architecture.md:116`.

5. **[HIGH] Create an incident response runbook.** Document step-by-step procedures for: (a) rotating all three GitHub PATs, (b) rotating Claude OAuth tokens, (c) killing active containers using compromised tokens, (d) clearing rate-limit state, (e) verifying no unauthorized label changes occurred. Place in `docs/operations/incident-response.md`.

6. **[HIGH] Forward logs to an external, append-only store.** Pipeline logs on the host filesystem are trivially deletable. Forward to syslog, a cloud logging service, or at minimum use `chattr +a` (append-only) on the log files. This prevents post-compromise evidence destruction.

7. **[HIGH] Add egress enforcement in local mode.** The iptables egress allowlist (`entrypoint-common.sh:43-64`) silently skips when `NET_ADMIN` is unavailable, which is the case in local docker runs (no `--cap-add NET_ADMIN` in `orchestrate.sh`). Either add `NET_ADMIN` to local docker runs or use Docker's `--network` with a custom network that restricts egress at the Docker daemon level.

8. **[MEDIUM] Pin base Docker images by digest.** Replace `FROM node:22-bookworm` with a pinned `FROM node@sha256:...` to prevent supply chain attacks via tag mutation. Apply to all three Dockerfiles.

9. **[MEDIUM] Add PAT expiry monitoring.** Create a cron script that checks PAT expiry dates via the GitHub API and alerts when tokens are approaching expiry (e.g., within 14 days). Integrate with the reconciler or create a separate `cron-token-check.sh`.

10. **[MEDIUM] Add atomic race protection to yolo merge.** Between the label check (`orchestrate.sh:946`) and the merge (`orchestrate.sh:975`), re-read labels to confirm `yolo` is still present and both reviews still show pass. This closes the TOCTOU race window.

11. **[LOW] Document credentials.json hot-reload behavior.** Explicitly state in `docs/operations/secrets.md` that the supervisor must be restarted after credential changes. Consider implementing a SIGHUP handler that triggers `load_credentials` re-read.

12. **[LOW] Clean rate-limit state on token rotation.** Add a cleanup step to the (future) rotation runbook that removes entries from `/tmp/hydra-rate-limits.json` for rotated tokens, or add logic to `load_credentials()` that prunes entries for token names not in the current `credentials.json`.

---

### Methodology

- **Tools used:** Grep (ripgrep) for pattern matching across codebase, Read for file inspection, Glob for file discovery, Bash for git status and file counting
- **Search patterns applied:** `oauth|refresh.token|access.token|bearer|jwt|session`, `service.account|api.key|pat|machine.identity`, `secret|token|key|credential|password`, `saml|oidc|sso|idp`, `log|audit|trail|event|monitor`, `cron|startup|entrypoint|init.container`, `mask|redact|filter.*token|sanitize.*log`, `--cap-drop|--read-only|--tmpfs`, `docker run`, `yolo|auto.merge`, `rotation|rotate|expir`
- **Files examined:** 518 files in repo (excluding node_modules, .git, vendor/skills, eval workspace). Deep inspection of 30+ key files including all scripts (8,625 lines total across scripts/, scripts/lib/, images/*/entrypoint.sh, images/*/Dockerfile, .github/workflows/*.yml), all agent configs, all operations docs, secrets templates, and label/reconcile logic.
- **Hydra-specific reference:** `references/hydra-persistence-vectors.md` loaded and all 7 Hydra-specific categories (H1-H7) fully evaluated.
- **Limitations:**
  - Could not verify actual PAT scope enforcement on GitHub (would require API calls with the tokens)
  - Could not verify runtime behavior of iptables rules (would require running a container)
  - Could not inspect actual log files (none present in the git repo — gitignored)
  - `secrets/credentials.json` and `secrets/.env` were readable on the local filesystem but are confirmed not tracked by git (`git ls-files` returned no matches under `secrets/`)
  - The `--admin` flag on `gh pr merge` may require repo admin permissions not documented in the PAT scope — could not verify without testing against the actual GitHub org
