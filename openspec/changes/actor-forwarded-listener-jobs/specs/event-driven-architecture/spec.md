## ADDED Requirements

### Requirement: Deferred listeners MUST forward the acting context to their background jobs

Heavy post-save object-event listeners moved off the write path MUST capture
the acting context at dispatch time (session user id
and active organisation uuid, both nullable) into a serializable
`DeferredListenerContext`, and their background jobs MUST extend
`ActorForwardedJob`, which re-establishes the captured user via
`IUserManager::get()` + `IUserSession::setUser()` before the deferred work
runs and restores the previous session user in a `finally` block — including
when the deferred work throws — so a cron worker never leaks one job's
identity into the next. Organisation context re-derives from the restored
user's persistent configuration; a captured-vs-current drift is logged and
the job proceeds under the user's current authority.

#### Scenario: Job runs the deferred work as the captured user and restores the session

- **GIVEN** an object save dispatched by user `alice` deferred listener work into a job
- **WHEN** the job runs in a session-less cron worker
- **THEN** the job resolves `alice` through `IUserManager` and sets her on `IUserSession` before executing
- **AND** the deferred work observes `alice` as the session user (e.g. translator attribution records `alice`)
- **AND** after execution the session user is restored to its pre-job value (null in cron)

#### Scenario: Session user is restored even when the deferred work throws

- **GIVEN** a job whose deferred work throws midway
- **WHEN** the job runs
- **THEN** the previous session user is restored in a `finally` block before the exception propagates

#### Scenario: A job for a deleted or unresolvable user never runs under a wrong identity

- **GIVEN** a job captured for a user that no longer exists
- **WHEN** the job runs
- **THEN** the deferred work is skipped with a log entry and no impersonation occurs

#### Scenario: A context captured without a session runs without impersonation

- **GIVEN** an object written from occ/cron with no session user
- **WHEN** the deferral captures a null userId and the job later runs
- **THEN** the deferred work executes without calling `setUser()` — identical to the inline session-less behaviour

### Requirement: Deferred listener jobs MUST be chunk-level, idempotent, and stale-safe

Listeners deferring through `ListenerDeferralService` MUST enqueue chunk-level
jobs (buffered entries flushed per chunk and at request shutdown), never one
job per object of a bulk save. Jobs MUST treat delivery as at-least-once:
re-fetch each entry's object by (uuid, register, schema), skip entries whose
object is gone or soft-deleted, and reconcile against current state so a
re-run converges. Entries MAY declare a dedupe key so writes that share an
evaluation target (e.g. threshold triggers per schema) coalesce into one
entry.

#### Scenario: Bulk save enqueues chunked jobs, not per-object jobs

- **GIVEN** a bulk save of 250 objects on a schema with a deferred listener and a chunk size of 100
- **WHEN** the request completes
- **THEN** at most ⌈250/100⌉ = 3 jobs are enqueued for that listener, each carrying an entry array

#### Scenario: A job entry whose object was deleted no-ops

- **GIVEN** an enqueued projection entry for object `X`
- **AND** `X` is deleted before cron runs
- **WHEN** the job processes the entry
- **THEN** the entry is skipped (no sidecar rows are resurrected, no notification is dispatched)

#### Scenario: Threshold evaluations coalesce per schema

- **GIVEN** 50 objects of schema `S` saved in one request, where `S` declares a threshold notification
- **WHEN** the deferral buffers the entries with the (register, schema) dedupe key
- **THEN** exactly one threshold evaluation entry for `S` is enqueued

#### Scenario: The kill switch restores inline execution

- **GIVEN** app config `openregister/listenerDeferral` set to `inline`
- **WHEN** an object event fires on a schema using a deferred listener's feature
- **THEN** the listener performs its work synchronously as before this change and enqueues nothing

### Requirement: Translation projection, annotation notifications and threshold evaluation MUST run via actor-forwarded jobs

Three listeners MUST perform their heavy work in actor-forwarded jobs:
`TranslationProjectionListener` (created/updated/transitioned),
`AnnotationNotificationListener` (all triggers) and
`AggregationThresholdListener` (created/updated/transitioned), keeping only a
request-cached schema-config gate inline so schemas without the respective
feature enqueue nothing. Delete-time work stays inline for
`TranslationProjectionListener` (sidecar purge) and
`AggregationThresholdListener` (the entity is not re-fetchable post-delete).
The deferred job MUST produce the same effect the inline listener produced:
projected translation rows record the acting user as translator; update
notifications evaluate field-change and calculatedChange conditions against
the pre-update snapshot captured at dispatch time and the current object
data; threshold crossings dispatch on the rising edge only.

#### Scenario: Deferred projection matches the inline effect including translator attribution

- **GIVEN** user `alice` updates an object with translatable properties
- **WHEN** the enqueued `TranslationProjectionJob` runs in cron
- **THEN** the translations sidecar contains the same rows an inline `project()` would have written, with `alice` recorded as translator

#### Scenario: Deferred update notification keeps old/new condition context

- **GIVEN** an update event with a real pre-update state on a schema with notification rules
- **WHEN** the enqueued `AnnotationNotificationDispatchJob` runs
- **THEN** the dispatcher receives trigger `updated` with `_oldData` from the captured snapshot and `_newData` from the re-fetched current object
- **AND** a `calculatedChange` dispatch follows with the same context, mirroring the inline listener

#### Scenario: Deferred threshold evaluation dispatches on the rising edge

- **GIVEN** a schema with a threshold notification whose aggregation value crosses the boundary
- **WHEN** the enqueued `AggregationThresholdJob` runs
- **THEN** the notification is dispatched once and the state cache records `above`, exactly as the inline evaluation did
