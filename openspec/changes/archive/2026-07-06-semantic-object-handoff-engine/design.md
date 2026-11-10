# Design: semantic-object-handoff-engine

## Contract source

The normative contract is `hydra/openspec/changes/semantic-object-handoff/specs/semantic-object-handoff/spec.md`
(ADR-051). This change's spec mirrors it 1:1. Kind contracts (the field sets the mapping targets)
are `hydra/.../specs/handoff-contract-case/spec.md` (`ns#Case`: title, summary, requester?,
channel, priority?, source) and `hydra/.../specs/handoff-contract-order-chain/spec.md` (`ns#Quote`,
`ns#Contract`, `ns#Invoice` with counterparty/currency/totalAmount + chain provenance). The engine
ships the four seed kind contracts as **data** (a versioned contract map in
`lib/Service/Handoff/`), keyed by kind URI, so validator + engine consult one source; new kinds are
added by extending that map alongside a hydra contract spec, never per-app.

## Verified state of the assets this change composes (HEAD = origin/development, 6b0534094)

| Asset | Verified behaviour | Delta |
|---|---|---|
| `lib/Service/SemanticTypeResolver.php` | `resolveSchemaByImplements(uri, ?consumingRegisterId)`: null-safe (any enumeration failure → null, never raises), request-scoped cache incl. negative entries, disabled-owning-app exclusion via `IAppManager::isEnabledForUser` (fallback `isInstalled`, fail-open only when no owning app is declared or app id is `openregister`), deterministic tie-break (consuming-register bias, else first-by-slug) + WARN log, and — from `virtual-schema-semantic-providers` — `allOf`-ancestor type inheritance with a circular guard. **BLOCKER: the file on HEAD contains committed merge-conflict markers (lines 61–69, 155–159, 193–290) and fails `php -l` at line 155.** The `origin/development` side of each hunk (the `implementedTypesWithAncestors` path) is the newer behaviour and is what conflict resolution keeps (task 0). | none besides task 0 |
| `JsonLdContextService::getImplementedTypes()` | `configuration.implements` when present, else `[configuration.jsonld.type]`; `x-schema-org` markers accepted by the resolver | none |
| `lib/Service/Integration/PropertyReferenceTypeValidator.php` | The ADR-048 dialect validator this change's validators mirror | sibling validators |
| Annotation-validator wiring | `NotificationAnnotationValidator` / `CalculationAnnotationValidator` are invoked from the `SchemaMapper` save path | register the two new validators the same way |
| `ObjectEntity.relations` + `lib/Service/Object/RelationHandler.php`, `RelationsController` | Relations are a JSON array on the object row, surfaced by the Related widget (UUIDs) | one reserved relation type `handoff` with direction semantics (`handed-off-to` / `originated-from`) |
| `AuditTrailMapper::createAuditTrail` | Immutable audit rows (used e.g. by `EdepotTransferService`) | one new action `handoff.executed` (+ `handoff.queued` / `handoff.dequeued`) |
| Durable-retry pattern | `lib/Db/WebhookLog(+Mapper)` (append-only attempt rows, `attempt` counter) + `lib/Cron/WebhookRetryJob.php` (`DEFAULT_INTERVAL = 300`, next-retry timestamps, retry-limit escalation) + `lib/BackgroundJob/WebhookDeliveryJob.php` (QueuedJob per attempt) | reuse the *pattern* for the handoff queue — `HandoffQueueEntry` + drain listeners + fallback TimedJob; no new queue abstraction invented |
| Routes | `schemas#resolveByImplements` (`/api/schemas/resolve-by-implements`, ADR-048) shows the registration style | two new `handoff#…` routes |
| Existing handoff code | `git grep -il handoff` over `lib/ appinfo/ src/` → **no hits**; the engine is green-field | everything below |

## HandoffService execution flow

