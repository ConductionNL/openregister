# Design: notifications-new-notes-and-referrers

Read at openregister development c53dd0685c.

## D-1: a note raises an event

`NoteService::createNoteAs()` (`lib/Service/NoteService.php:329-352`) dispatches
a new `OCA\OpenRegister\Event\ObjectNoteAddedEvent` after
`commentsManager->save()` returns, carrying the object uuid, the note id, the
actor type and id, and the normalised visibility. Both `createNote()` (`:290`)
and link-authored notes go through `createNoteAs()`, so a note left through an
access link fires too, with an actor type that is not `users`.

`AnnotationNotificationListener` (`lib/Listener/AnnotationNotificationListener.php:93-131`)
gains a branch for the event. Like the other triggers it keeps the inline
schema gate (a schema without `x-openregister-notifications` enqueues nothing)
and defers the dispatch to `AnnotationNotificationDispatchJob` under the
captured actor.

## D-2: the `noteAdded` trigger

`NotificationAnnotationValidator::VALID_TRIGGERS`
(`lib/Service/Notification/NotificationAnnotationValidator.php:50`) gains
`noteAdded`. Its trigger object accepts one optional key, `visibility`
(`public` or `internal`); anything else is refused naming the key, as the
validator does for other triggers (`:276-287`). Because the value moves from
app-event to reserved, an app event literally named `noteAdded` would now be
refused as reserved (`:154-160`); no fleet register declares one.

The dispatcher (`AnnotationNotificationDispatcher::dispatch()`, `:344`) treats
`noteAdded` like `created` for matching and adds to the context:

- `note.author`: the display name of a user author, or the link's label for a
  link author;
- `note.excerpt`: the first 140 characters of the message as plain text. A
  template that does not use it does not carry it.

Two filters run after recipients are resolved and before delivery:

1. the author (actor type `users`, actor id) is removed;
2. every remaining uid must pass read on the object through
   `PermissionHandler::hasPermission(schema, 'read', userId: uid, object)`
   (`lib/Service/Object/PermissionHandler.php:414`). A watcher who lost access
   stops hearing about notes, and a rule cannot be used to push note text to
   someone who may not see the object.

The canonical subject key for the new trigger is added beside `created` and
`transition` (`AnnotationNotificationDispatcher.php:2609-2620`).

## D-3: the `referrers` recipient kind

`NotificationAnnotationValidator::VALID_RECIPIENT_KINDS` (`:52`) gains
`referrers`, with this shape:

```json
{
  "kind": "referrers",
  "of": "module",
  "register": "softwarecatalogus",
  "schema": "usage",
  "property": "module",
  "recipients": [{ "kind": "object-acl", "permission": "read" }]
}
```

- `of` is optional. Absent, the referred object is the triggering object.
  Present, it names a relation property on the triggering object and the
  referred objects are the ones it points at (at most 10).
- `register`, `schema` and `property` name where the referring objects live and
  which of their properties points back. The validator refuses a schema or
  property that does not exist, naming it.
- `recipients` is a nested block of any existing kind except `referrers`, so
  the kind is one level deep by construction.

`NotificationRecipientResolver::resolveWithDiagnostics()`
(`lib/Service/Notification/NotificationRecipientResolver.php:143`) resolves it
by reading referring objects with `MagicMapper::findByRelationBatchInSchema()`
(`lib/Db/MagicMapper.php:8424`), filtering to those whose named property holds
the referred uuid, at most 200 referring objects, and resolving the nested
block against each (`object-acl`, `field`, `relation`, `watchers`, `users`,
`groups`, `role`, `expression`). Past the cap the rule records an unresolved
entry `referrers-truncated` with the count, the way the resolver already
reports a rule that reaches nobody, so a rule that silently stops at 200 is
visible. Every uid the kind reaches then passes the same read check on the
triggering object as D-2 applies to a note.

## Declarative-vs-imperative decision

Declarative, in `x-openregister-notifications` (hydra ADR-031). Both are
notification rules: when X happens, tell these people. The trigger is one more
"when" in the existing dialect, and the kind is one more "who". Nothing here is
code a consuming app writes.

## Risks

- Security (hydra ADR-005): the read filter in D-2 applies to every `noteAdded`
  delivery and to every uid a `referrers` recipient reaches. The `referrers`
  lookup itself reads referring objects without the actor's RBAC and across
  organisations, because the rule is the schema author's declaration and the
  point is to reach people in other organisations (a municipality's usage of a
  supplier's module). What keeps that safe: the nested kinds resolve only real,
  existing uids; each must then pass read on the triggering object; and
  placeholders render from the triggering object only, so no referring object's
  content reaches a recipient.
- Noise: the author exclusion and the dispatcher's existing coalescing
  (`lib/Service/Notification/NotificationCoalescer.php`) apply, so a burst of
  notes on one task is one digest under the recipient's preferences.
- Performance (hydra ADR-058): one indexed `_relations` query per referred
  object, 200 referring objects and 10 referred objects at most.
