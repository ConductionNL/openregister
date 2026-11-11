---
kind: code
depends_on: [object-files-follow-object-access]
---

# Proposal: object-folder-in-files-browser

## Status

**Decided on 2026-10-10 (Ruben): option 1, with subfolders.** The files browser
reaches an object's files through OpenRegister's files API instead of WebDAV.
Access stays exactly the object's rule, decision 1 of
`object-files-follow-object-access` (object folders live in the `openregister`
account, out of the Files app, approved 2026-10-04) stands, and an object folder
keeps its subfolders.

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

## Options considered

1. **The browser reads through OpenRegister's files API, not WebDAV**
   (nextcloud-vue, plus folder endpoints here). Every call passes the object
   guard, so access is exact by construction and nothing appears in the Files
   app. **Chosen.**
2. **An OpenRegister mount provider** that mounts readable object folders at
   filesystem setup, after the read check. Needs private `\OC\Files` classes and
   puts the folders back in the Files app. Not chosen.
3. **Per-reader shares, reconciled.** Rejected for the self-sustaining grant
   above.
4. **A register-folder share with a named group.** Widens by design. Not chosen.

Explicit object grants (`ObjectSharingService::grant()`) failing with core's
"Cannot increase permissions" is openregister#4508's question and stays there.

## What changes

- New endpoints under `/api/objects/{register}/{schema}/{id}/folder`: list a
  folder of the object (its root or any subfolder, by a path relative to the
  object folder), create a subfolder, upload files into a subfolder, rename a
  file or folder, and delete a file or folder. Every one checks the object
  exactly as the existing object files endpoints do: reading needs read on the
  object (404 otherwise), changing needs update (403 for a reader).
- The listing says whether the caller may change the folder, so the browser can
  offer upload and new folder only to a person who may update the object.
- The existing object file endpoints keep working for files inside a subfolder
  (`files/{fileId}` download, rename, delete already resolve within the folder's
  whole tree). Nothing about them changes.
- nextcloud-vue (separate PR): `CnFilesBrowser` gets a data source that uses these
  endpoints; `CnFilesTab` uses it by default for an object, and keeps the WebDAV
  source for a host that asks for it.

## Impact

- openregister: new `ObjectFolderController`, new `ObjectFolderBrowser` service,
  five routes, unit tests, an API e2e spec.
- nextcloud-vue: `CnFilesBrowser`, `CnFilesTab`, a source module, docs.
- dossiq: nothing; it gets the browser by upgrading nextcloud-vue.
- No Nextcloud share is created anywhere in this change.
