---
name: persistence-audit
description: Audit a codebase or architecture for post-compromise persistence vectors — OAuth grants, service accounts, CI/CD secrets, IdP trust, audit trails, and endpoint persistence
metadata:
  category: Security
  tags: [security, audit, persistence, oauth, tokens, incident-response, ci-cd]
---

# Persistence Audit

Audit whether an attacker who gained initial access could maintain it after standard containment (credential rotation, session invalidation, MFA enforcement).

Core principle: **rotating secrets is containment, not eradication.** Changing credentials does not remove trust that was already granted through other paths.

## Model Recommendation

This skill requires nuanced security judgment — incomplete analysis can leave real persistence vectors undetected.

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- If the active model is **Haiku or any model other than Sonnet or Opus**: stop immediately and tell the user:
  > "This skill requires Sonnet or Opus minimum — persistence analysis needs stronger reasoning than Haiku can reliably provide. Please switch models with `/model sonnet` or `/model opus` and re-run."

- If the active model is **Sonnet or Opus**: proceed.

## Guardrails

- **Never mark a vector COVERED without citing specific code, config, or spec evidence.** A missing file or unchecked path is PARTIAL or MISSING, never COVERED.
- **Never skip a vector category.** If the system has no OAuth code, mark the entire section N/A with a brief explanation — do not silently omit it.
- **Never recommend disabling security controls** (audit logging, token rotation, RBAC) as a remediation.
- **Never expose actual secret values** (tokens, keys, passwords) in the audit report. Reference file paths and variable names only.
- **Never modify code or configuration.** This skill is read-only analysis. Suggest remediations but do not apply them.
- **Always complete all 6 vector categories** before generating the report. Partial audits are worse than no audit.

## Input

Accept an optional argument:
- No argument → audit the current repository
- `<path>` → audit a specific directory or file
- `<PR URL or number>` → audit the diff of a pull request for persistence vectors introduced or missed by the change
- `hydra` → audit using the Hydra-specific vector reference (CI/CD pipeline focus)
- `spec` → audit against OpenSpec design documents and architecture specs rather than code
- `post-incident` → run with heightened sensitivity: treat PARTIAL as effectively MISSING in severity scoring

## Step 1: Determine audit scope

1. Identify the system under audit:
   - Read `README.md`, `CLAUDE.md`, or `project.md` for system description
   - Check for `openspec/` directory — if present, read architecture specs
   - Check for `docker-compose.yml`, `Dockerfile`, `.github/workflows/` to understand deployment model
2. Determine which vector categories apply:
   - Has auth/OAuth code? → Category 1 applies
   - Has service accounts, PATs, API keys? → Category 2 applies
   - Has CI/CD pipelines, deploy scripts? → Category 3 applies
   - Uses SSO/SAML/OIDC? → Category 4 applies
   - Has logging infrastructure? → Category 5 applies
   - Has containers, cron jobs, startup scripts? → Category 6 applies
3. If the `hydra` argument was passed, load the Hydra-specific reference at [references/hydra-persistence-vectors.md](references/hydra-persistence-vectors.md) and apply those checks in addition to the generic checklist.
4. If a **PR URL or number** was passed:
   - Fetch the PR diff using `gh pr diff <number> --repo <owner/repo>` (or extract owner/repo from the URL)
   - Read the PR description and changed files list with `gh pr view <number> --repo <owner/repo>`
   - Focus the audit on persistence vectors **introduced, modified, or left unaddressed** by the change
   - Also check the surrounding code context for each changed file — a PR that touches auth code may miss persistence implications in adjacent code
   - In the report, distinguish between "vectors introduced by this PR" and "pre-existing vectors visible in the PR context"

## Step 2: Scan for evidence per vector

For each applicable category, actively search the codebase. Do not rely on file listings alone.

### 2a. OAuth and Token Persistence

**What to search for:**
```
grep -ri "oauth\|refresh.token\|access.token\|bearer\|jwt\|session" --include="*.{php,js,ts,py,rb,go,yaml,yml,json,env}"
```

**Checklist:**
- [ ] Are OAuth grants revocable by admins (not just users)?
- [ ] Do refresh tokens have a maximum lifetime?
- [ ] Is refresh token rotation enforced (new refresh token on each use)?
- [ ] Are tokens invalidated when a user account is disabled/deleted?
- [ ] Are tokens invalidated when a user changes their password?
- [ ] Is there a `state` parameter (CSRF protection) on OAuth flows?
- [ ] Can an admin see all active OAuth grants with last-use timestamps?
- [ ] Are token values excluded from all logs?

### 2b. Service Accounts and Machine Identities

**What to search for:**
```
grep -ri "service.account\|api.key\|pat\|machine.identity\|bot\|app.password" --include="*.{php,js,ts,py,yaml,yml,json,env,sh}"
```
Also check: `.env*`, `secrets/`, `credentials*`, `docker-compose*.yml` for credential definitions.

