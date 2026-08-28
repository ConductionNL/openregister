---
kind: code
depends_on: []
---

## Why

ADR-045 makes OpenRegister own the generic MDM surface — including a **reversible
merge with audit** ("snapshot → relink → recompute → audit → reverse, on any OR
schema"). The survivorship engine (`mdm-survivorship`, merged) already resolves a
golden record from trust-tiered sources declaratively; what is still missing is the
steward **action** that collapses two duplicate master objects into one survivor,
reversibly, and lets downstream systems react. pipelinq ships that today as a
bespoke `MergeService` bolted to an app-local sync queue — the exact per-app rebuild
ADR-045 exists to prevent. This change generalises that service into an
entity-type-agnostic OR engine so any register whose schema declares merge config
gets a reversible merge for free, and propagates the result over OR's own event
infrastructure rather than a second delivery queue.

## What Changes

- **New OR-owned `mergeOperation` register/schema** (`lib/Settings/merge_operation_register.json`,
  shaped like `trust_configuration_register.json`) — a runtime audit log of each merge:
  `mergedIntoUuid`, `mergedFromUuids[]`, `reason`, `preMergeSnapshot` (both objects'
  golden-record / provenance / status / links), `reversible`, `mergedAt`, `reversedAt`,
  `reversedBy`. No seed rows — it is a log, populated only at runtime.
- **New sibling schema annotation `x-openregister-merge`** — declares the merge-specific
  config (`sourceLinkField` reused conceptually from survivorship, `entityType`,
  `reversalWindowDays` default 30, `statusField`, `survivorStatus` / `mergedStatus`).
  Registered in `Schema::ANNOTATION_VOCABULARY`, shape-validated at import by a new
  `MergeAnnotationValidator`, malformed → non-fatal warning. Chosen over extending
  `x-openregister-survivorship` to keep the save-time materialise contract and the
  imperative merge action decoupled (see design.md).
- **New generic `MergeService`** (`lib/Service/Merge/MergeService.php`), entity-type-agnostic:
  `previewMerge` / `executeMerge` / `reverseMerge` / `isReversible` / `reversalDeadline`
  / `buildSnapshot`. `executeMerge`: build snapshot → relink the losing object's linked
  source records onto the survivor via the survivorship `sourceLinkField` → recompute the
  survivor golden record by reusing `SurvivorshipResolver` → persist a `mergeOperation` →
  **dispatch an OR `ObjectsMergedEvent`** (NOT an app queue). `reverseMerge` restores from
  snapshot inside the reversal window and flips `reversible=false`.
- **New `ObjectsMergedEvent`** (`lib/Event/ObjectsMergedEvent.php`) dispatched via
  `IEventDispatcher` so leaf apps (e.g. pipelinq's downstream sync) subscribe — reusing
  OR's existing event/webhook infrastructure instead of a parallel queue subsystem.
- **New `MergeController`** (`lib/Controller/MergeController.php`) + routes in
  `appinfo/routes.php` (ADR-016/ADR-029): `preview`, `execute`, `reverse`.
  `#[NoAdminRequired]`, RBAC enforced through `ObjectService` (same auth style as the
  `#1` `DuplicateController` / `QualityController`).

## Capabilities

### New Capabilities
- `mdm-merge`: A generic, entity-type-agnostic, reversible object-merge engine in
  OpenRegister — the `x-openregister-merge` declarative config + `MergeAnnotationValidator`,
  the OR-owned `mergeOperation` audit-log register/schema, the imperative `MergeService`
  (preview / execute / reverse / reversibility), an `ObjectsMergedEvent` for downstream
  propagation, and a RBAC-scoped `MergeController` REST surface.

### Modified Capabilities
<!-- None. A sibling x-openregister-merge annotation is introduced instead of
     extending x-openregister-survivorship, so the mdm-survivorship capability's
     requirements are unchanged. This change consumes SurvivorshipResolver as-is. -->

## Impact

- **New code**: `lib/Service/Merge/MergeService.php`, `lib/Service/Merge/MergeAnnotationValidator.php`,
  `lib/Controller/MergeController.php`, `lib/Event/ObjectsMergedEvent.php`,
  `lib/Settings/merge_operation_register.json`.
- **Edited code**: `lib/Db/Schema.php` (add `x-openregister-merge` to `ANNOTATION_VOCABULARY`),
  `lib/Db/SchemaMapper.php` (call a `validateMergeAnnotation` hook, non-fatal),
  `appinfo/routes.php` (three merge routes).
- **Reuses**: `SurvivorshipResolver` (golden-record recompute), `ObjectService`
  (RBAC + tenant-scoped reads/writes), `IEventDispatcher` (event dispatch), the
  `trustConfiguration` register (via the resolver), OR audit trail (via ObjectService saves).
- **Consumers**: leaf apps subscribing to `ObjectsMergedEvent` (pipelinq downstream sync,
  a follow-on `#D` migration). Out of scope here: the merge UI (`#C mdm-merge-ui`),
  auto-merge policy/gates (apps decide), pipelinq migration (`#D`), GDPR/AVG (ADR-047).
