# Add Live Updates (notify_push backend)

## Problem

OpenRegister frontends and the shared `@conduction/nextcloud-vue` object store currently have no mechanism to learn that data has changed without polling. Polling is wasteful for quiet registers and simultaneously too slow for collaborative editing or near-real-time dashboards.

The existing GraphQL subscription stack ([openspec/specs/graphql-api/spec.md](../../specs/graphql-api/spec.md), status `implemented`) solves this for GraphQL clients via SSE. REST clients — which includes `@conduction/nextcloud-vue`'s object store and every Conduction app sitting on top of it — have no equivalent path.

The [realtime-updates capability spec](../../specs/realtime-updates/spec.md) already includes a `SHOULD` requirement that the system integrate with Nextcloud's `notify_push` app for native push delivery. That requirement is currently unimplemented — see `lib/Service/RealtimeService.php:20`: *"notify_push integration (NC infra)"* listed under v1 deferred scope.

This change ships the deferred notify_push integration.

## Context

- **Nextcloud notify_push** — optional sidecar Rust binary that ships with Nextcloud. It exposes a WebSocket bus for custom push events via `OCA\NotifyPush\Queue\IQueue::push('notify_custom', [...])`. JS clients consume it via `@nextcloud/notify_push`. Runtime-optional dependency: OR must soft-fail when absent.
- **Existing event infrastructure** — OR already dispatches `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` (in `lib/Event/`) on every object save. `GraphQLSubscriptionListener` (`lib/Listener/GraphQLSubscriptionListener.php`) already hooks them. The new listener attaches to the same events — no event duplication at source.
- **Two-transport architecture** — notify_push serves REST clients; SSE via `GraphQLSubscriptionController` serves GraphQL clients. Both transports hang off the same three internal events. A third transport MUST NOT be added without extending the realtime-updates spec.
- **Permission resolution** — OR's `PermissionHandler` (`lib/Service/PermissionHandler.php`) already encodes which users can read which objects. The listener uses this to fan out per-user pushes. If a "list readers of this object" method does not yet exist, this change adds it.
- **Deck pattern** — `OCA\Deck\NotifyPushEvents` (`custom_apps/deck/lib/NotifyPushEvents.php`) provides the canonical Nextcloud pattern for a constants class grouping `notify_custom` event string names. We mirror it.

## Proposed Solution

Implement the backend half of notify_push-based live updates:

1. **`lib/Push/PushEvents.php`** — constants class (`OR_OBJECT`, `OR_COLLECTION`) following the Deck pattern.
2. **`lib/Listener/NotifyPushListener.php`** — `IEventListener` for the three OR object lifecycle events. On each event:
   - Lazy-resolves `IQueue` from the container in a try/catch (soft-fail when notify_push is absent or partially installed).
   - Resolves authorised users via `PermissionHandler::getReadableByUsers($object)` (adding the method if it does not exist).
   - Emits one `or-object-{uuid}` push per authorised user carrying `{action, register, schema, uuid, version}` only.
   - Emits `or-collection-{register-slug}-{schema-slug}` per authorised user **on create/delete only** — not on field edits — to avoid list-refetch storms.
3. **Per-request deduplication** — a static accumulator coalesces multiple pushes for the same `(uuid, action)` pair within one HTTP request.
4. **Batch-mode flag** — `setBatchMode(bool)` / `flushBatch()` to suppress per-object pushes during bulk imports and emit a single summary collection event at end.
5. **Admin settings push status section** — extends the existing `OpenRegisterAdmin` page with a three-state probe (not installed / installed but unreachable / active).
6. **Documentation** — `docs/Integrations/Deck.md` as the worked example documenting Deck's existing push events, plus a `docs/Integrations/index.md` update pointing to it.

## Capabilities

| Capability | Type | Action |
|---|---|---|
| `realtime-updates` | backend | **Modified** — promotes notify_push from `SHOULD` to `MUST`; adds detailed event-string, fan-out, dedup, batch-mode requirements |
| `admin-settings` | settings | **New** — initialises the spec; adds Push notifications section |

## Out of Scope

- **GraphQL endpoint / subscriptions** — already implemented (status `implemented` in `openspec/specs/graphql-api/spec.md`). This change does not modify the GraphQL stack.
- **Frontend `useLiveUpdates` plugin** — separate `add-live-updates-plugin` change in the `nextcloud-vue` repo, blocks on this PR being merged.
- **`pushEvents()` extension on `IntegrationProvider`** — the `pluggable-integration-registry` change (still in flight) introduces the `IntegrationProvider` interface. Once that lands, a follow-up change `extend-integration-registry-push-events` will add a `pushEvents(): array` method so integrations declare which `notify_custom` events they emit. Splitting it out keeps this change shippable independently of the registry.
- **Per-app frontend migration** — consuming apps switching from polling to the subscription API are separate per-app changes.
- **WebSocket server** — notify_push owns the WebSocket layer; OR only emits events to `IQueue`.
- **Mobile / desktop push notifications** — notify_push can deliver to NC desktop/mobile clients; OR does not control that path.

## Dependencies

- Runtime-optional: `notify_push` Nextcloud app. OR soft-fails when absent.
- Does NOT modify the existing GraphQL subscription stack.
- Does NOT depend on the in-flight `pluggable-integration-registry` change (the `pushEvents()` extension is descoped to a follow-up).

## Risks

- **Fan-out cost at scale** — emitting one push per authorised user is fine for small orgs (the target deployment profile). Installations with thousands of readers per object would need a broadcast-channel approach. Document the ceiling explicitly in design.md; defer the optimisation.
- **`IQueue` injection failure mode** — if notify_push is installed but `IQueue` is not resolvable (partial install), soft-fail must not log at error level on every object save. At most one DEBUG entry per request.
- **PermissionHandler API surface** — if `PermissionHandler` does not expose a "readers of object X" method today, this change adds one. The naive fallback (iterate all NC users, per-user check) would be unacceptably slow on instances with many users; the implementation must use a query-based approach, mirroring how `GraphQLSubscriptionListener` already performs RBAC filtering.
