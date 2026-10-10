---
kind: code
depends_on: [object-files-follow-object-access]
---

# Proposal: object-folder-in-files-browser

## Status

**Needs a decision before it is built.** This change records the finding, the
cause and the options. It does not pick one, because every option either reverses
part of decision 1 of `object-files-follow-object-access` (approved by Ruben on
2026-10-04) or moves work into nextcloud-vue. See "Decision needed".

## Why

A person who may read an object cannot browse its folder in the files tab.

Found live on 2026-10-10 on a throwaway instance (Nextcloud 34, openregister
`development` at `ae4084d72a`, dossiq `development`). User `bea`, member of the
group the case schema grants read, opens a case:

- `GET /api/objects/20/35/<case>` returns `@self.folder: 270`.
- A WebDAV `SEARCH` for file id 270 under `/files/bea` returns no `d:href`.
- So `resolveObjectFolder()` in nextcloud-vue (`CnFilesBrowser/filesBrowser.js`)
  returns null, and `CnFilesTab` shows the old attachments drop zone instead of
  the files browser: no folder view, no "Bestanden toevoegen", no drop overlay.

With a manual share of the folder from the `openregister` account to the reader,
the browser, the add button, the hint and the drop overlay all work.

## Cause

This is the cost `object-files-follow-object-access` accepted, meeting a library
built for the state before it:

1. Since that change every object folder lives in the `openregister` account's
   home, and OpenRegister decides file access from the object, after the check,
   as its own account. No folder is in a reader's tree (decision 1: "files leave
   the saver's Files app").
2. `CnFilesBrowser` reads and writes through WebDAV under `/files/<uid>`. It can
   only show a folder that is in the signed-in person's tree.
3. `resolveObjectFolder()` therefore finds the folder only for a person with a
   Nextcloud share on it. Nothing creates such a share for a reader.

## Why the obvious fix is wrong

Sharing the folder with each reader (what the manual share did) widens access in
a way that sustains itself. A user or group share on an object's folder **is an
object grant**: `ObjectGrantResolver` reads object grants from exactly those
shares ("Only a share whose node IS the object's folder grants the OBJECT"). A
share created because a read rule admitted `bea` would keep admitting `bea` after
the rule changes, her group membership ends, or the case's condition stops
matching, and her access would then justify keeping the share. It would also put
the folders back in every reader's Files app, which decision 1 removed.

## Options

1. **The browser reads through OpenRegister's files API, not WebDAV**
   (nextcloud-vue, plus folder endpoints here). Every call passes the object
   guard, so access is exact by construction and nothing appears in the Files
   app. Cost: the object files API is flat today (`files#index`, `move`,
   `rename`, `copy`, upload, delete, versions); subfolders need a list and a
   create endpoint, and `CnFilesBrowser` needs a data source other than its DAV
   client. **Recommended.**
2. **An OpenRegister mount provider** (the second follow-up shape in that change's
   design: "a WebDAV view served by OpenRegister that lists only objects the
   caller may read"). At each filesystem setup, mount the folders of a bounded
   candidate set (for example the person's recently opened objects, from the
   audit trail) after the object read check, read-only without `update`. Exact
   per request and no library change. Cost: private `\OC\Files` classes (mount
   point, jail, permission mask) that this codebase does not use today, RBAC work
   on filesystem setup, the mount cache's own lag, and the folders reappear in the
   Files app.
3. **Per-reader shares, reconciled.** Rejected for the self-sustaining grant
   above, unless the grant resolver learns to ignore OpenRegister's own mirror
   shares, which is a second access path to keep in step.
4. **A register-folder share with a named group** (the first follow-up shape).
   Opt-in and off by default, but it widens by design, so it does not meet "no
   wider than the object's access".

Separately, explicit object grants (`ObjectSharingService::grant()`) already put
the folder in the grantee's tree, and so would make the browser work for a
grantee, but they fail today with core's "Cannot increase permissions" because the
caller is recorded as sharer of a folder the `openregister` account owns. That is
openregister#4508's open question and is left there.

## Decision needed

- Which option: 1 (recommended), 2, or another.
- For option 1: whether subfolders inside an object folder are kept (they are in
  the library's New menu today) or the browser is flat for object folders.

## Impact

- Option 1: openregister `FilesController` (folder list and create), nextcloud-vue
  `CnFilesBrowser` / `CnFilesTab`. dossiq needs nothing.
- Option 2: openregister only, new mount provider and its registration.
