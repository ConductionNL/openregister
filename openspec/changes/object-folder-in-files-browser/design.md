# Design: object-folder-in-files-browser

Option 1 of the proposal, decided by Ruben on 2026-10-10, with subfolders kept.

## Facts this rests on

Checked on openregister `development` at `3a987bf002` and nextcloud-vue
`development` at `2525e4283`.

- `FolderManagementHandler::getOpenRegisterUserFolder()` returns the `openregister`
  account's home for every caller (`object-files-follow-object-access` task 2.1),
  and `FileService::getObjectFolder()` resolves the object's folder from
  `@self.folder` in that home.
- `FilesController` checks the object (`read` for reads, `update` for changes)
  through `ObjectFileAccess` before any file work and then works as the
  `openregister` account.
- `ReadFileHandler::getFile(object, fileId)` resolves a file id with
  `Folder::getById()` on the object folder, which searches the folder's whole
  subtree. So `files#show`, `files#rename` and `files#delete` already reach a file
  inside a subfolder, and only there.
- `FileService::getFilesForEntity()` lists only the object folder's direct
  children, and `files#delete` / `files#rename` refuse a folder node.
- `ObjectGrantResolver` resolves object grants from user, group and remote shares
  whose node is the object folder. A share is a grant.
- `resolveObjectFolder()` (nextcloud-vue) reads `@self.folder` and runs a DAV
  `SEARCH` under `/files/<uid>`; `CnFilesBrowser` lists, uploads, creates folders
  and renames over WebDAV.

## Invariant

No Nextcloud share is created to mirror a read rule. A share on an object folder
is a grant, and a grant is an access decision a person made, not a cache of one
the rules made. Every call the browser makes for an object goes through the
object guard, as the signed-in person, on every request.

## Endpoints

All under `/api/objects/{register}/{schema}/{id}/folder`, on a new
`ObjectFolderController` (FilesController is already past every size limit).
Signed-in callers only; an anonymous call is refused with 401, because a
published file is served by the existing public endpoints and nothing else.

| Verb   | Path                 | Needs  | Does |
| ------ | -------------------- | ------ | ---- |
| GET    | `folder?path=a/b`    | read   | Lists the folder at `path` (empty: the object folder): files and folders, with id, name, type, mime, size, mtime and the path relative to the object folder. Also `canChange`. |
| POST   | `folder`             | update | Creates folder `name` in `path`. 409 when the name is taken. |
| POST   | `folder/upload`      | update | Multipart upload of `files[]` into `path`, through the same `FileService::addFile()` pipeline as every other upload (executable block, object tag). 409 per file when the name is taken; 207 for a partial batch. |
| PUT    | `folder/{nodeId}`    | update | Renames a file or folder inside the object folder to `name`. |
| DELETE | `folder/{nodeId}`    | update | Deletes a file or folder inside the object folder. |

Refusals answer like the existing object file endpoints: 404 for a caller who may
not read the object (never confirming it exists), 403 for a reader who may not
change it.

Downloads use the existing `GET files/{fileId}`, which already resolves within the
folder's subtree and checks read.

### Paths

`path` is relative to the object folder. A segment that is empty, `.` or `..`,
or that contains a backslash or a NUL, is refused with 400 before any lookup, so
a path can never leave the object folder. A path that does not resolve to a
folder answers 404. A name (new folder, rename, upload) is one segment: no `/`,
`\`, NUL, and not `.` or `..`.

### Nodes

A node id is resolved with `Folder::getFirstNodeById()` on the object folder, so
an id from another object or from anywhere else answers 404, exactly as a file id
does today. The object folder itself is not a node of this API: renaming or
deleting it answers 404.

### Locks and frozen objects

Renaming or deleting a file goes through `FileService::renameFile()` /
`deleteFile()`, which already refuse a file locked by someone else. Deleting or
renaming a folder refuses (409) when any file below it is locked by someone else.
A frozen or archived object's write refusal (openregister#4549) hooks Nextcloud's
node write events, so it covers these endpoints without a change here.

## nextcloud-vue

`CnFilesBrowser` takes a `source` (an object with `list`, `createFolder`,
`upload`, `rename`, `remove`, `downloadUrl`); without one it keeps its WebDAV
behaviour, so hosts that use it on a person's own folder see no change.
`createOpenRegisterSource({ apiBase, register, schema, objectId })` is the source
for an object. `CnFilesTab` gets `source: 'openregister' | 'webdav'`, default
`'openregister'`; `'webdav'` keeps the DAV lookup and browser exactly as before.
With the OpenRegister source the Files app's registered actions and New menu
entries are not offered (they act over WebDAV on a tree the reader does not
have); the browser offers download, rename and delete itself, and upload and new
folder only when the listing says `canChange`. The root crumb reads the object's
title.
