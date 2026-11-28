# Modals mount when they open

## What was wrong

`src/modals/Modals.vue` hosts every OpenRegister modal. 32 of them were already
mounted only while their navigation key is set and loaded as their own chunk.
The other 15 (ExportRegister, PublishRegister, DeleteRegister,
DeleteConfiguration, ExportConfiguration, PublishConfiguration,
PublishToGitlab, DeleteSchema, DeleteSchemaProperty, EditSource, DeleteSource,
DeleteObject, LockObject, UploadFiles, ViewConfiguration) were imported
statically and mounted on every page with the dialog closed. Each renders
nothing until its key is set, yet every OpenRegister page:

- downloaded and evaluated their code and styles in the main entry chunk
  (openregister-main.js 427 KB, of which ~80 KB these modals);
- built 15 component instances at boot, before DOMContentLoaded;
- inserted their styles into the document at boot.

## What changes

- The modal host mounts every modal only while its navigation key
  (`navigationStore.modal` or `navigationStore.dialog`) has the modal's value,
  and loads it as its own chunk the first time it opens.
- A delete dialog whose objects-in-use guard initialised on its first update
  after opening also initialises when it mounts open.

## Not in this change

- The modals' own behaviour once open.
