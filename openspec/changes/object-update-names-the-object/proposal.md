---
kind: code
depends_on: [activity-provider, notificatie-engine]
---

# Proposal: object-update-names-the-object

## Summary

A user who edits an object in an app built on OpenRegister (pipelinq first)
gets a popup and an activity that say "Register "Pipelinq CRM Register" was
updated", not what they changed. This change stops a register save that
changed nothing from firing `RegisterUpdatedEvent`, and names the schema and
the object in the object activity ("Client Gemeente Demo updated") and in the
canonical object notification.

## Why

Reported on pipelinq beta (review item E1, 2026-10-06). Traced on a local
instance:

- A plain `PATCH` of an object writes one `object_updated` activity and no
  register activity. The object save path does not write the register: the
  register folder id goes through `RegisterFolderRecorder`, not
  `RegisterMapper::update()`.
- What the user sees comes from `RegisterMapper::update()` being called on an
  unchanged register. Every `ConfigurationService::importFromApp()` run does
  that (pipelinq calls it from the setup wizard's provision step, from the
  example data load and from its settings load). Each run dispatched
  `RegisterUpdatedEvent`, which wrote two `register_updated` activities and a
  `register-changed` notification to every admin, shown as a popup. All seven
  `register-changed` notifications on the test instance carried `_oldData`
  equal to `_newData`.
- The object activity says "Object updated: {title}" and the canonical
  notification template says `in register "20"`, because nothing fills
  `registerName`.

## What changes

1. `RegisterMapper::update()` compares the stored register before and after
   the write and dispatches `RegisterUpdatedEvent` only when something other
   than the `updated` timestamp changed. Activity, notifications, webhooks
   and the authorization cache listener all stop reacting to a save that
   changed nothing.
2. Object activities carry the schema title. The provider renders
   `{schema} {title} created|updated|deleted` when it is known, and the old
   `Object updated: {title}` when it is not (older rows, unknown schema).
3. The canonical object notification drops the `in register "%2$s"` clause
   when no register name is known, instead of printing the register id.

Schema saves are deliberately left alone: `SchemaUpdatedEvent` drives
installers (flows, approval chains, notification annotations) that may depend
on firing again on every import.

## Impact

- `lib/Db/RegisterMapper.php`, `lib/Service/ActivityService.php`,
  `lib/Activity/ProviderSubjectHandler.php`, `lib/Notification/AnnotationNotifier.php`
- `l10n/en.*`, `l10n/nl.*` for the new subjects.
- No API or schema change. A webhook subscribed to `RegisterUpdatedEvent` no
  longer fires for a save that changed nothing.
