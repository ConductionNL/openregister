## Context

ADR-045 assigns OpenRegister the whole MDM surface, including a **reversible merge with
audit**. The survivorship engine (`mdm-survivorship`, merged) already resolves a golden
record declaratively from trust-tiered sources via the pure `SurvivorshipResolver`, driven
by the `x-openregister-survivorship` annotation and the OR-owned `trustConfiguration`
register. What remains is the steward **action** that collapses duplicates: pick a survivor,
relink the loser's source records, recompute the survivor, log it, and let downstream
systems react — reversibly.

pipelinq already implements this as `OCA\Pipelinq\Service\Mdm\MergeService`
(`previewMerge` / `executeMerge` / `reverseMerge` / `isReversible` / `reversalDeadline` /
`buildSnapshot`, `REVERSAL_WINDOW_DAYS=30`). That service is the exact contract to
generalise — but it is bolted to two app-specific pieces we must NOT carry into OR: a
hardcoded `MasterEntityService` (contact/account/product) and an app-local
`SyncQueueService` with a `DOWNSTREAM_SYSTEMS` allowlist. The OR version must be
entity-type-agnostic (config-driven, reusing `SurvivorshipResolver`) and must propagate
over an **OR event** rather than a private queue (ADR-045 anti-pattern: "app-local outbound
sync queue").

Reference collaborators already in the repo: `SurvivorshipResolver`,
`SurvivorshipRecomputeListener` (save-time materialise pattern), `ObjectService`
(RBAC + tenant scoped find/findAll/save), `trust_configuration_register.json` (register
JSON shape), `DuplicateController` (auth/route style from `#1`), the `Event/*Event.php`
family + `Application.php` `IEventDispatcher` dispatch pattern.

## Goals / Non-Goals

**Goals:**
- A generic, entity-type-agnostic, reversible merge engine in OR (`MergeService`).
- Declarative merge config via a sibling `x-openregister-merge` annotation.
- An OR-owned `mergeOperation` audit-log register (no seed rows).
- Downstream propagation via an `ObjectsMergedEvent`, reusing OR's event/webhook infra.
- A RBAC-scoped `MergeController` REST surface (preview / execute / reverse).

**Non-Goals:**
- The merge UI — deferred to `#C mdm-merge-ui`.
- Auto-merge policy / gating (when a merge is *allowed* to fire automatically) — apps decide.
- pipelinq's migration off its app-local `MergeService` — deferred to `#D`.
- GDPR/AVG deletion workflow — ADR-047.
- A sync queue / retry / dead-letter subsystem — OR already has `WebhookService` /
  `WebhookDeliveryJob` / `HookRetryJob`; downstream apps subscribe to the event.

## Decisions

### Declarative config vs imperative action (ADR-031)

ADR-031 prefers schema-declarative business logic over service classes. Merge sits on both
sides of that line and the split is deliberate:

- **The CONFIG is declarative.** *What* a merge means for a schema — which field links the
  source records, which entity type feeds survivorship recompute, how long the reversal
  window is, which status values mark survivor vs merged — is declared in the schema via
  `x-openregister-merge` and validated at import, exactly like `x-openregister-survivorship`
  / `x-openregister-quality`. No merge behaviour is hardcoded per entity type.
- **The ACTION is imperative.** A merge is a **steward-initiated command with side effects**
  (relink N source records, recompute, write an audit row, dispatch an event), not a
  save-time materialisation of a derived field. It cannot be modelled as an
  on-save listener the way survivorship's golden record is, because it is triggered by an
  explicit REST call on two chosen objects, is transactional across multiple objects, and
  must be *reversible* — a property that only makes sense for an action, not a projection.
  Therefore an imperative `MergeService` + `MergeController` is the correct shape; ADR-031
  is satisfied because the declarative surface (config) drives the imperative engine, and
  the engine hardcodes nothing about any entity type.

*Alternative considered:* model merge as another materialise-on-save listener that collapses
objects when a "mergeInto" field is set. Rejected — it hides a destructive, multi-object,
reversible action inside an implicit save hook, defeats preview, and makes RBAC and reversal
opaque.

### Sibling `x-openregister-merge` annotation vs extending survivorship

We add a **new sibling annotation** `x-openregister-merge` rather than a `merge` block inside
`x-openregister-survivorship`.

- *Why sibling:* the survivorship annotation governs a **save-time materialise contract**
  (`SurvivorshipRecomputeListener` writes `goldenRecordField` / `provenanceField` before
  every persist). Merge is a separate, imperative, opt-in capability. Coupling them would
  (a) modify the merged `mdm-survivorship` capability's requirements, (b) make the
  survivorship validator responsible for merge shape, and (c) force a schema that wants only
  golden-record materialisation to reason about reversal windows. The guardrail explicitly
  prefers decoupling.
- The merge engine still **reuses** the survivorship `sourceLinkField` conceptually (the
  losing object's source records live in the same field survivorship reads) and reuses
  `SurvivorshipResolver` for recompute — reuse without config coupling.
- *Alternative considered:* a `merge:` sub-block under `x-openregister-survivorship`. Rejected
  for the coupling above; recorded as DEFERRED_QUESTION (a) so it can be revisited if a schema
  is found that always wants both together.
- **Capability impact:** because we chose the sibling annotation, the `mdm-survivorship`
  capability's requirements are unchanged (no MODIFIED spec). `mdm-merge` is purely additive.

### Reuse `SurvivorshipResolver`, not pipelinq's `MasterEntityService`

`executeMerge` and `previewMerge` recompute the survivor by loading the combined linked
source records and calling the pure `SurvivorshipResolver::resolveGoldenRecord(...)` with the
schema's survivorship config, trust rows (via `TrustTierResolver`/`trustConfiguration`), and
`asOf = now` — exactly as `SurvivorshipRecomputeListener` already does. No golden-record math
is reimplemented; pipelinq's hardcoded `recomputeGoldenRecord` is dropped.

### Downstream propagation via `ObjectsMergedEvent`, not a queue

`executeMerge` / `reverseMerge` dispatch `OCA\OpenRegister\Event\ObjectsMergedEvent` through
`IEventDispatcher` (registered/dispatched following the existing `Event/*Event.php` +
`Application.php` pattern). Leaf apps subscribe to react (pipelinq downstream sync in `#D`).
OR's existing `WebhookService` can also fan the event out to HTTP subscribers — no new
delivery/retry code. The event carries `{ survivorUuid, mergedFromUuids[], mergeOperationId,
isReversal }`. DEFERRED_QUESTION (c) tracks whether to *also* fire a dedicated OR webhook
trigger versus relying on subscribers alone — default: emit the event only; existing webhook
infra can observe it.

### Snapshot fidelity and reversal

`buildSnapshot(from, into)` captures both objects' golden record, provenance, status, and the
losing object's source-record → owner links — the minimum to restore. `reverseMerge` restores
that snapshot and flips `reversible=false`. Best-effort restoration of source-record links
(skip an unresolvable record rather than abort) mirrors pipelinq; DEFERRED_QUESTION (d) tracks
whether reversal must re-split source records with exact fidelity or best-effort is acceptable
— default: best-effort, since the snapshot records the exact prior owner per record and the
reversal window bounds drift.

### `mergeOperation` as an OR-owned register (no seed)

Shipped as `lib/Settings/merge_operation_register.json` in the `trust_configuration_register.json`
shape. It is a **runtime audit log** — see Seed Data below. Rows are written via `ObjectService`
so RBAC/tenant scoping and audit trail come for free.

### Reversal window default location

`reversalWindowDays` defaults to `30` and is read from the `x-openregister-merge` annotation
(fallback to a `MergeService` constant when absent), matching pipelinq's `REVERSAL_WINDOW_DAYS`
but making it schema-configurable. DEFERRED_QUESTION (b) tracks whether the default belongs on
the annotation, a service constant, or an admin setting — default: annotation with a service-constant
fallback.

## Seed Data

The `mergeOperation` register/schema ships with **NO `x-openregister-seed` objects**. It is a
runtime audit log: rows exist only after `executeMerge` writes one. This differs from
`trust_configuration_register.json`, which seeds example trust rows, precisely because seeding a
merge log would fabricate merge history. The register JSON therefore declares the register + the
`mergeOperation` schema (with its properties) and an empty/absent seed block.

## Risks / Trade-offs

- **[Destructive action without a UI]** `#C` ships the UI later; until then execute/reverse are
  API-only. → RBAC + reversal window bound the blast radius; preview is side-effect-free so a
  steward can inspect before committing.
- **[Snapshot drift]** an object edited after a merge but before a reversal may not restore
  cleanly. → the reversal window bounds the drift; snapshot records exact prior state; reversal is
  best-effort per record and logged.
- **[Event has no subscribers yet]** dispatching `ObjectsMergedEvent` is a no-op until `#D`. →
  intentional; the engine is complete and downstream wiring is a separate change. No queue debt is
  created.
- **[Annotation misuse]** a schema declares `x-openregister-merge` but no survivorship config, so
  recompute has nothing to resolve. → validator warns; merge still relinks + logs; golden record
  simply is not recomputed (fail-soft, like survivorship).
- **[RBAC bypass]** a merge that skipped `ObjectService` would leak cross-tenant. → every read/write
  goes through `ObjectService`; controller declares `#[NoAdminRequired]` and relies on it, matching
  `DuplicateController`.

## Migration Plan

1. Add `x-openregister-merge` to `Schema::ANNOTATION_VOCABULARY` + a `MergeAnnotationValidator`
   wired non-fatally in `SchemaMapper` (alongside `validateSurvivorshipAnnotation`).
2. Ship `lib/Settings/merge_operation_register.json` (imported by the standard register-import path).
3. Add `MergeService`, `ObjectsMergedEvent`, `MergeController`, and the three routes.
4. Rollback: remove the routes + controller + service + event + register JSON and drop the vocabulary
   entry. No data migration is destroyed — `mergeOperation` rows are additive log entries and no
   existing schema is altered.

## Open Questions

See DEFERRED_QUESTIONS in the change summary: (a) sibling annotation vs survivorship `merge` block,
(b) reversal-window default location, (c) event-only vs also firing an OR webhook, (d) reversal
snapshot fidelity (exact re-split vs best-effort).
