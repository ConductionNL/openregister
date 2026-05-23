## Persistence Audit: Hydra — Agentic CI/CD Pipeline

**Date:** 2026-04-17
**Scope:** Full repository audit (`/home/wilco/hydra`) — code, scripts, config, specs, container images, CI/CD workflows
**Mode:** Post-incident (PARTIAL treated as effectively MISSING in severity scoring)
**Model:** Claude Opus 4.6 (1M context)

### Summary

- 5/28 vectors COVERED
- 10/28 vectors PARTIAL
- 10/28 vectors MISSING
- 3/28 vectors N/A
- **Critical gaps:** No token expiry enforcement on PATs or OAuth tokens; no inventory of machine identities; no monitoring for new token creation; CI/CD pipelines can mint fresh secrets; no admin audit dashboard; logs are application-writable and lack tamper evidence; `--read-only` filesystem claimed in docs but not enforced in any `docker run` command; no anomaly detection or rate limiting on auth events; cron jobs run as the same user with full credential access

### Findings

#### 1. OAuth and Token Persistence

| Check | Status | Evidence |
|-------|--------|----------|
| Admin-revocable grants | PARTIAL | Per-agent PATs can be revoked individually (`docs/operations/auth.md:43-49`), but Claude OAuth tokens in `credentials.json` have no admin revocation mechanism — they are static strings rotated manually. No central admin UI. |
| Refresh token max lifetime | MISSING | Claude OAuth tokens have ~24h expiry when static (`docs/operations/auth.md:13`), but `credentials.json` tokens have no enforced expiry field. The example file has no `expires_at` field (`secrets/credentials.example.json`). An attacker who exfiltrates `credentials.json` has indefinite access until manual rotation. |
| Refresh token rotation | PARTIAL | `run_container_with_fallback()` in `scripts/lib/credentials.sh:288-381` cycles through tokens on rate limit, but this is availability fallback, not security rotation. Tokens are reused across runs without rotation. |
| Tokens invalidated on account disable | MISSING | No mechanism found. Searched `scripts/`, `docs/`, `images/`. Disabling a GitHub user account would invalidate their PAT, but Claude OAuth tokens have no tied account-disable flow. |
| Tokens invalidated on password change | MISSING | No mechanism found. Claude OAuth tokens and GitHub PATs persist through password changes. |
| CSRF state parameter on OAuth flows | N/A | Hydra does not implement OAuth authorization flows — it consumes pre-provisioned tokens. No OAuth callback endpoints exist. |
| Admin view of active grants with timestamps | MISSING | No dashboard or inventory of active tokens. `credentials.json` has no `last_used` tracking. Rate limit state (`/tmp/hydra-rate-limits.json`, `credentials.sh:148`) tracks exhaustion times but not usage audit. |
| Token values excluded from logs | PARTIAL | `logs/` is gitignored (`.gitignore`), and `docs/operations/secrets.md:89` warns logs may contain token prefixes. However, `credentials.sh:321` prints token account names to stdout (not values), and `entrypoint.sh:30` embeds tokens in git config URLs that could appear in error output. No active scrubbing of token values from JSONL logs. |

#### 2. Service Accounts and Machine Identities

| Check | Status | Evidence |
|-------|--------|----------|
| Service accounts bypassing normal auth | COVERED | Each agent persona (builder, reviewer, security) has its own scoped GitHub PAT (`docs/operations/auth.md:43-49`). Scopes are minimal: builder gets `contents:write + pull-requests:write`, reviewers get `pull-requests:write` only. |
| Service account credential expiry | PARTIAL | `docs/operations/auth.md:51` recommends "Rotate at least every 90 days" but there is no enforcement mechanism — no expiry date in `credentials.json`, no automated rotation, no alerting on stale tokens. |
| Inventory of machine identities | MISSING | No formal inventory. `credentials.example.json` documents 3 Git PATs and 2 Claude accounts, but there is no live inventory, no tracking of which tokens are active, and no alert when new tokens are added. |
| Independent revocation of machine credentials | COVERED | Git tokens are per-role (`credentials.sh:123-135`). Revoking the reviewer PAT does not affect builder or security. Claude accounts are indexed separately (`credentials.sh:92-106`). |
| Workload identity minimal scoping | PARTIAL | GitHub PATs are minimally scoped per agent (`docs/operations/auth.md:43-49`). However, Claude OAuth tokens are not scoped per agent — any token in `claude_accounts[]` can be used by any container type. A single compromised Claude token grants access to all pipeline stages. |

#### 3. CI/CD and Automation

