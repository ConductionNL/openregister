---
kind: code
depends_on: [events-at-the-level-of-change, openregister-system-notifications, wizard-dataset-card-load, deep-link-registry]
---

# Proposal: live-audit-round-one

## Summary

Four OpenRegister fixes from the live audit of 7 October 2026 on the pipelinq
review instance: admin notices only on a real change, the setup wizard stays
closed once closed, list headers that sort, and activity links that open the
owning app.

## Why

- **E1, notices.** An administrator's bell held 106 "updated" notices for
  schemas, registers and configurations. Read back from the notification
  payloads: most compared equal apart from the `updated` stamp, a
  configuration's `version` (the app build it came from), a `created` stamp,
  `null` against `[]`, or key and `required` order. Those are re-imports of
  identical content. #4433 already stops `SchemaUpdatedEvent` on an
  unchanged schema save; the configuration path and the notification bridge
  still fired on every import. Ruben: these notices only on a real change.
- **Setup wizard.** "Set up Open Register" opened on every page in any
  browser except the one it was closed in. The wizard records a close only
  in that browser's localStorage, and the server keeps reporting the example
  data step as unanswered until a dataset is picked.
- **G2, sorting.** The schemas list declared sortable headers that nothing
  handled, so a click changed nothing (aria-sort stayed null). The audit
  trail table offered no sort at all, although its API sorts.
- **E1, activity links.** An object activity linked to the OpenRegister
  admin view even when the owning app registered its detail route in the
  deep link registry (pipelinq does, from its manifest).

## What changes

- `SystemEntityChange` compares two serialised system entities without
  bookkeeping; `SystemEntityNotificationListener` sends no notice when they
  compare equal.
- `SetupController` gains a `dismiss-setup` action that marks the example
  data step answered without touching a pick or a load. The app posts it
  when the wizard is closed or finished (`src/services/wizardDismissal.js`).
- `SchemasIndex` sorts by the clicked header (`src/services/listSort.js`);
  `AuditTrailIndex` marks six headers sortable and asks the server to sort,
  keeping that sort while paging.
- `ActivityService` links an object activity through the deep link registry
  and falls back to the OpenRegister view.

## Out of scope

- Header filters on these tables (nextcloud-vue, another lane).
- The schema import that flips `owner`/`application` between a value and
  `null` on alternate saves, which is a real write and still notifies.
