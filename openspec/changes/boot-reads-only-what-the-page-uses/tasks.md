# Tasks

## 1. Reads every page sent and did not use

- [x] 1.1 `RegistersSideBar` loads the dashboard register list in `created()`,
      not at import.
- [x] 1.2 `UploadFiles` loads its label choices when the modal opens, not on
      mount.
- [x] 1.3 `initializeAppData()` takes the active organisation from the
      organisation list answer and asks `/api/organisations/active` only when
      the list was not read or did not carry it.
- [x] 1.4 `initializeAppData()` no longer pre-loads applications, views and
      sources; `reloadAppData()` still refreshes every store.

## 2. Verification

- [x] 2.1 Unit tests for each path (`RegistersSideBar.spec.js`,
      `UploadFiles.golden.spec.js`, `AppInitializationService.spec.js`).
- [x] 2.2 Live on the sandbox reference stack: requests per page before and
      after, in the PR body.
