# Design — OpenRegister System-Schema Notifications

## Context

OpenRegister owns the notification engine (`notificatie-engine`). The engine
fires schema-declared `x-openregister-notifications` rules off
`ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectTransitionedEvent`, with
the dispatcher resolving rules from a stored `ObjectEntity` via
`$object->getSchema()` → `SchemaMapper`. OpenRegister's own system entities
(`Register`, `Schema`, `Configuration`, `Source`, `Synchronization`, `Import`,
`Webhook`, `Agent`) are plain `OCP\AppFramework\Db\Entity` records and do NOT
flow through those events — so today no operational event on OpenRegister's own
domain can drive a notification.

## Goal

Let OpenRegister notify platform admins / integration ops about its own
operational events (sync/import failure, schema/config change, source/agent
health) using the **same** engine (channels, recipients, preferences, i18n,
rate-limiting, coalescing) as every other app — without forking a parallel
notification path.

## Decision: kind = code

Annotation alone cannot work because the system entities never reach the
dispatcher. Two pieces of engine wiring are required:

1. **System-schema rule source.** The dispatcher must be able to resolve
   `x-openregister-notifications` for a system entity. Two viable shapes
   (decide during implementation):
   - **(a) Synthetic system schemas** — seed real `Schema` rows for the system
     entities and attach annotations, so the existing
     `SchemaMapper`-based lookup just works. Cleanest reuse; needs the system
     entities to be addressable as schema-backed objects.
   - **(b) System-rule registry** — a small in-code map of system-schema-slug →
     rule array the dispatcher consults when the entity is a system entity.
     Less invasive; duplicates a little of the schema-lookup path.
   Prefer (a) if the system entities can be represented as schema-backed
   objects without distorting the data model; fall back to (b) otherwise.

2. **System-event bridge.** Emit (or adapt existing) create/update/transition
   signals for the system entities and route them through
   `AnnotationNotificationListener`, populating `_oldData`/`_newData` so the
   `notification-updated-field-change-condition` `condition` block (status /
   health-field changes) is available for system schemas too.

## Recipients & subjects

- Recipients: integration-ops / `admin` groups (`{"kind":"groups",...}`),
  schema/config owners (`{"kind":"object-acl","permission":"manage"}` or an
  owner field). No external email needed (all internal uids/groups).
- Subjects: bilingual nl/en, metadata-only (schema name, source name, run id) —
  never payload contents.

## Triggers per event

| Event | Trigger | Notes |
|-------|---------|-------|
| Sync failure | `transition`→`failed` **or** `updated`+`condition` equals `failed` | depends on whether Synchronization has named lifecycle actions |
| Import failure | `transition`→`failed` **or** `updated`+`condition` | same |
| Schema changed | `updated` (optionally `condition` on a version field) | owners + admin |
| Config changed | `updated` | admin group |
| Source unhealthy | `threshold` (consecutive failures) **or** `updated`+`condition` health field | numeric threshold preferred if a failure counter exists |
| Agent unhealthy | `threshold` / `updated`+`condition` heartbeat field | |

## Open questions (resolve in implementation)

1. Synthetic system schemas (a) vs system-rule registry (b)?
2. Canonical slug/identifier for each system schema.
3. Which system entities already emit create/update/transition signals vs need
   new event emission (esp. Synchronization/Import run outcomes, Source/Agent
   health checks).
4. Whether Source/Agent health is best modelled as `threshold` (needs a numeric
   failure counter) or `updated`+`condition` on a health field.

## Non-goals

- No change to stored-object notification behaviour.
- No new user-facing register JSON under `lib/Settings/`.
- No new external-email channel (out of scope; tracked in the engine gap notes).
