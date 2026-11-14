# Tasks: notifications-new-notes-and-referrers

## 1. Note trigger

- [x] 1.1 `ObjectNoteAddedEvent` dispatched from `NoteService::createNoteAs()` after save. Verify: `NoteServiceTest` asserts one event with note id, actor and visibility. Built in `NoteService::postNote()` (what `createNoteAs()` became; `createNote()` and link-authored notes both go through it), verified by `tests/Unit/Service/NoteServiceNoteAddedEventTest.php`.
- [x] 1.2 `noteAdded` in the validator with the optional `visibility` key; the listener branch deferring to the dispatch job. Verify: `NotificationAnnotationValidatorTest` for valid, bad key and reserved app event; listener test.
      > 2026-11-14: `noteAdded` is a reserved trigger type; its object form is checked by `NoteAddedTriggerValidator` (only `visibility`, `public` or `internal`; any other key refused by name). `AnnotationNotificationListener` handles `ObjectNoteAddedEvent`: finds the object by uuid through the deferred-entry resolver, keeps the schema gate, and defers `noteAdded` with the note id, actor and visibility; `AnnotationNotificationDispatchJob` dispatches it. Tests: `NotificationNoteAddedTriggerValidatorTest`, `AnnotationNotificationListenerNoteAddedTest`, `AnnotationNotificationDispatchJobTest::testNoteAddedEntryForwardsTheNoteContext`.
- [x] 1.3 Dispatcher: matching, `note.author` and `note.excerpt`, author exclusion and the read filter. Verify: `AnnotationNotificationDispatcherTest` with a watcher, the author and a user without read.
      > 2026-11-14: `NotificationTriggerMatcher` applies `trigger.visibility` (a declared visibility must equal the note's). `AnnotationNotificationDispatcher` adds `note.author` (user display name, link label, else actor id) and `note.excerpt` (plain text, at most 140 characters) through `NoteTriggerContext`, renders under the canonical subject `object_note_added`, drops the note's author and passes every recipient through `ReadableRecipients` (read on the object, as that user; fails closed, so no read check means nobody). A note reaches accounts only: parties and referenced addresses cannot be read-checked and are not addressed for `noteAdded`. Tests: `AnnotationNotificationDispatcherNoteAddedTest` (reader, author, user without read, visibility, no read check, other triggers unchanged), `NoteTriggerContextTest`, `ReadableRecipientsTest`.

## 2. Referrers kind

- [x] 2.1 `referrers` in the validator with `of`, `register`, `schema`, `property` and a nested block that may not contain `referrers`. Verify: validator tests naming each refused field.
      > 2026-11-14: `referrers` is a recipient kind, checked by `ReferrersRecipientValidator`: `register`, `schema` and `property` must be non-empty (`notification-recipient-referrers-missing`, naming the key), `of` when present must be a property of the triggering schema (`-bad-of`), `recipients` must be a non-empty list (`-no-recipients`) with no `referrers` inside (`-nested`, naming `recipients[i]`), and each nested entry passes the usual checks (a nested `field` names a property of the referring schema, so only its presence is checked). Whether the named register, schema and property exist is not checked at save: the validator has no schema lookup; a missing one resolves nobody at dispatch and is recorded there. Tests: `tests/Unit/Notifications/Service/Notification/ReferrersRecipientValidatorTest.php`.
- [x] 2.2 Resolution through `findByRelationBatchInSchema()` with the 10 and 200 caps and the `referrers-truncated` diagnostic. Verify: a new `tests/Unit/Service/Notification/NotificationRecipientResolverReferrersTest.php` for direct referrers, `of`, the cap, a nested `object-acl` and a reached user without read on the triggering object.
      > 2026-11-14: `NotificationRecipientResolver` resolves `referrers` through `ReferrerRecipients`: the register and schema are found by slug or id, the referred uuids are the triggering object or at most 10 values of its `of` property, each is looked up with `findByRelationBatchInSchema()` and kept only when the named property holds it, at most 200 referring objects are used and more are recorded as an unresolved `referrers-truncated` entry with the count, the nested block resolves against each referring object, and every reached uid must pass read on the triggering object (`ReadableRecipients`). An unknown register or schema, or a missing lookup service, reaches nobody and is recorded. Tests: `tests/Unit/Service/Notification/NotificationRecipientResolverReferrersTest.php` (7).

## 3. Tests and docs

- [ ] 3.1 Add `tests/e2e/ci/notify-new-note.spec.ts`: declare a `noteAdded` rule to watchers, watch a task as a second user, add a note as a third user, and assert the second user's notification and none for the author; then a `referrers` rule over a usage schema and a new version object.
- [ ] 3.2 Document the trigger and the kind in the notification docs under `docs/features/`, with the stackiq and planninq rules as examples.

Acceptance:

- A note never notifies its own author or anyone who cannot read the object.
- A `referrers` rule that reaches its cap says so in the rule's reach record.
