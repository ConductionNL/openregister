---
kind: code
depends_on: [semantic-object-handoff]
chain:
  - semantic-object-handoff              # hydra (kind: config) — kind contracts + engine requirements + ADR-051
  - semantic-object-handoff-engine       # this change (openregister, kind: code) — the engine implementation
  - semantic-handoff-emit                # pipelinq (kind: config) — depends_on this engine
  - semantic-case-intake                 # procest (kind: config) — depends_on this engine
  - semantic-invoice-consume             # shillinq (kind: config) — depends_on this engine
---

## Why

ADR-051 (hydra) decides that cross-app workflows hand objects over via **canonical semantic
kinds** (`https://openregister.app/ns#Case`, `ns#Quote`, `ns#Contract`, `ns#Invoice`), never via
point-to-point bridges, and that **OpenRegister owns the handoff/conversion engine** (ADR-022
exclusivity). The cross-app coordination head `hydra/openspec/changes/semantic-object-handoff/`
defines the contract: the `x-openregister-handoff` dialect, the `handoffContract` binding, the
`HandoffService` behaviour, degradation modes, ADR-041 event emission, and the REST surface. Its
`specs/semantic-object-handoff/spec.md` is the normative contract; this change is the OR-side
implementation plan, mirroring those requirements 1:1 so the OR main spec and the hydra contract
never drift.

The demanded flows are real and unserved: the pipelinq→procest "Pipelinq Bridge" has been
advertised in both READMEs with zero code behind it (re-confirmed 2026-07-05), and pipelinq's won
deals are retyped by hand into shillinq's Quote/Contract/Invoice chain. The read-direction
counterpart (ADR-048 semantic references) is already live on OR `origin/development` via
`lib/Service/SemanticTypeResolver.php` — this change adds the write/convert direction on top of it.

Owner decision 2026-07-05/06 (Ruben): OR owns the engine, and `whenUnavailable: queue` is **kept in
v1** (not cut) — a handoff declared with queue-mode deferral parks when no provider is installed
and executes automatically once one is.

## What Changes

- **`x-openregister-handoff` dialect + schema-save validator** — a new ADR-031 dialect on the
  *source* schema declaring `id`, `targetSemanticType`, `trigger` (`manual` | `lifecycle:<state>`),
  a `mapping` restricted to five expression kinds (`from`, `const`, `template`, `semanticRef`,
  `provenance`), optional `whenUnavailable` (`hide` default | `queue`), and optional
  `onSuccess.set`. Validated at schema save by a sibling of the ADR-048
  `PropertyReferenceTypeValidator` (`lib/Service/Integration/PropertyReferenceTypeValidator.php`),
  wired where the existing annotation validators run (`SchemaMapper` save path, same as
  `NotificationAnnotationValidator` / `CalculationAnnotationValidator`).
- **`handoffContract` binding validation on implementing schemas** — a schema claiming a kind via
  the existing `implements[]` / `jsonld.type` / `x-schema-org` markers declares a `handoffContract`
  block binding each kind-contract field to one of its own properties; save-time validation rejects
  incomplete mandatory bindings (`handoff-contract-incomplete`).
- **`HandoffService`** — resolve the provider via the shipped `SemanticTypeResolver` (null-safe,
  disabled-app-excluded, deterministic tie-break — verified on HEAD), create the target object
  through `ObjectService` under the **caller's** RBAC, write the typed `handoff` provenance
  relation both ways, write one immutable audit-trail row per side, apply `onSuccess.set` to the
  source through the lifecycle-aware write path. Steps are atomic — a failed handoff leaves no
  partial state (no relation, no audit, no source mutation).
- **ADR-041 event emission** — a typed `HandoffExecutedEvent` (provenance + source/target
  identifiers + kind + handoff id + correlationId) dispatched after commit so the consuming app
  runs intake logic in its own DI context; terminal-state feedback reuses the existing ADR-041
  conclusion-event pattern. The integration registry is NOT the transport (gate-27).
- **REST surface** — execute + availability endpoints on a new `HandoffController`, registered in
  `appinfo/routes.php` with explicit auth posture and per-object authorization (ADR-005/016/029).
- **Both degradation modes** — `hide`: action absent, API returns `handoff-provider-unavailable`
  (not 5xx). `queue`: the request is parked in a durable queue (new `HandoffQueueEntry` Db entity
  mirroring OR's shipped `WebhookLog` + `WebhookRetryJob` durable-retry pattern — reused, not
  invented) and drained when a provider appears (schema-save/app-enable listeners + a fallback
  `TimedJob`).
- **Vocabulary bookkeeping** — the seed kinds `ns#Case`, `ns#Quote`, `ns#Contract`, `ns#Invoice`
  and their mandatory/optional contract fields are consumed from the hydra contract specs
  (`specs/handoff-contract-case/spec.md`, `specs/handoff-contract-order-chain/spec.md`); this
  change hard-codes no app or schema slug for them.

## Capabilities

### New Capabilities
- `semantic-object-handoff`: the OR-owned handoff/conversion engine — dialect + validators,
  `HandoffService`, degradation (hide + queue), ADR-041 events, REST surface. Mirrors the hydra
  contract spec 1:1.

### Modified Capabilities
<!-- None. The engine composes shipped capabilities (SemanticTypeResolver, JsonLdContextService,
     ObjectService, relations, audit trail, webhook-style durable retry) without altering their
     requirements. -->

## Impact

- **New code**: `lib/Service/Handoff/` (service, dialect validator, contract-binding validator,
  mapping evaluator, queue drainer), `lib/Db/HandoffQueueEntry(+Mapper)`, `lib/Event/`
  handoff-executed event, `lib/Controller/HandoffController.php`, listeners (schema-save /
  app-enabled queue drain), one `TimedJob` fallback drainer, new routes in `appinfo/routes.php`,
  a migration for the queue table.
- **Consumes (unchanged)**: `SemanticTypeResolver` (incl. its `allOf`-ancestor type inheritance
  shipped by `virtual-schema-semantic-providers`), `JsonLdContextService::getImplementedTypes()`,
  `ObjectService` (RBAC + multitenancy write path), `ObjectEntity.relations` + `RelationHandler`,
  `AuditTrailMapper`, the `WebhookLog`/`WebhookRetryJob` durable-retry pattern, the schema-save
  annotation-validator wiring in `SchemaMapper`.
- **Pre-existing defect in the dependency (blocker)**: `lib/Service/SemanticTypeResolver.php` on
  HEAD (= `origin/development`, 6b0534094) contains **committed merge-conflict markers** (lines
  61–69, 155–159, 193–290; `php -l` fails at line 155). 17 more files share the defect (incl.
  `lib/AppInfo/Application.php`). Task 0 resolves at least the resolver + its test before any
  engine code builds on it.
- **APIs**: two new authenticated endpoints (execute, availability); no change to existing routes.
- **No new register schemas** — the dialect and binding live on consumer/provider app schemas; the
  queue is a Db table, not a register.
- **Downstream**: pipelinq `semantic-handoff-emit`, procest `semantic-case-intake`, shillinq
  `semantic-invoice-consume` (already authored) list this change as their dependency.
