# Design: files leaf save to object

## D-1: one attach path, two entry points

Both actions end in `FileService::attach(objectId, node)`, which is the
existing validate, write, own, tag pipeline with the node as the source
instead of an upload. The Talk action first writes the exported chat as a
`.txt` node in the user's files, then attaches it, so the audit trail shows
one file event.

## D-2: the picker searches titles the user may write

The picker calls the object search with `_writable: true`, which the RBAC
layer already resolves, and shows title, schema and register per row. A
manifest may declare `fileActions.attachTargets: [{ register, schema }]` to
pin the list; the declaration is read at runtime by the picker, not by the
Files app.

## D-3: registration through the Nextcloud APIs

The Files action uses `@nextcloud/files` `registerFileAction`; the Talk
action uses Talk's message and conversation action registration. Both are
registered from OpenRegister's Files and Talk script entries, so the actions
exist on every instance that has OpenRegister enabled.

## D-4: kind

Code, in OpenRegister. Consuming apps declare `fileActions.attachTargets`
in config when they want a narrower picker.