| Check | Status | Evidence |
|-------|--------|----------|
| Pipelines can mint/inject fresh secrets | PARTIAL | GitHub Actions workflows inject secrets via `${{ secrets.HYDRA_* }}` (`hydra-build.yml:60-61`, `hydra-review.yml:55-56`). The builder container has `contents:write` scope and could theoretically create new deploy keys on target repos via the GitHub API. No restriction on the builder calling `gh api` to create new PATs or deploy keys. |
| Pipeline secrets rotatable without redeploy | PARTIAL | GitHub Actions secrets can be rotated via the GitHub UI without redeploying. Local `credentials.json` requires manual edit + supervisor restart. K8s path (`docs/operations/auth.md:36-38`) requires `kubectl` secret update. No zero-downtime rotation mechanism. |
| Deploy keys/PATs/SSH keys inventoried | MISSING | No inventory. The `credentials.example.json` documents expected tokens but there is no audit of what actually exists on GitHub. No script to list all PATs, deploy keys, or SSH keys across the org. |
| Monitoring for new keys/tokens being created | MISSING | No monitoring. A compromised builder container could use its `GIT_TOKEN` to create additional deploy keys on target repos. No GitHub audit log monitoring, no webhook alerting on key creation events. |
| Compromised pipeline step persisting across deploys | PARTIAL | Containers use `--tmpfs` for workspace (`scripts/orchestrate.sh:250-251`, `scripts/hydra-supervisor.sh:768-769`), so filesystem changes are ephemeral. However, a compromised builder could push malicious commits to the target repo, add GitHub Actions workflows, or create webhooks that persist beyond container teardown. The `--cap-drop ALL` (`orchestrate.sh:252`) limits kernel-level persistence. |

#### 4. Identity Provider Integration

| Check | Status | Evidence |
|-------|--------|----------|
| Compromised IdP granting access | N/A | Hydra does not use SSO/SAML/OIDC for its own authentication. All auth is via pre-provisioned PATs and Claude OAuth tokens. SSO/OIDC terms appear only in vendored skills and target app ADRs, not in Hydra's own auth flow. |
| SAML/OIDC token freshness validation | N/A | Not applicable — no IdP integration. |
| Session fixation protection on SSO | N/A | Not applicable — no SSO callbacks. |

#### 5. Audit Trail

| Check | Status | Evidence |
|-------|--------|----------|
| Authentication events logged | PARTIAL | Claude CLI produces JSONL logs with session init events (`docs/operations/logging.md:20-22`). Git token usage is logged indirectly via `[credentials]` prefixed messages (`credentials.sh:86-87, 321`). However, there is no centralized auth event log — events are spread across per-run JSONL files and supervisor stdout. No log of token grant, refresh, or revoke events. |
| API requests logged with identity | PARTIAL | Each pipeline run produces a JSONL log file (`logs/<stage>-<timestamp>.jsonl`) with the Claude session. GitHub API calls are not independently logged — they occur inside the Claude agent session and are captured only as tool-use events in the JSONL stream. No structured API request log with caller identity. |
| Admin actions logged | MISSING | No logging of credential changes (editing `credentials.json`), label state transitions by humans, or configuration changes. The supervisor logs label changes it makes (`hydra-supervisor.sh:985-1119`) but not human-initiated changes. |
| Anomaly detection / rate limiting | PARTIAL | Rate limit tracking exists (`credentials.sh:140-278`) but is purely for availability (token fallback), not security. No detection of unusual patterns like: builder creating deploy keys, reviewer pushing code, multiple simultaneous sessions with the same token, or token use from unexpected IPs. |
| Logs tamper-evident | MISSING | Logs are written to `logs/` directory by the same user running the pipeline (`tee "${log_file}"` in `credentials.sh:334`). No log signing, no append-only storage, no forwarding to immutable storage. An attacker with pipeline access could modify or delete logs. `logs/` is gitignored so log tampering leaves no trace in version control. |

#### 6. Endpoint and Workload Persistence

| Check | Status | Evidence |
|-------|--------|----------|
| Compromised endpoint re-injecting credentials | PARTIAL | `credentials.json` lives on the host filesystem at `secrets/credentials.json` and is read at startup (`credentials.sh:37`). A compromised developer machine with access to this file can inject modified tokens. The file is gitignored but not encrypted at rest. No integrity checking on the credentials file between runs. |
| Startup scripts/cron jobs that could beacon | COVERED | Cron jobs are documented and intentional (`CLAUDE.md:213-219`): watchdog every 1 min, reconcile every 10 min, audit every 30 min, spec-from-issue every 10 min. All cron scripts are in `scripts/` and checked into version control. An attacker would need host access to modify crontab. |
| Container built from trusted/verified source | COVERED | Dockerfiles are in `images/` and checked into version control. GitHub Actions workflow (`hydra-image.yml`) builds from committed Dockerfiles and pushes to GHCR. Base images are built locally from `images/nextcloud-test/Dockerfile`. However, no image signing (cosign/notation) or SBOM generation was found. |

### Recommended Remediations