```
execute(sourceRef, handoffId, actor):
  1. load source object (ObjectService, actor RBAC — read+write required on source)
  2. read x-openregister-handoff[handoffId] from the source schema  → 404 handoff-not-declared
  3. resolve provider: SemanticTypeResolver::resolveSchemaByImplements(targetSemanticType)
       null → whenUnavailable == hide  → 409 handoff-provider-unavailable (machine-readable, no 5xx)
              whenUnavailable == queue → persist HandoffQueueEntry, audit handoff.queued, return 202
  4. RBAC pre-check: actor create permission on the resolved schema     → 403, no escalation
  5. evaluate mapping → contract fields → translate via provider's handoffContract binding
       from: copy | const | template ({{prop}}, HTML-escaped, existing interpolation convention)
       semanticRef: carry the UUID reference, never dereference/copy
       provenance: engine-filled {app, register, schema, uuid} of the source
  6. ALL-OR-NOTHING execution (see the atomicity note below):
       a. create target object (ObjectService write path — schema validation, RBAC, tenancy)
       b. onSuccess.set applied to the source through the lifecycle-aware write path
          — failure ⇒ compensation: created target removed, error to caller
       c. TRANSACTION (IDBConnection): relations both ways (source += handed-off-to,
          target += originated-from) + audit row on both sides (actor, kind, handoff id,
          mapping hash, resolved schema, deferred?)
          — failure ⇒ rollback + compensation: target removed, source data restored
  7. afterwards: dispatch HandoffExecutedEvent (never inside a transaction)
```

**Atomicity — implementation note (verified live on postgres, 2026-07-06).** The originally
drafted single `IDBConnection` transaction around create→relations→audit→onSuccess is
**infeasible**: `ObjectService::saveObject` issues best-effort probe queries that fail and are
swallowed by design (e.g. the MySQL-only `SHOW VARIABLES LIKE 'max_allowed_packet'` — now
platform-guarded by this change — and cross-schema magic-table `COUNT(*)` probes against tables
that may not exist), and on PostgreSQL **any** failed statement inside an open caller-managed
transaction aborts the whole transaction (SQLSTATE 25P02). The engine therefore implements the
contract's observable guarantee ("either fully happens or leaves no partial state") as: the target
create is atomic within OR's own write path; the source update and the relations+audit transaction
are each compensated on failure by removing the created target and restoring the source's
pre-handoff data. Failure at any step leaves no target, no provenance relation, no `handoff.*`
audit row, and no source mutation. Event dispatch happens only after everything succeeded, so a
throwing listener can never undo a completed handoff (mirrors `ObjectCreatedEvent` conventions).

`trigger: lifecycle:<state>` handoffs run through the same `execute()` from a lifecycle-transition
listener; v1 gates them to transitions performed by a real actor (the transition's user is the
handoff actor — no system-user privilege lane).

## Queue mode (`whenUnavailable: queue`) — KEPT in v1 per owner decision 2026-07-06

Storage: `oc_openregister_handoff_queue` via `HandoffQueueEntry` entity + mapper — source ref,
handoff id, target kind URI, requesting user, mapping snapshot hash, created/attempted timestamps,
`attempt` counter, status (`parked` / `executed` / `failed-permission` / `failed-validation` /
`cancelled`), last error. Append-only attempt semantics copied from `WebhookLog`.

Draining — three triggers, cheapest first:
1. **Schema-save listener**: when a saved schema's implemented types (incl. `allOf` ancestors)
   intersect a parked kind, drain that kind.
2. **App-enabled listener** (`OCP\App` management events): a re-enabled provider app makes its
   lingering schemas resolvable again — drain all parked kinds.
3. **Fallback `TimedJob`** (same 300 s cadence class as `WebhookRetryJob`): sweeps `parked`
   entries whose kind now resolves; catches anything the listeners missed (e.g. register import
   paths that bypass the schema-save event).

