---
kind: code
depends_on: [object-watchers]
---

# Proposal: notifications-new-notes-and-referrers

## Summary

Two additions to the notification engine. First, a schema can declare a rule
that fires when someone adds a note to one of its objects, so the people who
follow a task, and anyone else the rule names, hear about a new comment. The
author is never told about their own note, and nobody who cannot read the
object is told. Second, a rule can address the people behind the objects that
refer to the triggering object: when a supplier publishes a new version of an
application, the organisations whose usage records point at that application
are told, not only the version's own managers.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| planninq | col-notify-comment | Get notified of new comments on your tasks. | no |
| stackiq | life-new-version-notice | Get notified when a supplier publishes a new version of an application you use. | no |

Both are rows in sibling matrices (planninq's and stackiq's), owned here
because `built.owner` is ConductionNL/openregister: planninq's comments are
Open Register notes, and stackiq's notice is an `x-openregister-notifications`
rule the Open Register engine dispatches. stackiq's row was marked `specified`
with no change directory; this is the change.

Demand rows: none recorded in the packet for either row.

Competitor yes cells for planninq col-notify-comment, quoted from the packet:

- Nextcloud Deck 1.18: "source read at v1.19.0: lib/Listeners/CommentEventListener.php:41-44
  and :56-59 a new comment triggers the card_comment_create activity;
  lib/Activity/SettingComment.php:28 'A comment was created on a card' setting
  delivered by the platform stream or mail; direct notification only for
  mentions, lib/Notification/NotificationHelper.php:178". Source path as cited, no URL.
- OpenProject 16 Community: "source read at v17.8.0: app/models/notification.rb:37
  reason 'commented' and :33 'mentioned'; app/models/notification_setting.rb:41
  WORK_PACKAGE_COMMENTED". Source path as cited, no URL.
- Plane Community 1.4: "source read at v1.4.2: .../notifications/email-notification-form.tsx:131
  'comment' preference; apps/api/plane/bgtasks/notification_task.py:133-150
  comment mentions and :280-311 subscribers notified". Source path as cited, no URL.
- Kanboard 1.2: "source read at v1.2.54: app/Subscriber/NotificationSubscriber.php:31
  CommentModel::EVENT_CREATE handled; app/Template/notification/comment_create.php:3
  mail body; app/Template/user_view/notifications.php:13 limit to tasks
  assigned to or created by me". Source path as cited, no URL.
- Jira Software Data Center 11: "batched updates include 'Changes to any of
  the issue fields ... Comments Work logs Attachments Mentions' (read
  2026-09-26)". Evidence:
  https://confluence.atlassian.com/adminjiraserver/configuring-email-notifications-938847633.html

Competitor yes cells for stackiq life-new-version-notice, quoted from the packet:

- GEMMA Softwarecatalogus: "\"Wanneer een leverancier een pakketversie
  registreert kan deze een suggestie versturen naar de gemeenten en
  samenwerkingen die dit pakket afnemen\" (read 2026-09-26);
  https://www.softwarecatalogus.nl/node/16564: C2: \"Via de notificatiefunctie
  krijgt u een signaal zodra het versienummer is toegevoegd\"". Evidence:
  https://www.softwarecatalogus.nl/suggesties_overnemen and
  https://www.softwarecatalogus.nl/node/16564

## Why

Notes exist and are silent:

- `NoteService::createNoteAs()` creates a Nextcloud comment, sets message,
  verb and visibility, saves, and returns (`lib/Service/NoteService.php:329-352`).
  It dispatches no event. The only notification a note can raise today is a
  mention (`lib/Service/Timeline/EntryMentionService.php:62`, subject
  `timeline_mention`), which reaches the person named, not the people who
  follow the object.
- A rule's trigger must be one of `created`, `updated`, `transition`,
  `scheduled`, `threshold`, `calculatedChange`, or an app event
  (`lib/Service/Notification/NotificationAnnotationValidator.php:50`,
  `:276-287`). There is no trigger for a note.
- The listener that feeds the dispatcher handles object created, updated and
  transitioned events only (`lib/Listener/AnnotationNotificationListener.php:93-131`).
- The `watchers` recipient kind already exists
  (`lib/Service/Notification/NotificationRecipientResolver.php:163-172`, `:397`),
  so the people who follow a task are addressable; nothing fires for a note.

The relation kind looks only one way:

- `relation` reads the named field of the triggering object and keeps the uids
  it finds there (`NotificationRecipientResolver.php:204-221`). It cannot look
  at objects that point at the triggering object.
- stackiq's rule `module-version-published` on `moduleVersion` addresses
  `object-acl manage` and the group `software-catalog-admins`
  (stackiq `lib/Settings/softwarecatalogus_register.json`), so the
  organisations whose `usage` records point at the module are never told.
- Open Register can already find referring objects in one schema with one query
  over the `_relations` index (`lib/Db/MagicMapper.php:8424`,
  `findByRelationBatchInSchema()`).

## What changes

- A new trigger `noteAdded`: a rule fires when a note is added to an object of
  the schema, optionally only for `public` or `internal` notes.
- `NoteService` dispatches a new `ObjectNoteAddedEvent` after a note is saved;
  the notification listener hands it to the dispatcher asynchronously like the
  other triggers.
- The note's author is removed from the recipients, and so is anyone who cannot
  read the object. The same read check applies to everyone a `referrers`
  recipient reaches.
- Templates can use `{{note.author}}` and `{{note.excerpt}}` (first 140
  characters, plain text).
- A new recipient kind `referrers`: from the triggering object (or from an
  object it points at, through `of`), find the objects in a named schema whose
  named property refers to it, and resolve a nested recipient block against
  each of them. Capped, and one level deep.

## Consumers

- planninq (col-notify-comment): a `noteAdded` rule on its task schema
  addressing `watchers`, the assignee field and the task's creator. The rule is
  planninq's register configuration.
- stackiq (life-new-version-notice): its `module-version-published` rule adds a
  `referrers` recipient over `usage.module` with `of: module`. The rule is
  stackiq's register configuration.
- dossiq and decidiq can declare the same trigger on their case and decision
  schemas.

## ADRs

- hydra ADR-031 (schema-declarative business logic): both additions are
  declared in `x-openregister-notifications`; see the design.
- hydra ADR-005 (security): no recipient who cannot read the object; resolved
  uids are checked to exist, as every kind does.
- hydra ADR-058 (bounded queries): the referrer lookup is capped.
- hydra ADR-078: dispatch stays asynchronous to the note save.
- openregister ADR-002 (organisation tenancy): the lookup crosses organisations
  on purpose (a supplier's version, a municipality's usage), so every uid it
  reaches must be able to read the triggering object before it is told.

## Impact

- Extends `notificatie-engine`.
- Affected code: `NotificationAnnotationValidator` (trigger and kind),
  `NotificationRecipientResolver` (the `referrers` kind),
  `AnnotationNotificationDispatcher` (the trigger, author exclusion, read
  filter, placeholders), `AnnotationNotificationListener`, `NoteService`, a new
  `lib/Event/ObjectNoteAddedEvent.php`.
- Backwards compatible: a new trigger and a new kind; existing rules are
  unchanged.
- Size: M.

## Out of scope

- A per-user preference "notify me of comments". User preferences exist in the
  engine; this change adds what a preference would switch.
- Notes edited or deleted. Only a new note fires.
- Referrers more than one level away. A second hop is a query chain nobody can
  bound by reading the rule.
- The planninq and stackiq rules themselves, which are those apps' register
  JSON.
