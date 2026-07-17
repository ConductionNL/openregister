# Retrofit — event bundle (6 sub-clusters, all extend)

Bundled reverse-spec of the OpenRegister event value-objects in `lib/Event/` (36 files). These are Nextcloud `OCP\EventDispatcher\Event` payload classes: a constructor that captures entity/context state plus getters that expose it to listeners. They ARE the published contract of pre-existing capabilities, so the architect classified all 6 sub-clusters as `--extend`. This change retroactively annotates the event methods against the capabilities they realise and mints REQs only where the existing spec genuinely lacks the contract.

## Sub-clusters and extend decisions

1. **event-object-lifecycle-crud** → `event-driven-architecture` — The canonical CRUD pre/post pair for `ObjectEntity` (ObjectCreating/Created, Updating/Updated, Deleting/Deleted). Already fully covered by the existing "All entity mutations MUST dispatch typed PHP events" and "Pre-mutation events MUST support rejection..." requirements (and the getters were annotated by `retrofit-2026-04-23-annotate-openregister`). **Annotate only — no new REQ.**

2. **event-object-state-changes** → `event-driven-architecture` — Locked/Unlocked/Reverted/Transitioned. Lock + Reverted are covered by the existing "Lock and revert operations dispatch specialized events" scenario; ObjectUnlockedEvent's getter is already annotated. **ObjectTransitionedEvent has no spec coverage at all** (state-machine transitions fired after a lifecycle-field update, with a 7-arg constructor carrying action/from/to/userId/register/schema) — **mint 1 new REQ**. ObjectUnlockedEvent is the symmetric pair of the lock event and the existing scenario only asserts lock; **mint 1 new REQ** so the unlock half of the contract is explicit.

3. **event-meta-entity-crud-non-object** → `event-driven-architecture` — 21 files for the seven non-Object meta-entities (Agent, Application, Configuration, Conversation, Organisation, Source, View). The existing "Non-object entity mutations dispatch corresponding typed events" scenario already enumerates exactly these entity types. **Annotate only — no new REQ.** Note: `ConfigurationUpdatedEvent` and `ViewUpdatedEvent` carry no getters (constructor-only), so only their constructors are brought into coverage; all other meta-entity getters were already annotated by the 2026-04-23 retrofit.

4. **event-reference-existence-validation** → `reference-existence-validation` — ReferenceValidatedEvent + ReferenceValidationFailedEvent. The capability's existing "Validation events MUST be dispatched for notification and extensibility" requirement already names both events and their payload shape. **Annotate only — no new REQ.**

5. **event-rbac-custom-scope** → `rbac-scopes` — CustomScopeEvaluatingEvent (voting allow()/deny(), first-vote-wins, hasVerdict()) + CustomScopeEvaluatedEvent (telemetry). Both carry a file-level `@spec openspec/changes/rbac-scopes/tasks.md` already. The custom-verb extension point is not yet a published requirement in the current `rbac-scopes/spec.md` (which documents the canonical five verbs). **Mint 1 new REQ** capturing the custom-scope event handshake, then annotate methods against it.

6. **event-deep-link-registration** → `deep-link-registry` — DeepLinkRegistrationEvent. The capability's existing "Apps SHALL register deep link patterns via boot-time events" requirement already names this event and its `register()` convenience method (already annotated). The `getRegistry()` getter just exposes the wrapped service. **Annotate only — no new REQ.**

## New REQs (3 total)

- `event-driven-architecture` — Requirement: State-machine transitions MUST dispatch a typed ObjectTransitionedEvent
- `event-driven-architecture` — Requirement: Object unlock MUST dispatch a typed ObjectUnlockedEvent
- `rbac-scopes` — Requirement: Custom (non-canonical) action verbs MUST be resolvable via a voting event pair

## Guardrails applied

- Observed behaviour only; constructor signatures and getter return shapes taken directly from the source files.
- No scanner false-positives included (these are real getter methods, not mis-parsed inline branches).
- Heavy bias to `--extend`: 3 of 6 sub-clusters are annotation-only against existing requirements.

Source: `/tmp/or-scan/bundle-event-all.json` (architect-bundled, 2026-05-24).
