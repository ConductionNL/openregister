## ADDED Requirements

### Requirement: Post-event object listeners MUST defer heavy work through the existing deferral contract

A post-event listener MUST NOT perform outbound I/O, a database write, or an
unbounded query inside the request that produced the event — this covers every
listener registered on `ObjectCreatedEvent`, `ObjectUpdatedEvent` or
`ObjectDeletedEvent`. It MUST route the work through `ListenerDeferralService` into an
`ActorForwardedJob` subclass, keeping only a cheap interest gate and a buffered
entry append on the request path.

Post-events expose no mutation and no veto API — `ObjectCreatedEvent` exposes
only `getObject()`, `ObjectUpdatedEvent` adds `getNewObject()` /
`getOldObject()`, and neither implements `StoppableEventInterface`. Any
`stopPropagation()` or `setModifiedData()` call inside a post-event listener is
therefore dead code and MUST NOT be relied upon.

No new deferral mechanism MAY be introduced. The contract established by
`actor-forwarded-listener-jobs` is the only one, including its
`listenerDeferral` kill switch, which MUST continue to restore full synchronous
behaviour for every listener this requirement covers.

#### Scenario: Cleanup work leaves the delete request

- **GIVEN** an object is hard-deleted and `ObjectCleanupListener` is registered
  on `ObjectDeletedEvent`
- **WHEN** the delete request runs
- **THEN** the request-path work SHALL be limited to an interest gate plus an
  enqueue through `ListenerDeferralService`
- **AND** the CalDAV calendar scan, calendar unlink, vCard rewrite and the deck,
  email and comment deletions SHALL be performed by the resulting background job

#### Scenario: Kill switch restores synchronous behaviour

- **GIVEN** `openregister listenerDeferral` is set to `inline`
- **WHEN** any object lifecycle event covered by this requirement is dispatched
- **THEN** the listener SHALL execute its full work inline exactly as before
  this change
- **AND** no background job SHALL be enqueued for it

#### Scenario: A post-event listener cannot veto

- **GIVEN** a listener on `ObjectCreatedEvent` calls `stopPropagation()`
- **WHEN** the event completes
- **THEN** the write SHALL already have been persisted and SHALL NOT be affected

### Requirement: Pre-event listeners MUST remain synchronous and MUST NOT be deferred

Pre-event listeners MUST run inline within the write — that is, every listener
registered on `ObjectCreatingEvent`, `ObjectUpdatingEvent` or
`ObjectDeletingEvent`. They mutate the object
via `setObject()` before persistence, or may veto it, and deferral would produce
either a lost mutation or a write that cannot be stopped.

`CalculationOnSaveListener` MUST additionally be invoked exactly once per create,
because it consumes a sequence number; any change that could cause it to run
twice for one create is prohibited.

`HookListener` MUST retain its `Creating` / `Updating` / `Deleting`
registrations inline, because `HookExecutor::applyFailureMode` can veto the save
at that point. Only its `Created` / `Updated` / `Deleted` registrations may be
deferred, and the split MUST be made at the registration site.

#### Scenario: Pre-event mutation reaches persistence

- **GIVEN** `LifecycleInitialStateListener` is registered on `ObjectCreatingEvent`
- **WHEN** an object is created
- **THEN** the listener SHALL run inline and its `setObject()` mutation SHALL be
  present in the persisted row

#### Scenario: Sequence number is consumed once per create

- **GIVEN** a schema whose create path runs `CalculationOnSaveListener`
- **WHEN** one object is created
- **THEN** exactly one sequence number SHALL be consumed

#### Scenario: HookListener veto still works after the split

- **GIVEN** `HookListener` is registered inline on `ObjectCreatingEvent` and
  deferred on `ObjectCreatedEvent`
- **WHEN** a hook's failure mode rejects the save
- **THEN** the object SHALL NOT be persisted
- **AND** no deferred post-event job SHALL be enqueued for it

### Requirement: A post-event listener that stays synchronous MUST declare a named exception category

A post-event listener that is not deferred MUST carry a
`@listener-placement inline <category> — <reason>` annotation naming exactly one
of `realtime`, `sapi-memory`, `cheap-bounded` or `correctness`, with free text
stating the specific blocking fact.

The following OpenRegister listeners are exempted under this requirement, each
for the stated reason:

| Listener | Category | Blocking fact |
|---|---|---|
| `NotifyPushListener` | `realtime` | it is the realtime delivery channel, and it holds per-request static state (`self::$seen`, `$batchMode`, `$batchedCollections`) that assumes one request lifetime |
| `GraphQLSubscriptionListener` | `sapi-memory` | `SubscriptionService::pushEvent` stores via `apcu_store`; APCu is per-SAPI, so a cron worker writes a segment SSE readers never see |
| `AggregationCacheInvalidationListener` | `cheap-bounded` | one cache get plus one set; a later read in the same request would otherwise see stale aggregations |
| `ObjectMetricsListener` | `cheap-bounded` | one fail-soft INSERT; a job row plus a cron round trip costs more, and would blur the metric timestamp |
| `NotificationDedupePruneListener` | `correctness` | a prune landing after a same-UUID re-create would wipe freshly-armed state |
| `FlowTriggerListener` (`runInline` path) | `realtime` | `FlowTriggerService::runInline()` executes `executionMode: sync` flows inside the triggering request as a published read-after-write contract |

#### Scenario: Deferring the APCu-backed listener is prohibited

- **GIVEN** `GraphQLSubscriptionListener` writes subscription state via
  `apcu_store` on the web SAPI