Deferred execution runs `execute()` **as the recorded requesting user** (impersonation via the
same user-session mechanics OR background jobs use), re-evaluating RBAC at drain time: if the user
has since lost create permission the entry goes `failed-permission` and the requester is notified —
the queue never becomes a privilege-escalation time capsule. The audit row records
`deferred: true` + park/drain timestamps (contract scenario "queue mode").

UI: a parked handoff shows as "queued — waiting for a providing app" on the source object
(availability endpoint reports `queued` state per entry).

## Validators

Two save-time validators in `lib/Service/Handoff/`, registered exactly where
`NotificationAnnotationValidator` runs (SchemaMapper save path), mirroring
`PropertyReferenceTypeValidator`'s error style:

- **HandoffAnnotationValidator** (source side): entry ids unique + slug-like; `targetSemanticType`
  absolute URI (`handoff-bad-target-type`); trigger `manual` or `lifecycle:<state>` with the state
  existing in the schema's lifecycle when declared; mapping keys ⊆ the kind's contract fields with
  every mandatory field mapped; expression kinds ⊆ the five (`handoff-bad-mapping-expression`);
  `whenUnavailable` ∈ {hide, queue}; `onSuccess.set` keys exist on the source schema
  (`handoff-bad-success-update`).
- **HandoffContractBindingValidator** (provider side): when a schema's implemented types contain a
  contract-carrying kind AND a `handoffContract` block is present, every mandatory contract field
  must bind to an existing own property (`handoff-contract-incomplete`, listing missing fields).
  A schema that `implements` a kind with NO binding block is not a handoff provider (it may
  implement the kind for ADR-048 reference purposes only) — the resolver result is filtered by
  "has a complete binding" inside HandoffService, so reference-resolution behaviour is untouched.

## REST surface

`lib/Controller/HandoffController.php`, routes in `appinfo/routes.php`:

| Route | Verb | Behaviour |
|---|---|---|
| `/api/objects/{register}/{schema}/{id}/handoffs` | GET | Availability: every declared handoff with `available` / `unavailable(reason)` / `queued`, resolved provider schema when present |
| `/api/objects/{register}/{schema}/{id}/handoffs/{handoffId}` | POST | Execute (or park, queue mode); returns created target ref / 202 parked / typed errors |

Both `#[NoAdminRequired]` **with a per-object authorization guard in the method body** (ADR-005,
gate no-admin-idor): read access for availability, write for execute; create-permission on the
target checked in the service (semantic-auth gate: the annotation matches the real requirement).
No `#[PublicPage]`, CSRF left enabled (POST is a state change from our own UI).

## Events (ADR-041)

`lib/Event/HandoffExecutedEvent.php`: provenance (`sourceApp`, source register/schema/uuid,
subject label), `targetSemanticType`, resolved target register/schema/uuid, `handoffId`,
`correlationId` (UUID minted per execution, also stamped on both audit rows and the queue entry),
`deferred` flag. Terminal-state feedback from consumers reuses the existing ADR-041
conclusion-event pattern — no new conclusion event class here; the handoff event carries the
correlation id consumers echo back. Integration registry is not a transport (gate-27).

## Security posture summary

- Creation happens **under the target schema's RBAC as evaluated for the calling user** — never a
  system escalation (contract scenario "Caller lacks create permission").
- Queue drain re-evaluates RBAC as the original requester at execution time.
- `template` interpolation is HTML-escaped; `semanticRef` never copies referenced data.
- Availability endpoint leaks no schema internals beyond what ADR-048's resolve endpoint already
  exposes (provider schema slug/title).

## Out of scope

- UI action rendering (nc-vue consumes the availability endpoint; ships with the adoption changes).
- Cross-instance/federated handoff; continuous post-handoff sync (ADR-051 out of scope).
- Repo-wide resolution of the other 17 committed-conflict-marker files (tracked as a repo defect;
  task 0 here covers only what this change builds on: `SemanticTypeResolver.php` + its test).
