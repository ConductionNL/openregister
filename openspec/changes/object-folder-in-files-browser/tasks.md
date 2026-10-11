# Tasks: object-folder-in-files-browser

## 0. Decision

- [x] 0.1 Ruben picks an option from the proposal: option 1, subfolders kept (2026-10-10).

## 1. OpenRegister

- [x] 1.1 Rewrite this change's design for option 1.
- [x] 1.2 `ObjectFolderBrowser` service: resolve a relative path inside the object folder (refusing `..`, empty and `.` segments), list, create a folder, upload into a folder, rename and delete a node inside the folder, refuse a taken name and a locked file below a folder.
- [x] 1.3 `ObjectFolderController` with the five routes, checking the object through `ObjectFileAccess` (read for the listing, update for every change) and answering 404 / 403 as the object file endpoints do.
- [x] 1.4 Unit tests from the caller: the controller with a real `PermissionHandler` deciding read and update, and the service against a fake folder tree.
- [x] 1.5 API e2e spec `tests/e2e/ci/object-folder-browser.spec.ts`: an updater, a reader without update and a person without read, against a live instance.
- [x] 1.6 `composer check:strict` once before the push.

## 2. nextcloud-vue (separate PR)

- [ ] 2.1 `CnFilesBrowser` data source, with the WebDAV source kept as the default for the component on its own.
- [ ] 2.2 `CnFilesTab` uses the OpenRegister source for an object by default (`source: 'openregister'`), keeps `'webdav'`, and the root crumb reads the object's title.

## 3. Live check

- [ ] 3.1 On a throwaway instance: `bea`, who may read a case, sees its files in the browser; a person who may not read it does not.