**Checklist:**
- [ ] Are there service accounts or app passwords that bypass normal auth?
- [ ] Do service account credentials have expiry dates?
- [ ] Is there an inventory of all machine identities?
- [ ] Can these be revoked independently of user credentials?
- [ ] Are workload identities (k8s service accounts, cloud IAM roles) scoped minimally?

### 2c. CI/CD and Automation

**What to search for:**
```
grep -ri "secret\|token\|key\|credential\|password" .github/workflows/ scripts/ Makefile docker-compose*.yml
```
Also check: entrypoint scripts, Dockerfiles, deployment manifests.

**Checklist:**
- [ ] Can CI/CD pipelines mint or inject fresh secrets?
- [ ] Are pipeline secrets rotatable without redeploying?
- [ ] Are deploy keys, PATs, and SSH keys inventoried?
- [ ] Is there monitoring for new keys/tokens being created?
- [ ] Can a compromised pipeline step persist across deploys?

### 2d. Identity Provider Integration

**What to search for:**
```
grep -ri "saml\|oidc\|sso\|idp\|identity.provider\|federation" --include="*.{php,js,ts,py,yaml,yml,json}"
```

**Checklist:**
- [ ] If SSO is used, can a compromised IdP integration grant access after local remediation?
- [ ] Are SAML assertions / OIDC tokens validated for freshness, not just signature?
- [ ] Is there session fixation protection on SSO callbacks?

### 2e. Audit Trail

**What to search for:**
```
grep -ri "log\|audit\|trail\|event\|monitor" --include="*.{php,js,ts,py,yaml,yml,json,sh}"
```
Also check: log rotation config, log storage locations, `.gitignore` for excluded log dirs.

**Checklist:**
- [ ] Are all authentication events logged (login, token grant, token refresh, revoke)?
- [ ] Are all API requests logged with user identity and timestamp?
- [ ] Are admin actions (revoke, disable, config change) logged?
- [ ] Is there anomaly detection or rate limiting on suspicious patterns?
- [ ] Are logs tamper-evident (not writable by the application user)?

### 2f. Endpoint and Workload Persistence

**What to search for:**
```
grep -ri "cron\|startup\|entrypoint\|init.container\|beacon\|heartbeat" --include="*.{sh,yaml,yml,Dockerfile}"
```
Also check: Dockerfiles for `USER` directives, capability drops, network policies.

**Checklist:**
- [ ] Could a compromised endpoint (developer machine) re-inject credentials?
- [ ] Are there startup scripts, cron jobs, or init containers that could beacon?
- [ ] Is the application container/image built from a trusted, verified source?

## Step 3: Classify findings

For each checklist item, assign one of:
- **COVERED** — spec or code explicitly addresses this; cite the file and line
- **PARTIAL** — partially addressed; describe the gap
- **MISSING** — not addressed at all
- **N/A** — not applicable to this system; explain why

**Evidence rules:**
- COVERED requires a file path + line number or config key as citation
- PARTIAL requires a description of what is present and what is missing
- MISSING requires confirmation that the relevant code/config area was searched
- N/A requires a brief justification (e.g., "no OAuth flows in this system")

## Step 4: Generate the audit report

Use AskUserQuestion to confirm the system name if not obvious from project files.

Structure the report as:

```markdown
## Persistence Audit: <system name>

**Date:** <today>
**Scope:** <what was audited — repo, spec, architecture>
**Model:** <model used>

### Summary
- X/Y vectors COVERED
- X/Y vectors PARTIAL
- X/Y vectors MISSING
- X/Y vectors N/A
- **Critical gaps:** <prioritized list>

### Findings

#### 1. OAuth and Token Persistence
| Check | Status | Evidence |
|-------|--------|----------|
| Admin-revocable grants | COVERED/PARTIAL/MISSING/N/A | <file:line or explanation> |
...

#### 2. Service Accounts and Machine Identities
...

#### 3. CI/CD and Automation
...

#### 4. Identity Provider Integration
...

#### 5. Audit Trail
...

#### 6. Endpoint and Workload Persistence
...

### Recommended Remediations
Ordered by severity (persistence duration × access level):
1. [CRITICAL] ...
2. [HIGH] ...
3. [MEDIUM] ...
4. [LOW] ...

### Methodology
- Tools used: <grep patterns, file globs, manual inspection>
- Files examined: <count>
- Limitations: <any areas that could not be assessed>
```

## Step 5: Suggest follow-up actions

Based on the findings:
- If critical gaps found → recommend immediate remediation and re-audit
- If the system has CI/CD pipelines → suggest running with the `hydra` argument for deeper pipeline analysis
- If this was a post-incident audit → recommend verifying each COVERED item is actually enforced (not just documented)
- If specs were audited (not code) → recommend a code-level follow-up audit

## When to Use This

- After writing or reviewing specs that involve auth, OAuth, API keys, or session management
- Post-incident review: "did we actually eradicate or just contain?"
- Architecture review for any system with multiple trust planes
- Before signing off on security-sensitive PRs
- When onboarding a new CI/CD pipeline or automation system

