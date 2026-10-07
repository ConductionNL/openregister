---
kind: code
depends_on: [event-driven-architecture, object-update-names-the-object]
---

# Proposal: events-at-the-level-of-change

## Summary

An event fires at the level where something changed, and only when it changed.
Ruben, 2026-10-07: "CRUD on an object is an object event (+ notification), not
a schema or register event." Registers already follow this rule
(`object-update-names-the-object`). This change applies it to schemas, keeps
every import installing what a schema declares, pins the object path to object
events, and fixes a PATCH that wrapped a translatable title one level deeper on
every save.

## Why

- Every app import saved each schema twice (`updateFromArray()`, then
  `update()`), and each save fired `SchemaUpdatedEvent` whether or not anything
  changed. On the pipelinq test instance that is 1,325 `schema_updated`
  activities and a "Schema ... was updated" popup per schema for every admin.
- Two listeners rebuild stored state from schema annotations on that event:
  the flow importer (`x-openregister-flows`) and the notification webhook
  installer (`x-openregister-notifications`). Firing on every import is what
  brought back a shipped flow or webhook someone deleted, and what installed a
  declaration made before the importer existed. Silencing the event without a
  replacement would stop that.
- A plain PATCH of a pipelinq lead turned its title `[Demo] Webshop` into
  `{"nl":"[Demo] Webshop"}`, nesting again on the next PATCH. The read path
  decodes the stored locale map, and the PATCH merge re-encoded every untouched
  array on a `type: string` property, translatable ones included.

## What changes

1. `SchemaMapper::update()` dispatches `SchemaUpdatedEvent` only when the stored
   schema changed. The `updated` timestamp alone does not count. The comparison
   is shared with registers in `EntityChangeDetector` (was
   `RegisterChangeDetector`). The mapper counts the events it dispatched per
   schema id (`updateEventCount()`).
2. `ImportHandler::importSchema()` reads that count before and after its saves.
   When no event fired, it calls `SchemaImportInstaller`, which runs the flow
   importer and the webhook installer directly. When an event fired, the
   listeners already ran and nothing runs twice.
3. The other `SchemaUpdatedEvent` listeners need no replacement. They
   invalidate caches (authorization, grantable rights, source-record index),
   warn about approval chains, drop dedupe rows for removed rules, drain parked
   handoffs for a newly arrived provider, or publish activity, webhooks and
   notifications. An unchanged schema gives none of them anything to do.
   Outside OpenRegister, pipelinq's `SchemaChangeListener` only logs column
   changes.
4. An architecture test pins the object create/update/patch/delete/bulk/import
   path, and every listener of an object event, to object events only. The
   2026-10-07 audit found the path clean.
5. `SchemaTypeConverter::restoreStringTypedValues()` skips translatable
   properties, whose stored form is the locale map.

## Impact

- `lib/Db/SchemaMapper.php`, `lib/Db/EntityChangeDetector.php` (renamed),
  `lib/Db/RegisterMapper.php`, `lib/Service/Configuration/ImportHandler.php`,
  `lib/Service/Configuration/SchemaImportInstaller.php` (new),
  `lib/Listener/SchemaFlowImportListener.php` (`importFor()` public),
  `lib/AppInfo/Application.php`, `lib/Service/Object/SchemaTypeConverter.php`
- A webhook or app listening to `SchemaUpdatedEvent` no longer hears an
  identical re-import. A real change still fires, and the
  `schema-changed` admin notification still goes out for it.
- Not changed: the register folder id an object upload records on its register
  (`RegisterFolderRecorder`) is a direct column write without an event, as before.
