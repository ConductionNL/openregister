## Context

See proposal.md for the why. A file listing reaches `FolderManagementHandler::getObjectFolder()`. For an object with no folder, or a legacy path, it calls `createObjectFolderById()`. That method makes the register folder, then `Open Registers/<title> Register/<uuid>`, and stores the node id on the object.

It stored the id with `MagicMapper::update()`. That method resolves register and schema, reads the old row, and calls `updateObjectEntity()`. `updateObjectEntity()` dispatches `ObjectUpdatingEvent` before the write, and `update()` dispatches `ObjectUpdatedEvent` after it. Only a `SystemOperationContext` scope withholds the second one.

## Goals and non-goals

**Goals:**
- A request that only reads an object's files never runs save-time logic on that object.
- The folder id write cannot repoint a folder another request recorded first.

**Non-goals:**
- Changing where object folders live or who owns them.
- Changing what a real save does with a folder id (`SaveObject`).

## Decisions

### A narrow write on MagicMapper, not a new recorder class

`MagicMapper::recordFolder(entity, expected, folderId)` runs one statement on the object's own table:

```
UPDATE openregister_table_<register>_<schema> SET _folder = :folderId
WHERE _uuid = :uuid AND (_folder IS NULL OR _folder = :expected)
```

It lives on MagicMapper because MagicMapper owns the table name for a register and schema. `restoreObject()` already writes one column of that table the same way. A separate class would have to repeat the table lookup.

### No event, not a suppressed event

The write does not go through `update()` at all. Wrapping `update()` in `SystemOperationContext::run()` would still dispatch `ObjectUpdatingEvent`, which is not gated. It would also mark a reader's request as a system operation, which RBAC trusts.

### No audit row

Register folder ids are recorded without an audit row (REQ-RFFU-002). The object path never wrote one either: `MagicMapper::update()` leaves auditing to the save service. A folder id is the system's own bookkeeping, not a change anyone made to the object.

### A lost race still returns the folder

When the compare-and-set matches no row, another request recorded a folder first. Both requests made or found the same folder, named after the object's uuid. The handler logs it at debug level and returns the folder, as the register side does.

## Risks

- An object without a uuid, or whose register or schema cannot be resolved, throws. `MagicMapper::update()` threw for the same objects before, so the behaviour is unchanged.
