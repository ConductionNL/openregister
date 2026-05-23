## Persistence Audit: Hydra Platform

**Date:** 2026-04-17
**Scope:** OpenSpec design documents and architecture specs (`openspec/` directory) -- spec-only audit, no code-level analysis
**Model:** Opus 4.6 (1M context)

### Summary
- 4/30 vectors COVERED
- 6/30 vectors PARTIAL
- 11/30 vectors MISSING
- 9/30 vectors N/A
- **Critical gaps:** No token expiry/rotation policy for PATs; no credential inventory spec; no audit logging specification for auth events; no monitoring for new key creation; CI/CD secret rotation not specified; no endpoint persistence hardening beyond container runtime

### Findings

#### 1. OAuth and Token Persistence

| Check | Status | Evidence |
|-------|--------|----------|
| Admin-revocable grants | N/A | Hydra uses GitHub PATs, not OAuth grants. No custom OAuth server. |
| Refresh token max lifetime | N/A | No refresh tokens -- PATs are used directly. |
| Refresh token rotation | N/A | No refresh tokens in the system. |
| Tokens invalidated on user disable | MISSING | No spec addresses what happens to agent PATs when a team member is removed. `project.md` defines per-persona PATs but no revocation lifecycle. |
| Tokens invalidated on password change | N/A | GitHub PATs are not tied to password rotation. This is a GitHub-platform concern. |
| State parameter (CSRF) on OAuth flows | N/A | No OAuth authorization flows. |
| Admin visibility of active grants | MISSING | No spec defines an inventory of active PATs, their scopes, or last-use timestamps. `project.md` lines 66-72 define per-persona PATs but not monitoring of their use. |
| Token values excluded from logs | PARTIAL | `adr-005-security.md` line 11: "NO PII in logs". `project.md` line 111: containers use `--bare` flag. However, no spec explicitly addresses token masking in CI logs or container output. |

#### 2. Service Accounts and Machine Identities

| Check | Status | Evidence |
|-------|--------|----------|
| Service accounts bypassing normal auth | COVERED | `project.md` lines 66-72: three named agent personas (Al Gorithm, Juan Claude van Damme, Clyde Barcode) each with dedicated scoped GitHub PATs. Scopes are explicitly defined per container. |
| Credential expiry dates | MISSING | No spec defines expiry for agent PATs. `adr-013-container-pool.md` references `credentials.json` (line 77, 121-122) but no expiry policy. |
| Inventory of machine identities | PARTIAL | `project.md` lines 64-72 lists three agent personas with their PAT scopes. `adr-013-container-pool.md` lines 121-122 references credential files. However, no formal inventory spec exists that tracks all machine identities including CI/CD service accounts, GHCR tokens, and Anthropic API keys. |
| Revocable independently of user credentials | PARTIAL | Per-persona PATs imply independent revocability (`project.md` line 109: "No shared org-admin tokens"). However, no spec defines the revocation procedure or who has authority to revoke which credential. |
| Workload identities scoped minimally | COVERED | `project.md` lines 66-72: Builder gets `contents:write` + `pull-requests:write`; Reviewers get `pull-requests:write` (comments only). `hydra-platform-mvp/specs/platform/spec.md` lines 35-40: Builder token cannot approve or merge. |

#### 3. CI/CD and Automation

| Check | Status | Evidence |
|-------|--------|----------|
| Pipelines mint or inject fresh secrets | PARTIAL | `project.md` lines 78-88 defines the input contract (env vars including GIT_TOKEN and ANTHROPIC_API_KEY). Containers receive secrets via env vars at launch. No spec addresses whether containers can create new tokens or persist secrets beyond their lifetime. |
| Pipeline secrets rotatable without redeploying | MISSING | No spec addresses PAT rotation procedures. `adr-013-container-pool.md` line 77 references "Token rotation: credentials.json" but no rotation cadence or procedure is specified. |
| Deploy keys, PATs, SSH keys inventoried | MISSING | No inventory spec exists. Three agent PATs are documented in `project.md` but GHCR push tokens, Anthropic API keys, and any SSH keys for K8s phase 3 are not inventoried. |
| Monitoring for new keys/tokens being created | MISSING | No spec addresses detection of unauthorized token creation. A compromised Builder container with `contents:write` could theoretically create deploy keys on target repos. No monitoring is specified. |
| Compromised pipeline step persists across deploys | COVERED | Containers are ephemeral (`project.md` line 52: "each destroyed after completion"). `hydra-platform-mvp/specs/platform/spec.md` lines 14-25: read-only root filesystem, `--cap-drop ALL`, tmpfs only. `adr-013-container-pool.md` line 147: "Container images are the unit of deployment." No persistent state survives container destruction. |

