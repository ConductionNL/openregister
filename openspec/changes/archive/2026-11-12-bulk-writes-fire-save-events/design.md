# Design: bulk-writes-fire-save-events

## D-1: one place for every bulk caller

`POST /api/bulk/{register}/{schema}/save`, the CSV and Excel import
(`ImportService`), the import preview (`ImportPreviewService`) and the flow
`ObjectWriteNode` all call `ObjectService::saveObjects()`, which hands the
batch to `SaveObjects::saveObjects()`. That method already screens every row
before anything is written: draft rule, appendOnly, permission, property
authorization, validation (`screenResolvedRow()`). The pre-save event is the
last step of that screen. So one change covers every caller, and a caller
added later is covered without knowing about it.

The screen already knows what the event needs: the row's schema, its
register, its uuid and the stored object for an update (prefetched for the
whole batch in one query per table).

## D-2: the setting and its order

`fireEventsOnBulkWrites` is a boolean, read in this order, first explicit
value wins:

1. the schema's `configuration.fireEventsOnBulkWrites`
2. the register's `configuration.fireEventsOnBulkWrites`
3. the app config value `fire_events_on_bulk_writes`, `true` or `false`

`occ config:app:set openregister fire_events_on_bulk_writes --value=true`
switches it on for the instance. A value that is not a boolean (or, for the
app config, not the text `true` or `false`) is not a decision and falls
through to the next level.

The schema is the most specific level, so it wins: an app that ships a
guarded schema can switch it on there, whatever the instance says. The
register level covers an app that wants every schema of its register guarded.
The instance level is for an administrator.

`BulkWriteEvents` holds the order. `Schema::validateConfigurationArray()`
lists the key among its booleans, because a key off that list is dropped on
save.

## D-3: the default

With no explicit value anywhere, a schema that declares
`x-openregister-lifecycle` fires the events, and any other schema does not.

- Firing for every schema would slow every existing import for no gain. The
  measurement below shows the dispatch alone roughly doubles the in-process
  cost per row before a single listener runs, and OpenRegister itself
  registers 16 listeners on `ObjectCreatingEvent` and 17 on
  `ObjectUpdatingEvent`.
- Firing for no schema keeps a known hole open. A schema that declares a
  lifecycle has declared a guard: `LifecycleValidationListener` refuses a
  transition the lifecycle does not allow. Its docblock says the guarantee
  only holds when every write runs it. On development a bulk write skips it.
- An app guard such as dossiq's deliberation check is not visible in the
  schema. Those apps switch the setting on for their schema.

An administrator who wants today's behaviour everywhere sets the instance
value to `false`. A schema that sets `false` keeps today's behaviour even
with a lifecycle.

## D-4: what a row's event looks like

- A row that updates gets `ObjectUpdatingEvent`, with a copy of the stored
  object carrying the row's data as the new object, and the stored object as
  the old one. The copy keeps the stored id, owner and metadata, as the
  single save does.
- A row that creates gets `ObjectCreatingEvent`. A row without an identity
  gets its uuid here, so the event and the written row name the same object.
- A stopped event refuses the row into `invalid` with the hook's
  `errors.message` (or "Object creation/update rejected by hook") and the
  row's position in the batch. The rest of the batch goes on.
- What a listener changes on the event's object, and what it passes to
  `setModifiedData()`, is written. That matches `MagicMapper::insertObjectEntity()`
  and `updateObjectEntity()`.

## D-5: post-save events

`processObjectsChunk()` already dispatches the created and updated events
when the caller passes `_events: true`. When the caller does not, the chunk
now works out which register and schema pairs have the setting on, and
dispatches for those objects only, through the same `emitChunkSideEffects()`
(with audit off, because the first call already wrote it). The pre-update
rows are fetched when such a pair exists, so an updated event carries the
real old object. `SystemOperationContext` still withholds these events.

## D-6: the row is screened where it is written

`MagicMapper::ultraFastBulkSave()` writes every row of a call that names a
register into that register's table, and with a named schema into that
schema's table. The row screen used to read `@self.register` and
`@self.schema` first. A row naming another register was then looked up in the
wrong table, judged a create, and checked against the other register's
permissions and setting, while the writer overwrote the stored row in the
route's table. The screen now takes the caller's register and schema first,
like the writer. `@self` decides only in mixed-schema mode.

Refusing such a row was the other option. It was not taken: exported data
carries `@self.register` and `@self.schema` from its source instance, and a
re-import into another register would then be refused row by row. Screening
against the target keeps those imports working and judges the table that is
actually written.

## D-7: known limits

- The pre-save events run in the row screen, before the chunk's own guards
  (`enforceChunkGuards()`) and before the chunk's database transaction. A
  listener's side effect (a number issued, a refusal recorded) stays when a
  later guard drops the row or the chunk rolls back. The single save has the
  same order for its own checks after the event.
- In mixed-schema mode, and in a call that names a schema but no register, the
  writer can refuse the whole chunk and throw after the rows' pre-save events
  have fired. The write fails closed (nothing is stored), but the listeners
  have already run.

The post-save step keys its targets on the same table as the screen: the
caller's register and schema first, the row's own only where the caller
named none. The stored objects carry that table's register and schema, so a
row whose `@self.register` names another register still gets its created or
updated event.

## D-8: what is left out

- `appendObjectsRaw()` writes through `MagicMapper::bulkUpsert()` and stays
  raw. Its caller takes the authorisation responsibility by contract.
- `MagicBulkHandler::bulkUpsert()` keeps its commented-out dispatch. The
  events run one level up, where the row is still a row and can be refused.
- The insert-race fallback in `MagicMapper::saveObjectToRegisterSchemaTable()`
  (a create that finds its uuid taken and updates instead) still skips
  `ObjectUpdatingEvent`. It is a single-save concurrency path and gets its own
  change: `insertObjectEntity()` asks with `failIfExists: true`, and on
  `ObjectExistsException` routes to `updateObjectEntity()`, which runs the
  guard.
- A caller's `_events: true` keeps meaning "post-save events". Pre-save events
  follow the setting only.

## Performance

Measured on the unit harness (mocked writer, no database, a dispatcher with
no listeners), creating N rows into one schema, median of three runs on a
loaded machine (load around 70):

| N | events off | events on | extra per row |
|---|---|---|---|
| 1,000 | 358 ms | 725 ms | 0.37 ms |
| 5,000 | 4,490 ms | 7,715 ms | 0.64 ms |

With events on, every row dispatched two events (one before, one after the
write). The numbers measure the mechanism only. On a real instance the cost
per row is the dispatch plus whatever the registered listeners do, and the
database write itself is unchanged. That is why the default stays off for a
schema without a lifecycle.
