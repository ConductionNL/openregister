## Context

Notification `subject`/`message` templates interpolate `{{prop}}` from the triggering object's data (`AnnotationNotificationDispatcher::interpolate()`). Relation fields hold a UUID, so the rendered text shows the UUID rather than a human label.

## Decisions

### Declarative vs imperative (ADR-031)
The notification rules stay declarative; this is a small refinement to the engine's interpolation (imperative engine code) — resolving a relation reference to a name needs an ObjectService lookup, which is not expressible as schema metadata. Scoped to the existing engine, not a new service.

### Resolution
- Only values matching a UUID pattern are resolved (so plain text/numbers pass through untouched).
- Resolution uses `ObjectService::find(id, _rbac: true)` — same RBAC-scoped path as the action-deeplink resolver; interpolation runs at dispatch time (request context), so RBAC is the dispatching user's.
- Result cached per dispatcher instance (keyed by UUID) to avoid repeat lookups across a recipient fan-out.
- Falls back to the raw value when: not a UUID, ObjectService absent, object unresolvable, or object has no name. Never throws (debug-logs and degrades).

## Seed Data
N/A — engine behaviour change, no schemas.

## Risks / Trade-offs
- One extra `find()` per distinct relation UUID per dispatch (cached). Notification volume is low; acceptable.
- A name is only as good as the related object's `getName()`; nameless objects keep the UUID (no regression).
