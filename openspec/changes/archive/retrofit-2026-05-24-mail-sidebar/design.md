# Design — Retrofit mail-sidebar (2026-05-24)

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Context

A coverage scan on 2026-05-24 surfaced 45 mail-sidebar-related methods across 14 files that lacked `@spec` annotations against the `mail-sidebar` capability. After triage (see Approach), 16 methods turned out to be net-new behaviors not yet specified — three-tab layout, path-based URL routing, attachment drag-drop, entity extraction, mount bootstrap. The remaining 29 are owned by sibling capabilities (`nextcloud-entity-relations`, `integration-email`) and already annotated to earlier retrofits.

## Approach

This change drafts 5 cohesive REQs that describe observed behavior — not aspirational. Code already exists; the change retroactively binds it to the spec via `@spec` annotations.

### REQ selection rationale

- **REQ-001 (3-tab layout)** — the structural pivot from the original spec's flat "Linked Objects + Related Cases" sections. Required to host REQ-004.
- **REQ-002 (path-based URL)** — the original spec only specified hash-based URLs; Mail 5.x+ uses pushState and polling is the only way to catch it.
- **REQ-003 (attachment drag)** — net-new feature with brittle Mail-internal coupling; spec captures the contract and flags the upstream Mail PR that should retire it.
- **REQ-004 (entities tab)** — net-new NLP surface; describes consumer behavior only (the `/api/entities` endpoint needs its own spec under a future capability).
- **REQ-005 (mount bootstrap)** — describes the `#initial-state-mail-accounts` probe + 30-retry loop that survives Mail's Vue re-renders.

### Out-of-scope methods (deferred)

- **EmailProvider (10 methods)** — covered by `integration-email` change. Already tagged `@spec openspec/changes/integration-email/tasks.md`.
- **EmailsController forward-flow (3 methods + 1 private)** — covered by `nextcloud-entity-relations` spec + `retrofit-2026-04-30-annotate-openregister`.
- **EmailService (5 methods)** — same as above.
- **Per-object EmailsTab / emails store (2 methods)** — same as above.
- **Smart-picker FPs (already triaged DROP in batch JSON)** — `EmailService::isMailAvailable`, `findMessageIdsBySender`, `getMailLinkedSchemas`, `buildMailboxSubquery`, `EmailProvider::delete`, `EmailsController::validateObject`.

### Annotation strategy

- File-level annotation where the entire file is captured by one REQ (e.g. `useAttachmentDrag.js` → REQ-003).
- Method-level annotation where the file spans multiple REQs (e.g. `MailSidebar.vue::toggleCollapsed` → REQ-001 only, while the tab structure pre-existing on the file is also REQ-001).
- File-level + method-level coexist where applicable (per ADR-008 / ADR-003).

## Risks

- **Future Mail-app refactor** breaking REQ-003 attachment patching — flagged in spec Notes; nextcloud/mail#10509 will retire the runtime patching once native drag lands.
- **`accountId` heuristic fallback** in REQ-002 — observed bug; spec flags it but does not silently fix it. Right fix needs a separate change.
- **`/api/entities` endpoint not specified** — REQ-004 documents consumer behavior only; producer endpoint is a follow-up.

## Migration

None — annotations are non-functional.

## Test plan

The scan is the test: after this change archives, a re-run of `/opsx-coverage-scan` must reclassify all 16 in-scope methods from Bucket 2a/2b into Bucket 1. If any remain in Bucket 2 the matcher needs investigation per the playbook's "no-loops" rule.
