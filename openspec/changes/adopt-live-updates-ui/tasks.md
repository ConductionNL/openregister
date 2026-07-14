# Tasks — adopt-live-updates-ui

## 1. Store wiring

- [x] 1.1 Add `liveUpdatesPlugin()` to the plugins array of the package object
  store in `src/store/modules/object.js` (explicit opt-in API of
  `@conduction/nextcloud-vue` 1.0.0-beta.206 — verified the locked version
  exports `liveUpdatesPlugin` and `useObjectSubscription`).
  - Acceptance: `objectStore.subscribe` / `objectStore.unsubscribe` exist;
    plugin is inert until the first subscribe.

## 2. Object list view (collection subscription)

- [x] 2.1 `src/views/object/ObjectsList.vue`: add
  `syncLiveSubscription()` / `releaseLiveSubscription()` managing a single
  collection subscription for `objectStore.currentType`; register the type
  (`registerObjectType`) when the list's own refresh has not done so yet.
- [x] 2.2 Re-scope on register/schema switch via a watcher on the store's
  `currentType`; subscribe on mount; unsubscribe in `beforeDestroy`.
- [x] 2.3 Guard the async race: a subscription resolving after the scope
  changed (or after unmount) is immediately unsubscribed instead of leaked.

## 3. Object detail view (object subscription)

- [x] 3.1 `src/views/object/ObjectDetails.vue`: subscribe to
  `or-object-{uuid}` for the opened object (uuid preferred over numeric id),
  keyed `${type}::${uuid}` so re-renders of the same object are no-ops.
- [x] 3.2 Bridge event-driven refetches into the view: `$watch` on
  `objectStore.getObject(type, uuid)` calls `setObjectItem(fresh)` so the
  Options-API template (which renders `objectStore.objectItem`) updates.
- [x] 3.3 Re-scope when another object is opened (`updated()` hook alongside
  the existing sub-resource reloads); release subscription + watcher in
  `beforeDestroy`; same async-race guard as the list view.

## 4. Out of scope / deferred

- [x] 4.1 Dashboard widgets deliberately NOT wired (separate dashboard store
  module; not a trivial integration) — documented in proposal.md.
- [x] 4.2 No dependency bump: locked `@conduction/nextcloud-vue`
  1.0.0-beta.206 already ships the live-updates layer.

## 5. Verification

- [x] 5.1 `npm run lint` passes on the touched files.
- [x] 5.2 `npm test` (jest) passes.
- [x] 5.3 `npm run build` (webpack production) succeeds.
- [ ] 5.4 Live two-session verification on a notify_push-enabled instance
  (deferred — requires a deployed instance; not part of this change's CI).