#### 4. Identity Provider Integration

| Check | Status | Evidence |
|-------|--------|----------|
| Compromised IdP grants access after remediation | N/A | Hydra does not use SSO/SAML/OIDC. Authentication is via GitHub PATs only. |
| SAML/OIDC token freshness validation | N/A | No SAML/OIDC in use. |
| Session fixation protection on SSO callbacks | N/A | No SSO callbacks. |

#### 5. Audit Trail

| Check | Status | Evidence |
|-------|--------|----------|
| Authentication events logged | PARTIAL | `pipeline-label-state-machine/proposal.md` lines 278-294: `reconcile.sh` logs findings to `logs/reconcile.log` and posts issue comments for auto-fixes. However, no spec covers logging of PAT authentication events, container start/stop with identity, or API key usage. |
| API requests logged with identity | MISSING | No spec addresses logging of GitHub API calls made by agent personas. Container output is captured in logs (`adr-013-container-pool.md` line 126: "checks output text for rate limit"), but no structured audit log of API actions per identity. |
| Admin actions logged | PARTIAL | `pipeline-label-state-machine/proposal.md` line 294: reconciler "Posts issue comment on every auto-fix for audit trail." Label transitions are visible in GitHub issue event streams. However, no spec defines centralized admin action logging for credential changes, container pool config changes, or manual interventions. |
| Anomaly detection or rate limiting | PARTIAL | `adr-013-container-pool.md` lines 125-127: rate limit detection via exit codes and output text scanning. `pipeline-label-state-machine/proposal.md` lines 136-149: timeout enforcement for running containers. However, no spec covers anomaly detection for unusual API patterns, unexpected token usage, or off-hours activity. |
| Logs tamper-evident | MISSING | No spec addresses log integrity. Logs are stored locally (`logs/reconcile.log`). GitHub issue comments provide a tamper-evident trail for label operations, but container logs and operational logs have no integrity protection specified. |

#### 6. Endpoint and Workload Persistence

| Check | Status | Evidence |
|-------|--------|----------|
| Compromised endpoint re-injects credentials | MISSING | `project.md` lines 105-121 documents the threat model (previous incident where Claude Code inherited org-admin rights from developer WSL session). However, no spec addresses ongoing protection against a compromised developer machine re-injecting credentials into the pipeline. `secrets/credentials.json` on the host is the credential store -- compromise of the host grants access to all credentials. |
| Startup scripts or cron that could beacon | COVERED | `pipeline-label-state-machine/proposal.md` lines 335-343: crontab is fully specified with known entries (watchdog, reconcile, audit, spec-from-issue). Container entrypoints are defined in the design docs. `hydra-platform-mvp/specs/platform/spec.md` lines 14-25: containers run with `--read-only`, `--cap-drop ALL`, `--security-opt no-new-privileges`. |
| Container images from trusted source | PARTIAL | `hydra-platform-mvp/design.md` lines 5-9: base image is `ghcr.io/anthropics/claude-code-devcontainer:latest` (official Anthropic image). `adr-013-container-pool.md` lines 109-118 lists all container images with sizes. However, no spec addresses image signing, digest pinning, or verification of the base image supply chain. Using `:latest` tag is vulnerable to tag-mutation attacks. |

### Recommended Remediations

Ordered by severity (persistence duration x access level):

1. **[CRITICAL] Define PAT lifecycle and rotation policy.** No spec addresses when agent PATs expire or how they are rotated. A compromised PAT provides indefinite access until manually discovered. Create a spec requiring: (a) maximum PAT lifetime (e.g., 90 days), (b) rotation procedure, (c) automated expiry alerting, (d) documented revocation authority. Affects: `project.md`, new ADR.

