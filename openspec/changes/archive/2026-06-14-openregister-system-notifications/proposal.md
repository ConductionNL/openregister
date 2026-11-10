---
kind: code
depends_on: [notification-updated-field-change-condition]
---

# OpenRegister System-Schema Notifications

## Why

The fleet notification analysis (`hydra/openspec/fleet-notification-plan.md`,
openregister row) calls for OpenRegister to notify platform admins and
integration ops about its **own operational domain events** — synchronisation
and import failures, schema/configuration changes, and unhealthy
sources/agents. OpenRegister owns the notification engine, so it is the natural
place to prove that the engine can dispatch on the platform's own system
schemas.

**The blocking open question (must be answered before any annotation has
effect):** the dispatch path resolves rules from an `ObjectEntity` via
`$object->getSchema()` → `SchemaMapper`, and the listener subscribes to
`ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectTransitionedEvent`. Those
events are emitted for **stored register objects**, not for OpenRegister's own
system entities (`Register`, `Schema`, `Configuration`, `Source`,
`Synchronization`, `Import`, `Webhook`, `Agent`), which are plain
`OCP\AppFramework\Db\Entity` records in `oc_openregister_*` tables and never
flow through `ObjectCreatedEvent` et al. **Verification confirmed the engine
does NOT currently dispatch on OpenRegister's own system schemas.** So a
pure-annotation approach is not sufficient here, and this change is `kind:
code`: it adds the engine wiring (system-schema event source + a system-schema
rule source) so OpenRegister's operational events can drive notifications, then
declares the recommended rule set.

System-schema slugs below are marked **"(system schema — confirm slug)"**
because OpenRegister's system entities are not currently registered as
annotatable schemas in a user register; the implementation must decide and fix
the canonical slug/identifier for each system schema as part of the wiring.

## What Changes

### Engine wiring (the prerequisite)

- Add a **system-schema notification source**: a way for OpenRegister's own
  system entities to carry `x-openregister-notifications` rules (either a
  built-in system register/schema set seeded for the system entities, or a
  system-schema rule registry the dispatcher consults), so the dispatcher's
  rule lookup can resolve rules for a system entity the same way it resolves
  them for a stored `ObjectEntity`.
- Add a **system-event bridge**: emit (or adapt) create/update/transition
  events for the relevant system entities (`Synchronization`/`Import` run
  outcomes, `Schema`/`Configuration` saves, `Source`/`Agent` health) and route
  them through `AnnotationNotificationListener` → `AnnotationNotification
  Dispatcher` with `_oldData`/`_newData` populated so the
  `notification-updated-field-change-condition` `condition` block works for
  system schemas too.
- Reuse the existing channels, recipient resolvers, rate-limiting,
  coalescing, preference-override and i18n machinery unchanged — only the rule
  source and event source are extended to system schemas.

### Recommended rule set (target state, once wiring lands)

Declared via `x-openregister-notifications` on the system schemas:

- **Synchronization failure** (`synchronization` system schema — confirm slug):
  `transition` → `failed` action, OR `updated` + `condition`
  `{"field":"status","operator":"equals","value":"failed"}` → integration-ops
  group + the sync's owner. Recipients `{"kind":"groups","groups":["admin"]}`
  (+ owner field if present).
- **Import failure** (`import` system schema — confirm slug): same shape —
  `transition`→`failed` or `updated`+condition → integration-ops group.
- **Schema changed** (`schema` system schema — confirm slug): `updated` (no
  condition, or `condition` on a version field) → schema owners /
  `{"kind":"object-acl","permission":"manage"}` + admin group.
- **Configuration changed** (`configuration` system schema — confirm slug):
  `updated` → admin group (`{"kind":"groups","groups":["admin"]}`).
- **Source unhealthy** (`source` system schema — confirm slug): `threshold`
  aggregation on consecutive failures, OR `updated`+condition on a health
  field → integration-ops group.
- **Agent unhealthy** (`agent` system schema — confirm slug): `threshold` /
  `updated`+condition on a health/heartbeat field → integration-ops group.

Subjects are bilingual (`nl`/`en`) and metadata-only (no payload contents).

## Capabilities

### Modified Capabilities

- `notificatie-engine`: the dispatch path is extended so that OpenRegister's
  **own system schemas** (register/schema/configuration/source/synchronization/
  import/webhook/agent) can declare and fire `x-openregister-notifications`
  rules for operational events, via a system-schema rule source and a
  system-event bridge, reusing all existing channels/recipients/preferences/
  i18n. Builds on `notification-updated-field-change-condition` so
  status/health-field-change conditions apply to system schemas.

## Impact

- **Code (OpenRegister):** new system-event bridge + system-schema rule source
  feeding `AnnotationNotificationListener` / `AnnotationNotificationDispatcher`;
  wiring for the relevant system entities (`Synchronization`, `Import`,
  `Schema`, `Configuration`, `Source`, `Agent`). No new user-facing schemas in
  a `lib/Settings/*_register.json`; system-schema identifiers fixed during
  implementation.
- **Open question to resolve in implementation:** confirm whether system
  entities get a synthetic system register/schema (annotatable like a normal
  schema) or a dedicated system-rule registry; confirm the canonical slug for
  each system schema; confirm which system entities already emit usable
  create/update/transition signals vs. need new event emission.
- **No** changes to existing stored-object notification behaviour. Back-compat:
  apps that annotate their own user schemas are unaffected.
- **Branch model:** left in the working tree on the current branch — no
  commit/PR from this task (OpenRegister's branch model is handled separately).
