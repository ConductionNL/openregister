## Context

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

The existing `tenant-lifecycle` spec defines the provisioning/suspension/deprovisioning state machine for tenant Organisations. Two related behaviors were observed in code but absent from the spec:

1. **OTAP environment validation** (`isValidEnvironment`, `isValidPromotionOrder`) — guards against invalid environment values and enforces unidirectional promotion order across `dev` → `test` → `acceptance` → `production`.
2. **Hourly deprovisioning job** (`TenantDeprovisionJob::run`) — already partially specified by REQ-003 but missing an `@spec` annotation.

The ghost change `retrofit-2026-04-28-tenant-lifecycle` adds REQ-005 for the OTAP validation logic and backfills the REQ-003 annotation on the deprovisioning job. It also surfaces an observed gap between REQ-003 (which specifies object soft-deletion) and the actual implementation (which only flips the Organisation status to `archived` and defers object deletion to a purge job).

**Files covered:**
- `lib/Service/TenantLifecycleService.php` — `isValidEnvironment`, `isValidPromotionOrder` (REQ-005, new); `validateTransition` (REQ-001, existing)
- `lib/BackgroundJob/TenantDeprovisionJob.php` — hourly deprovisioning sweep (REQ-003 backfill)

## Goals / Non-Goals

**Goals:**
- Add REQ-005 specifying OTAP environment validation and unidirectional promotion order
- Backfill `@spec` on `TenantDeprovisionJob` to point at the existing REQ-003
- Surface the REQ-003-vs-implementation divergence in Notes for reviewer attention

**Non-Goals:**
- No code changes — annotations + spec only
- Does not fix the soft-delete gap in `TenantDeprovisionJob` — that is a separate (potentially compliance-critical) PR
- Does not formalize the `OTAP_ORDER` constant map as part of the spec — REQ-005 references the order ordinally, not by literal values

## Decisions

**Decision: `--extend tenant-lifecycle` adding REQ-005 rather than mining a new `environment-otap` cluster**

OTAP environment validation is a tenant-level concern (each tenant declares its environment band) and is enforced inside `TenantLifecycleService`. Splitting it into a separate capability would scatter related state-machine logic across two specs.

**Decision: Surface the soft-delete gap rather than silently fix it**

REQ-003 says "all objects belonging to the Organisation MUST be soft-deleted" during deprovisioning, but `TenantDeprovisionJob::run` only calls `archive()` (status flip) and defers object deletion to a downstream purge job. This is a real divergence with potential GDPR/retention implications. The retrofit playbook is clear: observe, do not fix. Notes flag the gap for a follow-up PR.

**Decision: Treat unknown environment values as `order = -1`**

`isValidPromotionOrder` returns `OTAP_ORDER[$src] < OTAP_ORDER[$tgt]` and unknown environments default to `-1`, which fails any forward-promotion check. REQ-005 specifies this fallback so the behavior is contract, not coincidence.

## Risks / Trade-offs

- **REQ-003 divergence is unresolved**: this PR documents the gap; it does not close it. Compliance reviewers must understand that retention of objects past tenant deprovisioning is the *current* behavior, deferred to retention purge. → Open a follow-up PR if compliance disagrees.
- **OTAP_ORDER is hard-coded**: the constant map lives in `TenantLifecycleService` and is not externally configurable. Tenants in non-OTAP environments (e.g. single-stage staging) get `-1` and cannot promote. → Acceptable for now; flag as Note if multi-band rollout is needed.
- **Hourly cron coupling**: `TenantDeprovisionJob` runs on an hourly Nextcloud cron schedule. Tenants stuck in `deprovisioning` rely on the cron firing. Failure modes (cron disabled, job exception) are not specified. → Future REQ candidate.

## Migration Plan

No migration required — annotations only. The ghost change is archived immediately; REQ-005 lands in `openspec/specs/tenant-lifecycle/spec.md`.

`.git-blame-ignore-revs` was updated with the annotation commit SHA.

**Follow-up tracking** — the REQ-003 / soft-delete divergence should be opened as a separate issue against the `tenant-lifecycle` capability owner.