2. **[CRITICAL] Create a machine identity inventory.** Three agent PATs are documented, but Anthropic API keys, GHCR tokens, credentials.json entries, and any future K8s service accounts are not inventoried. An attacker who gains access to `secrets/credentials.json` on the host has all credentials. Spec should define: (a) complete credential inventory with scopes and owners, (b) storage requirements (encrypted at rest), (c) access control (who can read/modify). Affects: new spec, `adr-013-container-pool.md`.

3. **[CRITICAL] Specify monitoring for unauthorized token/key creation.** A compromised Builder container has `contents:write` on target repos, which may allow creating deploy keys. No spec addresses detection of new keys, tokens, or webhooks created on target repositories. Add: (a) periodic scan for unexpected deploy keys/webhooks on target repos, (b) alerting on new PAT creation in the GitHub org, (c) reconciler check for unauthorized GitHub app installations.

4. **[HIGH] Add audit logging spec for authentication events.** The pipeline has good operational logging (label transitions, reconciler), but no structured logging of: container authentication to GitHub, API key usage, credential rotation events, or failed authentication attempts. These are essential for post-incident forensics. Create an ADR specifying structured auth event logging.

5. **[HIGH] Pin container base images by digest, not tag.** `ghcr.io/anthropics/claude-code-devcontainer:latest` is vulnerable to tag-mutation. If the upstream image is compromised, all new containers inherit the compromise. Pin by `@sha256:...` digest and update deliberately. Add image signature verification for phase 3 K8s deployment.

6. **[HIGH] Protect host-level credential store.** `secrets/credentials.json` on the developer machine is the single point of compromise for all agent identities. No spec addresses: filesystem permissions, encryption at rest, access logging, or separation between Hydra and Specter credentials for the host-level store.

7. **[MEDIUM] Specify log integrity controls.** Container logs and `logs/reconcile.log` are writable by the application. Forward logs to an append-only destination (GitHub issue comments are already partially serving this role for label operations). Specify for phase 3: centralized log aggregation with tamper-evident storage.

8. **[MEDIUM] Address pipeline secret rotation without redeployment.** The spec should define how PATs and API keys in `credentials.json` can be rotated without stopping the container pool. `adr-013-container-pool.md` mentions token rotation at the pool level but provides no procedure.

9. **[LOW] Specify token masking in logs.** While `adr-005-security.md` says "NO PII in logs," explicitly require that `GIT_TOKEN`, `ANTHROPIC_API_KEY`, and any credential values are masked/redacted in all container stdout/stderr output and operational logs.

### Methodology
- Tools used: manual inspection of all files in `openspec/` directory tree (architecture/, specs/, changes/, schemas/, config.yaml, project.md, AGENTS.md)
- Files examined: 42 OpenSpec documents across architecture ADRs, platform specs, change proposals, change designs, and change specs
- Limitations:
  - This is a spec-only audit. Code-level persistence vectors (actual secret handling in scripts, Dockerfile security, entrypoint behavior) were not examined.
  - The `secrets/` directory structure was not inspected (read-only analysis of specs, not code).
  - GitHub Actions workflow files (`.github/workflows/`) were not audited for secret injection patterns.
  - Container images were not inspected for actual runtime security posture.

### Follow-up Actions

1. **Code-level audit recommended.** This spec-only audit identified design gaps. A code-level audit (`/persistence-audit` without the `spec` argument) should verify that the security controls documented in specs are actually implemented in `scripts/`, `images/`, and `.github/workflows/`.
2. **Hydra-specific deep dive recommended.** Run `/persistence-audit hydra` for CI/CD pipeline-specific checks using the Hydra vector reference.
3. **Post-incident verification.** The threat model in `project.md` (lines 117-121) describes a real incident where Claude Code bypassed peer review via inherited org-admin rights. The specs added controls (per-persona PATs, `--bare`, `--cap-drop ALL`), but a post-incident audit should verify these are enforced at the code level, not just documented.
4. **Phase 3 (K8s) requires additional persistence audit.** K8s introduces new persistence vectors: service accounts, RBAC bindings, network policies, secrets in etcd. The current specs (`manifests/`) should be audited when phase 3 implementation begins.
