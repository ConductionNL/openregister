## ADDED Requirements

### Requirement: OpenRegister's own UI MUST subscribe to live update events for the views it renders

The OpenRegister frontend MUST consume the live-update events the backend
emits, using the shared `@conduction/nextcloud-vue` live-updates layer
(`liveUpdatesPlugin` on the app's package object store). Events MUST be
treated as refetch hints only — the UI MUST re-fetch through the existing
store fetch actions and MUST NOT apply event payloads as data.

- The object LIST view MUST hold a subscription to the collection event
  (`or-collection-{register-slug}-{schema-slug}`) for the currently scoped
  register+schema, re-scope it when the user switches register/schema, and
  release it on unmount.
- The object DETAIL view MUST hold a subscription to the object event
  (`or-object-{uuid}`) for the opened object, re-scope it when another object
  is opened, and release it on unmount.
- Transport selection (notify_push websocket vs visibility-gated polling
  fallback) is owned by the shared library; the app MUST NOT implement its own
  transport.
- A failed subscription attempt MUST NOT break the view — it degrades to the
  pre-existing manual-refresh behaviour.

#### Scenario: Object list refreshes when another session changes the collection
- **WHEN** the object list for register `zaken` / schema `meldingen` is open and another session creates, updates, or deletes a `meldingen` object
- **THEN** the client receives the `or-collection-zaken-meldingen` event and re-runs `fetchCollection` with the last-used params, so the rendered list reflects the change without a manual refresh

#### Scenario: Object detail refreshes when the open object changes
- **WHEN** the detail view for object `uuid-123` is open and another session updates that object
- **THEN** the client receives the `or-object-uuid-123` event, re-runs `fetchObject`, and the detail view re-renders with the fresh object data

#### Scenario: Subscriptions are released on scope change
- **WHEN** the user switches from schema `meldingen` to schema `vergunningen`, or opens a different object in the detail view
- **THEN** the previous subscription is unsubscribed before (or immediately after) the new one is established, so no stale subscription keeps refetching the old scope

#### Scenario: Subscription failure degrades gracefully
- **WHEN** a subscribe attempt fails (e.g. slugs cannot be resolved)
- **THEN** the view renders and behaves exactly as before this change (manual refresh only) and logs a console warning

@e2e exclude Live-updates transport requires a notify_push-enabled instance and a second mutating session; covered by nc-vue unit tests on the shared plugin and manual verification.
