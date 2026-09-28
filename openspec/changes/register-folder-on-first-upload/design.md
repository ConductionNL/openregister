## Context

See proposal.md for the why. A file upload reaches `FolderManagementHandler::getObjectFolder()`, which creates the object's folder inside the register's folder, creating that first through `createRegisterFolderById()`. That method finds or makes `Open Registers/<title> Register` through `createFolderPath()` in the files of `getUser()`: the session user, or the OpenRegister system user when there is no session. It then stores the folder's node id on the register with `RegisterMapper::update()`.

`RegisterMapper::update()` is the path for a person editing a register. It runs `verifyRbacPermission('update', 'register')`, which passes for an admin, the CLI, or a `SystemOperationContext` scope, and `verifyOrganisationAccess()`, which refuses any register whose organisation differs from the caller's active organisation and has no system bypass. It then cleans the entity (uuid, slug, version, source, authorization validation) and dispatches `RegisterUpdatedEvent`, which four listeners take: system notifications, the authorization cache, webhooks and the activity stream.

`consolidate-permission-handling` (open, proposal point 4) keeps `PHP_SAPI === 'cli'` and `SystemOperationContext::isActive()` as the only blanket bypasses and names openregister#2515's fix as folder initialisation, not wider trust. That rules out any answer that makes the permission model say yes to this caller.

The object's folder id is stored with `MagicMapper::update()`, which does no RBAC check; the E2E log in portaliq#29 shows only the register write failing.

## Goals / Non-Goals

**Goals:**
- A first upload succeeds for any caller whose upload is otherwise allowed, including a request with no session and a register in any organisation.
- The folder id write can never widen access: it cannot repoint an existing folder, and its value never comes from the request.
- Repeated and concurrent first uploads end with one recorded folder and no failed upload.

**Non-Goals:**
- Provisioning register folders eagerly at register creation or import (the issue's option 1). It would still need this path for every register that already exists without a folder, and for a folder deleted since; it is a candidate follow-up.
- Changing where folders live or who owns them. `createFolderPath()` and the ownership transfer are unchanged.
- The object folder write, which already works for a session-less request.

## Decisions

### Record the folder id with a conditional single-column write

`RegisterFolderRecorder::record(registerId, expected, folderId)` runs one statement:

```
UPDATE openregister_registers SET folder = :folderId
 WHERE id = :registerId AND (folder IS NULL OR folder = :expected)
```

`expected` is the value the handler read before making the folder (`null` or `''` for none, a stale id or legacy path when the stored folder no longer resolves). The statement returns whether it changed a row. Zero rows means another request recorded a folder first; the handler logs that at debug and carries on with the folder it has, which for a session-less request is the same node, found at the same path.

Why this is safe to run without the register permission and organisation checks:
- It writes one bookkeeping column, never a field a person edits, and never the register's organisation, owner or authorization.
- The value is the node id of the folder `createFolderPath()` just made or found at the register's conventional path, not anything from the request.
- The compare-and-set means it can only fill an empty or dead slot, never replace a folder another request recorded.
- It is scoped to the register the upload path already resolved; whether the caller may upload to that register's objects is decided before this code runs, exactly as today.

Alternatives considered:
- `SystemOperationContext::run()` around `RegisterMapper::update()` (the issue's option 2): passes the permission check, but `verifyOrganisationAccess()` still refuses a register outside the default organisation for a portal request, and the update event would keep reporting a register edit that nobody made. Rejected.
- A system bypass in `verifyOrganisationAccess()`: widens a shared tenant check for every mapper that uses the trait to fix one bookkeeping write, and would be the third blanket bypass `consolidate-permission-handling` forbids. Rejected.
- A service identity the portal assumes (openregister#2515's longer ask): the right home for authenticated-but-not-by-Nextcloud callers, and a change to the permission model of its own. This fix does not wait for it and does not pre-empt it.
- A method on `RegisterMapper`: the natural home, but the class is at 983 of phpmd's 1000-line cap and the method takes it to 1018 (measured). A focused class keeps the write reviewable on its own. Chosen: `lib/Db/RegisterFolderRecorder.php`, taking `IDBConnection`.
- Provision at import (option 1): see Non-Goals.

### Take a folder a concurrent request just created

`createFolderPath()` checks for the root folder and the register folder with `get()`, then calls `newFolder()` when it is missing. Two first uploads can both miss and both call `newFolder()`; the second gets a `NotPermittedException` and the upload failed. A small helper now gets or creates a folder and, when creation is refused, looks once more and takes the folder if it now exists. Only a folder it created itself goes on to the ownership transfer and, for the root, the group setup, as before.

### The constructor takes the recorder

`FolderManagementHandler` already has nine constructor collaborators and phpmd's parameter-list cap is ten. The recorder is the tenth, so the constructor carries the codebase's usual `@SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection` (the same line `FileService` and `MagicMapper` carry). The alternatives were routing a database write through the `FileService` facade, or a setter; both hide the dependency.

### Declarative-vs-imperative decision

Not applicable in the ADR-031 sense: no lifecycle, aggregation, calculation, notification, relation or widget is involved. This is file-storage plumbing.

## Risks / Trade-offs

- [A listener relied on `RegisterUpdatedEvent` to learn a register's folder] → None of the four listeners reads the folder: they notify, invalidate the authorization cache, send webhooks and write activity about register edits. The PR says the event no longer fires for folder bookkeeping.
- [An authenticated first upload used to record the folder through `update()` too] → The recorder now serves every caller, so an admin's first upload also no longer produces an "updated" activity entry for the register. That entry described nothing a person did.
- [Two requests record different folder ids] → Possible only when one has a session (folder made in that user's files) and one has none (system user's files), both on a register with no folder; the compare-and-set keeps the first, and the second request's upload still lands in a real folder. Same outcome as today's last-writer-wins, minus the overwrite.
- [The request-scoped `RegisterMapper` find cache holds a register instance with the old folder value] → The handler sets the folder on the instance it holds, which is the cached instance for that lookup; any other instance takes the idempotent path (finds the folder by path, the compare-and-set declines, the upload proceeds).

## Migration Plan

None. No schema or data change. Rollback is reverting the PR.

## Seed Data

Not applicable: no OpenRegister schema is introduced or changed.
