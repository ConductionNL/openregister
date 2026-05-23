# ADR-013: Unified Container Pool

**Status:** accepted
**Date:** 2026-04-12

## Context

Specter (intelligence/research) and Hydra (build/review/merge) both run LLM workloads in Docker containers. Today they operate independently: Hydra spins up builder/reviewer/security containers on demand, Specter has a separate `run_llm_containers.sh` wrapper. Both compete for the same Claude Max rate limits.

We want to unify these into a **single priority-scheduled container pool** so that:
- Critical work (bugfixes, reviews) preempts lower-priority work (discovery, research)
- A fixed number of containers (e.g. 10) run continuously, pulling from a shared queue
- Token rotation and rate limit recovery happen at the pool level, not per-script
- Adding a new workload type (audit, spec generation, test) is just a new queue entry

## Decision

### Container types (priority order)

| Priority | Type | Source | Container image | Model | Fallback |
|----------|------|--------|-----------------|-------|----------|
| 1 | **code-review** | Hydra: PR code review + in-container fixes | `hydra-reviewer` | sonnet | opus |
| 2 | **security-review** | Hydra: PR security review + in-container fixes | `hydra-security` | sonnet | opus |
| 3 | **applier** | Hydra: binary go/no-go gate (no fix authority) | `hydra-applier` | sonnet | opus |
| 4 | **build** | Hydra: initial spec build | `hydra-builder` | haiku | — |
| 5 | **audit** | Hydra: codebase audit | `hydra-builder` | sonnet | opus |
| 6 | **spec-generation** | Specter: push_spec_pipeline | `specter-llm-worker` | sonnet | haiku |
| 7 | **schema-synthesis** | Specter: generate/dedup schemas | `specter-llm-worker` | haiku | — |
| 8 | **classification** | Specter: classify/redistribute features | `specter-llm-worker` | haiku | — |
| 9 | **translation** | Specter: translate requirements | `specter-llm-worker` | haiku | — |
| 10 | **discovery** | Specter: research, feature extraction | `specter-llm-worker` | haiku | — |

**No-loop policy (openspec/changes/no-loop-review-pipeline):** Reviewers own fix
authority. The Applier is a read-only final gate that emits a binary pass/fail
verdict — it never modifies files. Every post-review outcome is terminal:
merge (on `applier:pass` or reviews passed with zero fixes) or `needs-input`
(on `applier:fail`, reviewer `agent-maxed-out`, or post-review deterministic
check failure). There is no fix-iteration loop and no `bugfix` container.

### Model strategy

**Principle:** Use the cheapest model that can do the job. Reserve expensive models for judgment work.

| Work type | Model | Rationale |
|-----------|-------|-----------|
| Build (implementation) | **Haiku** | Clear instructions (tasks.md, design.md). Pattern-following, not judgment. Faster and cheaper — 5 parallel Haiku builds burn far less quota than Sonnet. |
| Fix-quality / fix-browser (pre-review) | **Haiku** | "Fix this PHPCS error" or "fix this browser test failure" — explicit, targeted corrections triggered by deterministic check output during the build phase. |
| Code review (+ in-container fix authority) | **Sonnet → Opus** | Judgment + bounded fixes. Sonnet is the primary; falls back to Opus when Sonnet quota exhausted. Budget: 40 turns (up from 20) to cover review + self-verified fixes. |
| Security review (+ in-container fix authority in PR mode) | **Sonnet → Opus** | Critical: injection vectors, auth bypasses, secret leaks. Same fallback logic. Budget: 40 turns in PR mode, 120 in full-audit mode (audit mode has no fix authority). |
| Applier (Axel Pliér) | **Sonnet → Opus** | Final binary go/no-go. No fix tools. Reads hydra.json + PR state + ADRs, emits `{pass, blocking[]}`. Budget: 20 turns. |
| Audit | **Sonnet → Opus** | Full codebase analysis — needs depth. |

**Quota optimization:** Claude Max plans have separate "Sonnet only" and "all models" weekly limits. By defaulting builders to Haiku, the Sonnet quota is reserved for reviews only (~20 turns each, 2 per PR). When Sonnet runs out, reviews fall back to the **deeper** model (Opus), not the shallower one — because reviews are the last line of defense before human approval.

**Overrides:** Set `HYDRA_BUILDER_MODEL`, `HYDRA_REVIEWER_MODEL`, or `HYDRA_REVIEWER_FALLBACK_MODEL` env vars to change defaults.

### Architecture

```
┌─────────────────────────────────────────────────────┐
│  Scheduler (cron or daemon)                         │
│                                                     │
│  reads: queue table (postgres)                      │
│  writes: container assignments, status updates      │
│                                                     │
│  ┌──────────────────────────────────────────┐       │
│  │ Pool: 10 container slots                 │       │
│  │                                          │       │
│  │  slot-1: [bugfix]     ← highest prio     │       │
│  │  slot-2: [code-review]                   │       │
│  │  slot-3: [build]                         │       │
│  │  slot-4: [build]                         │       │
│  │  slot-5: [classify]                      │       │
│  │  slot-6: [classify]                      │       │
│  │  slot-7: [translate]                     │       │
│  │  slot-8: [discovery]                     │       │
│  │  slot-9: [idle]       ← waiting for work │       │
│  │  slot-10: [idle]                         │       │
│  └──────────────────────────────────────────┘       │
│                                                     │
│  Token rotation: credentials.json (work → private)  │
│  Rate limit: pool-level tracking per account        │
│  Preemption: low-prio containers stopped when       │
│              high-prio work arrives and pool is full │
└─────────────────────────────────────────────────────┘
```

