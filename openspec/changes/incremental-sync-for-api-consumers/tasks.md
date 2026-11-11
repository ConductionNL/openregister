# Tasks: incremental-sync-for-api-consumers

## 1. Deletion moves updated (REQ-SYNC-001, D-1)

- [ ] 1.1 `lib/Service/Object/DeleteObject.php`: set `updated` to the deletion time next to `setDeleted($deletionData)`, using the same `DateTime` as `deletedAt`.
- [ ] 1.2 The restore path behind `DeletedController::restore` and `restoreMultiple`: set `updated` when `deleted` is cleared.
- [ ] 1.3 Unit tests: after a soft delete `getUpdated()` equals `deleted.deletedAt`; after a restore it is the restore time.
- [ ] 1.4 Integration test: object list with `@self[updated][gte]` and `_includeDeleted=true` returns an object deleted after the cut-off and not one deleted before it.

## 2. Filters on the deleted list (REQ-SYNC-002, D-2)

- [ ] 2.1 `DeletedController::index`: read `register`, `schema`, `deletedSince`; resolve register and schema by id or slug (404 when unknown); parse `deletedSince` as ISO 8601 (400 naming it when invalid).
- [ ] 2.2 `ObjectEntityMapper::findDeletedAcrossAllMagicTables` and `countDeletedAcrossAllMagicTables`: accept the three filters; narrow the magic table list before scanning; apply `deletedSince` to `deleted.deletedAt` in SQL.
- [ ] 2.3 Unit tests for each refusal and for `total` matching the filtered rows.
- [ ] 2.4 Newman: add the filtered deleted-list request to the existing collection.

## 3. Tombstones for destroyed objects (REQ-SYNC-003, D-3)

- [ ] 3.1 `lib/Db/AuditTrailMapper.php`: read destruction entries (`DestructionScope::DESTRUCTION_ACTION`) by register, schema and created-since, paginated.
- [ ] 3.2 `DeletedController::index`: with `_includeDestroyed=true` and `deletedSince`, append tombstones `{id, register, schema, destroyedAt, tombstone: true}` passed through `readableDestructionRecords`; refuse `_includeDestroyed` without `deletedSince` with 400.
- [ ] 3.3 Add `destroyedCoverageFrom` (oldest destruction entry held) to the envelope.
- [ ] 3.4 Tests: a tombstone carries no object data; a tombstone outside the caller's scope is withheld; the refusal.

## 4. Watermark (REQ-SYNC-004, D-4)

- [ ] 4.1 `ObjectsController` list and `DeletedController::index`: take the server time before the query and return it as `syncWatermark`.
- [ ] 4.2 Test: the watermark is earlier than or equal to the `updated` of an object written during the read (simulate with a mapper stub that writes mid-query).

## 5. Docs and links

- [ ] 5.1 Document the sync recipe (changed-since read, deleted list, tombstones, watermark) in the API docs under `docs/`.
- [ ] 5.2 Set `built.state` of row `int-incremental-sync` in `openspec/parity/capabilities.json` once shipped; tell planninq so its row can follow.
- [ ] 5.3 `@spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md` on every touched method.