- **WHEN** a change proposes to move it into a background job
- **THEN** the change SHALL be rejected
- **AND** the stated reason SHALL be that a cron worker writes a different
  per-SAPI APCu segment, so SSE readers would silently never observe the event

#### Scenario: An inline post-event listener without a category is a defect

- **GIVEN** a post-event listener that performs outbound I/O inline
- **AND** it carries no `@listener-placement inline <category>` annotation
- **WHEN** the mechanical gate evaluates it
- **THEN** the gate SHALL report a failure
- **AND** the run SHALL exit with a non-zero status

### Requirement: Deferred entries MUST carry every value that cannot be re-fetched at job time

A deferred handler that needs deleted-object state or pre-update state MUST
carry that state in the deferred entry payload, because `ObjectDeletedEvent` is
dispatched after a hard delete (`MagicMapper.php:8721`, following
`deleteObjectEntity(hardDelete: true)`) and the row no longer exists when the
job runs.

`SourceRecordChangeListener` MUST resolve the affected master UUIDs **inline**,
while the data still exists, and MUST carry the resolved **new and old** master
UUIDs in the entry — not the source object's uuid. The old master is required so
that a source record re-parented to a different master refreshes both masters.
The entry's dedupe key MUST be the master uuid, so a bulk edit of many source
records collapses to one recompute per master.

Delivery is at-least-once. Every deferred handler MUST re-resolve its target and
MUST no-op when the object is absent or soft-deleted.

#### Scenario: Re-parented source refreshes both masters

- **GIVEN** a source record whose master changes from master A to master B in one
  update
- **WHEN** the deferred recompute runs
- **THEN** both A and B SHALL be recomputed
- **AND** the entry SHALL have carried both master UUIDs, resolved inline

#### Scenario: Deferred delete-content no-ops after a same-UUID re-create

- **GIVEN** a deferred `ContextChatSubmissionListener` entry to delete content
  for `00000000-0000-0000-0000-000000000000`
- **AND** an object with that same uuid has since been created
- **WHEN** the job runs
- **THEN** it SHALL check existence first and SHALL NOT delete the content of the
  live object

#### Scenario: Bulk source edit collapses to one recompute per master

- **GIVEN** 500 source records belonging to one master are updated in a bulk save
- **WHEN** the deferred entries are flushed
- **THEN** the master SHALL be recomputed once, not 500 times

### Requirement: Trigger, flow and rule resolution MUST filter at query time

Resolution performed in reaction to an object event MUST constrain its query by
trigger, register and schema, MUST apply a bound, and MUST NOT render results it
only intends to filter. Loading every candidate object and filtering in PHP is
prohibited.

`OpenRegisterFlowResolver::flowsForTrigger()`
(`lib/Service/Flow/OpenRegisterFlowResolver.php:181`) currently performs an
unfiltered, unlimited, fully rendered `ObjectService::findAll()` of every flow
object on every object write and filters afterwards in PHP — measured at 144 ms
cold and 8–9 ms warm to return a single flow, and the largest single contributor
to event dispatch cost. It MUST be replaced with a filtered, bounded,
unrendered query, memoized per (trigger, register, schema) for the request.

The filtered result MUST be equivalent to the previous PHP-side filter for the
same inputs.

`WebhookService::dispatchEvent()` MUST evaluate its zero-webhook guard **before**
`extractPayload()` serializes the entity, so a write on an instance with no
webhooks configured pays no serialization cost
(`lib/Service/WebhookService.php:660`).

#### Scenario: Flow resolution does not load every flow

- **GIVEN** an instance with many flow objects and one flow matching the write's
  trigger, register and schema
- **WHEN** an object is created
- **THEN** the resolver SHALL issue a query constrained by trigger, register and
  schema with a bound
- **AND** it SHALL NOT call `findAll()` without a filter or without a limit

#### Scenario: Filtered resolution matches the previous PHP filter

- **GIVEN** a fixture set of flows covering matching and non-matching triggers,
  registers and schemas
- **WHEN** the filtered query and the previous PHP-side filter are both applied
- **THEN** the two resulting flow sets SHALL be equal

#### Scenario: No webhooks configured means no serialization

- **GIVEN** an instance with zero webhooks configured
- **WHEN** an object is created
- **THEN** `dispatchEvent()` SHALL return before `extractPayload()` runs
- **AND** the entity SHALL NOT be `jsonSerialize()`d

### Requirement: The listener placement classification MUST be recorded and mechanically enforced

Every listener registered on an object lifecycle event MUST have its sync/async
verdict recorded in the change's `tasks.md` as an auditable table stating,
per listener: the events it is registered on, whether it is pre- or post-event,
whether it is deferred, and — when it is not — the specific blocking fact and
its exception category.

A mechanical gate MUST fail the build when a listener is registered on a `*ed`
post-event and its handler performs outbound I/O, a write, or an unbounded query
without either routing through `ListenerDeferralService` or carrying a valid
`@listener-placement inline <category> — <reason>` annotation. The gate MUST run
unconditionally and MUST propagate its failure to the process exit code.

#### Scenario: A new undeclared synchronous post-event listener fails the build

- **GIVEN** a PR adds a listener on `ObjectCreatedEvent` that posts to an
  external API inline
- **AND** it carries no deferral and no `@listener-placement` annotation
- **WHEN** the gate runs
- **THEN** it SHALL report a failure naming the listener
- **AND** the gate run SHALL exit with a non-zero status

#### Scenario: The classification table covers every registration

- **GIVEN** the change's `tasks.md`
- **WHEN** the recorded classification is compared to the listener registrations
  in `lib/AppInfo/Application.php`
- **THEN** every object lifecycle registration SHALL appear in the table with a
  verdict