### Queue table (future)

```sql
CREATE TABLE container_queue (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,        -- bugfix, code-review, build, classify, etc.
    priority INTEGER NOT NULL,         -- 1=highest
    payload JSONB NOT NULL,            -- script args, spec slug, issue URL, etc.
    status VARCHAR(20) DEFAULT 'pending', -- pending, running, completed, failed
    container_id VARCHAR(100),         -- docker container name when running
    token_account VARCHAR(50),         -- which OAuth account is assigned
    created_at TIMESTAMP DEFAULT NOW(),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    exit_code INTEGER,
    error_message TEXT
);
```

### Phased rollout

**Phase 1 (now):** All LLM calls containerized. Specter scripts run via `run_llm_containers.sh`. Hydra containers use `run_container_with_fallback`. Both read from `credentials.json`. No shared queue yet — each system schedules its own containers.

**Phase 2:** Shared queue table. A single scheduler script replaces both `cron-hydra.sh` dispatch and `run_llm_containers.sh`. Pool size configurable. Priority enforcement by not starting low-prio work when high-prio is queued.

**Phase 3:** Preemption. Running low-priority containers can be stopped (gracefully, with checkpoint) when high-priority work arrives and all slots are occupied. Container images support checkpoint/resume via DB state.

### Current state (Phase 1)

**Container images:**

| Image | Size | Purpose |
|-------|------|---------|
| `conduction/nextcloud-test:stable31` | 1.5GB | Prebuild NC server + PostgreSQL + OpenRegister (cloned) |
| `hydra-builder:latest` | 1.9GB | Code implementation: NC test env + Claude CLI + PHP + skills |
| `hydra-reviewer:latest` | 1.3GB | Code review + bounded in-container fix authority (Juan Claude van Damme) |
| `hydra-security:latest` | 1.9GB | Security review + bounded in-container fix authority (Clyde Barcode) |
| `hydra-applier:latest` | 1.0GB | Binary go/no-go gate; no Write/Edit tools (Axel Pliér) |
| `specter-spec-writer:latest` | ~800MB | Spec generation: Claude CLI + openspec CLI + skills (no PHP) |
| `specter-llm-worker:latest` | ~500MB | Intelligence pipeline: Claude CLI + DB access |

**Credential separation:**
- **Specter:** `concurrentie-analyse/secrets/credentials.json` (work + private tokens)
- **Hydra:** `hydra/secrets/credentials.json` (work token only)

**Token detection:**
- Container mode: uses exit code (0 = success, non-zero checks output for rate limit)
- Local mode: checks output text for "rate limit" / "auth failed" strings

**NC test environment:**
- Prebuild image with PostgreSQL (matches production, not SQLite)
- Builder `COPY --from=conduction/nextcloud-test` at build time
- Entrypoint starts PG + enables OpenRegister at runtime
- Each container gets its own isolated NC+PG instance

**Spec generation flow:**
- `push_spec_pipeline.py` prepares repos in parallel, generates in `specter-spec-writer` containers
- Each spec gets its own container + clone (compartmentalized)
- Dependency tiers control ordering: Phase 1 → Phase 2 → Phase 3 → Phase 4
- Specs with met deps push to development directly (doc-only merge guard)
- Issues created with `yolo` label → Hydra auto-builds, reviews, merges, closes issue

### Container capability profiles

Each container persona runs with a different Linux capability set determined by the trust we extend to it. This is load-bearing for runtime behaviour — a container's `/workspace` is ONLY writable by the claude user if the build or the entrypoint arranges it, and the two code paths diverge based on cap profile.

| Persona | Caps added | Claude user | Workspace setup |
|---------|-----------|-------------|-----------------|
| Builder | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Dropped via `gosu` at run time | Entrypoint chowns at start, relies on DAC_OVERRIDE |
| Reviewer | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Same as builder | Same — entrypoint chown |
| Security | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Same | Same |
| **Applier** | **None** (minimum-cap — read-only judge) | **Runs as `claude:claude` via `docker --user`** (no gosu drop possible — can't setuid without SETUID) | **Must be pre-chowned at IMAGE BUILD TIME** — no runtime chown possible |

**The applier's minimum-cap profile has a hard consequence:** its Dockerfile MUST contain
```dockerfile
RUN mkdir -p /workspace && chown claude:claude /workspace && chmod 0775 /workspace
```
before the `WORKDIR /workspace` directive. Otherwise the non-root claude user cannot write files into its own workdir, `hydra_prefetch_pr_context` silently fails every redirect, Claude runs 0 turns, and the orchestrator records `pass=null, turns=0 → applier:fail`. Observed on decidesk#44 2026-04-23 06:01 UTC — looked like a harness bug, real cause was one missing `chown` line in the Dockerfile.

This is **the rule for any future minimum-cap persona**: if you drop DAC_OVERRIDE + SETUID for security reasons, the Dockerfile owns workspace ownership — the entrypoint cannot.

## Consequences

- All LLM calls go through containers — no direct `claude -p` from host scripts
- Token management is centralized per system (Specter has private fallback, Hydra doesn't)
- Container exit code determines token rotation (not mid-session JSONL text)
- Prebuild NC image eliminates 30-60s clone overhead per builder container
- Container images are the unit of deployment — version, test, rollback independently
- ADR-000 convention: every repo's data model is at `openspec/architecture/adr-000-data-model.md`
- `context-brief.md` in each change directory carries intelligence data through the full pipeline
- Minimum-cap containers (applier) require Dockerfile-time workspace chown; higher-cap containers can chown at runtime. This split is permanent — don't ship a new minimum-cap persona without pre-chowning.
