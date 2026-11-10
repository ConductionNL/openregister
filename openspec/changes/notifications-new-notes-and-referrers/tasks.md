# Tasks: notifications-new-notes-and-referrers

## 1. Note trigger

- [ ] 1.1 `ObjectNoteAddedEvent` dispatched from `NoteService::createNoteAs()` after save. Verify: `NoteServiceTest` asserts one event with note id, actor and visibility.
- [ ] 1.2 `noteAdded` in the validator with the optional `visibility` key; the listener branch deferring to the dispatch job. Verify: `NotificationAnnotationValidatorTest` for valid, bad key and reserved app event; listener test.
- [ ] 1.3 Dispatcher: matching, `note.author` and `note.excerpt`, author exclusion and the read filter. Verify: `AnnotationNotificationDispatcherTest` with a watcher, the author and a user without read.

## 2. Referrers kind

- [ ] 2.1 `referrers` in the validator with `of`, `register`, `schema`, `property` and a nested block that may not contain `referrers`. Verify: validator tests naming each refused field.
- [ ] 2.2 Resolution through `findByRelationBatchInSchema()` with the 10 and 200 caps and the `referrers-truncated` diagnostic. Verify: a new `tests/Unit/Service/Notification/NotificationRecipientResolverReferrersTest.php` for direct referrers, `of`, the cap, a nested `object-acl` and a reached user without read on the triggering object.

## 3. Tests and docs

- [ ] 3.1 Add `tests/e2e/ci/notify-new-note.spec.ts`: declare a `noteAdded` rule to watchers, watch a task as a second user, add a note as a third user, and assert the second user's notification and none for the author; then a `referrers` rule over a usage schema and a new version object.
- [ ] 3.2 Document the trigger and the kind in the notification docs under `docs/features/`, with the stackiq and planninq rules as examples.

Acceptance:

- A note never notifies its own author or anyone who cannot read the object.
- A `referrers` rule that reaches its cap says so in the rule's reach record.
