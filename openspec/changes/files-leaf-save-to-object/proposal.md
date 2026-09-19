# Files leaf: save a file or a Talk chat to any register object

## Why

Round 2 of the dossiq competitor analysis (row B05 in
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md`, decision
D10): OpenCase registers a "Save to OpenCase" file action in Nextcloud Files
and a "Save chat to OpenCase" entry in Talk
(`opencase/round2/pages/NextcloudFilesIntegration.md`,
`opencase/round2/menu-tree.md`). A caseworker who receives a file in Files or
a conversation in Talk attaches it to the case from where they are, without
opening the case app first.

dossiq registers no files plugin and no talk plugin. The files leaf already
copies and moves files between objects (file-actions, File Copy Between
Objects) and Talk rooms already link to objects (integration-talk). What is
missing is the entry point on the Nextcloud side, and it is the same entry
point for every fleet app.

## What changes

- OpenRegister registers a Files action "Add to object" on files and folders.
  It opens a picker that finds a register object by title across the schemas
  the user may write to, optionally narrowed by app, and attaches the file
  through the existing files-leaf upsert pipeline.
- OpenRegister registers a Talk conversation action "Save chat to object"
  that exports the conversation as a text file and attaches it the same way.
- A consuming app may pin the picker to its own schemas by declaring
  `fileActions.attachTargets` in its manifest; without it the picker offers
  every writable schema with a files leaf.

## Who benefits

dossiq, filinq, keepiq, humaniq, buildiq, and every app that mounts the files
leaf.

## Impact

- Affected specs: file-actions (delta).
- Affected code: a Files plugin under `src/files/`, a Talk plugin under
  `src/talk/`, `lib/Service/File/FileService.php` (attach by object id),
  one route for the object picker's title search.
- Backwards compatible: the existing file endpoints are unchanged.
