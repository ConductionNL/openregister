## Context

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

The `DestructionCheckJob` already implements two distinct notification phases for objects with a retention `archiefactiedatum`:

1. **Pre-destruction notifications** — N days before the deadline (default 30), per-object alerts to the `archivaris` group with deduplication and legal-hold exclusion.
2. **List creation notification** — fires once when a destruction list is generated, already covered by REQ-001.

The ghost change `retrofit-archival-destruction-workflow-2026-04-24` retroactively specifies the pre-destruction notification phase as REQ-009 and backfills `@spec` annotations on the 18 supporting Bucket 2a methods (legal hold management, archival date calculation, due-objects discovery) that map to existing REQs.

**Files covered:**
- `lib/BackgroundJob/DestructionCheckJob.php` — the new REQ-009 behavior
- `lib/Controller/ArchivalController.php` — legal-hold endpoints (REQ-006 backfill)
- `lib/Service/Archival/LegalHoldService.php` — legal-hold business logic (REQ-006 backfill)
- `lib/Service/ArchivalService.php` — archival date calculation + destruction discovery (REQ-001, REQ-007 backfill)

## Goals / Non-Goals

**Goals:**
- Add REQ-009 covering the configurable advance-notification phase
- Annotate 2 methods directly implementing REQ-009
- Backfill `@spec` tags on 9 supporting methods that map to existing REQs (REQ-001, REQ-006, REQ-007)
- Surface the dedup-list-storage pattern (`retention_notified_objects` app config) as canonical

**Non-Goals:**
- No code changes — annotations + spec only
- Does not respec the destruction-list creation flow (already REQ-001)
- Does not formalize the `archiefnominatie: bewaren` → e-Depot subject branching as its own REQ — covered as a scenario under REQ-009

## Decisions

**Decision: `--extend archival-destruction-workflow` rather than a new cluster**

The advance-notification phase is part of the retention/destruction lifecycle, sharing the same lead-time semantics, archivist-group recipient, and legal-hold exclusion logic as the existing destruction-list flow. Minting a new capability would split a coherent workflow across two specs.

**Decision: Document deduplication via app config rather than the database**

`DestructionCheckJob` persists notified UUIDs in `app config` rather than a dedicated table. This is unusual for OpenRegister (most state lives in registers/objects), but the volume is bounded (one entry per object across its retention lifetime) and a config-key approach avoids a migration. The spec REQ-009 codifies this choice without requiring a refactor.

## Risks / Trade-offs

- **App-config persistence ceiling**: `retention_notified_objects` grows monotonically until objects clear retention. For tenants with large retention queues, the config blob could become unwieldy. → Mitigation: a follow-up could move to a dedicated audit-style table once observed size warrants it.
- **Subject branching invisibility**: the `archiefnominatie: bewaren` → "Object requires e-Depot transfer" subject path is buried in `sendObjectNotification`. The scenario surfaces it; there is no separate REQ. Reviewers should verify the branch matches the e-Depot interop contract.
- **No automatic re-notification**: once an object is notified, it is never re-notified, even if its `archiefactiedatum` changes. Behavior is intentional but warrants a Note in REQ-009 for future tightening.

## Migration Plan

No migration required — annotations only. The ghost change is archived immediately.

`.git-blame-ignore-revs` was updated with the annotation commit SHA so `git blame` continues to show original authors for annotated files.