Ordered by severity (persistence duration x access level):

1. **[CRITICAL] Enforce token expiry and automated rotation.** `credentials.json` has no expiry fields. Add `expires_at` to each token entry, and add a pre-flight check in `load_credentials()` that refuses to use expired tokens. Implement automated PAT rotation (GitHub fine-grained PATs support expiry). For Claude OAuth tokens, integrate with the CLI refresh flow rather than storing static tokens.

2. **[CRITICAL] Create a machine identity inventory with alerting.** Build a script that enumerates all GitHub PATs, deploy keys, and OAuth apps across the `ConductionNL` org. Run it on a schedule and alert on unexpected additions. This is the primary way an attacker persists after credential rotation.

3. **[CRITICAL] Monitor for unauthorized key/token creation.** A compromised builder container can use its `GIT_TOKEN` to create deploy keys on target repos via the GitHub API. Add a GitHub org webhook or audit log monitor that alerts on `deploy_key.created`, `personal_access_token.created`, and `oauth_authorization.created` events.

4. **[CRITICAL] Make logs tamper-evident.** Forward pipeline logs to an append-only destination (cloud logging, syslog to a separate host, or signed log files). The current setup allows an attacker with host access to modify `logs/` without detection.

5. **[CRITICAL] Scope Claude OAuth tokens per agent role.** Currently any `claude_accounts[]` token can be used by any container type. If one token is compromised, all pipeline stages are compromised. Use separate Claude accounts per role (builder vs. reviewer) to limit blast radius.

6. **[HIGH] Enforce `--read-only` root filesystem.** CLAUDE.md line 5 of Security Constraints claims "Read-only root filesystem (`--read-only`, `--tmpfs /tmp`)" but no `docker run` command in `scripts/` includes `--read-only`. Add `--read-only` to all container invocations in `orchestrate.sh`, `hydra-supervisor.sh`, `manual-review.sh`, `dev-run.sh`, and `cron-audit.sh`.

7. **[HIGH] Add admin action audit logging.** Log all changes to `credentials.json`, `.env`, label state transitions (especially manual ones), and crontab modifications. Consider a git hook or file integrity monitor on the `secrets/` directory.

8. **[HIGH] Restrict builder container API scope.** The builder's `GIT_TOKEN` has `contents:write` which allows creating deploy keys and webhooks on target repos. Consider using a GitHub App installation token with narrower permissions (just push to specific branches) instead of a PAT.

9. **[MEDIUM] Add anomaly detection on pipeline behavior.** Flag unusual patterns: builder creating API resources, reviewer pushing commits, same token used from multiple IPs simultaneously, containers running longer than expected timeouts.

10. **[MEDIUM] Encrypt credentials at rest.** `secrets/credentials.json` contains plaintext tokens. Consider SOPS, age, or Vault integration for at-rest encryption with a passphrase/key that is not stored alongside the file.

11. **[LOW] Add container image signing.** Use cosign or notation to sign images pushed by `hydra-image.yml`. Verify signatures before running containers in the pipeline.

### Methodology

- **Tools used:** Grep (ripgrep) across full repository for auth, token, secret, credential, log, cron, entrypoint patterns; manual file inspection of all scripts in `scripts/`, `scripts/lib/`, `images/*/entrypoint.sh`, `images/*/Dockerfile`, `.github/workflows/`, `manifests/`, `docs/operations/`, `secrets/`
- **Files examined:** ~45 files across scripts, config, docs, workflows, manifests, and container definitions
- **Limitations:**
  - `secrets/credentials.json` is gitignored — could not inspect actual token values or verify expiry claims
  - Dockerfiles were not deeply inspected for USER directives (grep returned no matches for `^USER` in images/)
  - GitHub org-level settings (branch protection, required reviews, audit log retention) are external to this repo and could not be verified
  - Runtime behavior (actual iptables rules, container isolation) could not be verified without running containers

### Follow-up Actions

1. **Verify COVERED items are actually enforced, not just documented.** In post-incident mode, documentation is insufficient evidence. Specifically:
   - Verify per-agent PAT scoping by checking actual GitHub token permissions via `gh api user` with each token
   - Verify `--cap-drop ALL` is actually applied in production crontab invocations (not just in checked-in scripts)
   - Verify cron jobs match what is documented — compare actual `crontab -l` output against CLAUDE.md
   - Verify `.gitignore` rules are effective — check that no secrets have been committed in git history (`git log --all -p -- secrets/`)

2. **Run with the `hydra` argument** for deeper CI/CD pipeline analysis using the Hydra-specific vector reference.

3. **Re-audit after remediations** — the 10 PARTIAL and 10 MISSING vectors represent significant persistence surface. In post-incident context, an attacker who had access to `credentials.json` or any PAT could maintain access through multiple independent paths.
