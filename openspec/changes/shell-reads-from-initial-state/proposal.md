## Why

Every OpenRegister page fired three reads of its own app shell at boot, after
the bundles had run: `GET /api/setup/status` and the two walkthrough
preferences (`GET /api/preferences/walkthrough_seen_version` and
`.../walkthrough_seen_version-progress`). Each one is a full Nextcloud request,
which costs ~20-30 ms of php-fpm bootstrap CPU. On a loaded server, the parallel
requests of one page queue on that CPU (PERF-HANDOFF, 27 Nov 2026). The answers
are known when the page is rendered. nextcloud-vue (2.234 and later) reads them
from initial state when a page carries them (`setup_status`,
`walkthrough_preferences`), as launchpad already does.

## What Changes

- `ShellBootReads` (lib/Controller/Support) provides `walkthrough_preferences`
  (`{seen, progress}`, the values the preferences endpoint answers) for every
  signed-in user. It provides `setup_status` (the status document) for
  administrators only. The setup endpoint is admin-only, and an in-process
  read skips its middleware, so for everyone else the key is left out and the
  shell asks the endpoint as before.
- The SPA renders (`UiController::makeSpaResponse`, `DashboardController::page`)
  call it.
- `SetupStatusReader` builds the status document for both the endpoint and the
  page, so they cannot drift apart.
- `@conduction/nextcloud-vue` raised to ^2.234.4, the first range with the
  initial-state readers.

## Impact

- Affected specs: `apphost-boilerplate`, `first-time-setup` (one added requirement each).
- Affected code: `lib/Controller/Support/ShellBootReads.php`,
  `lib/Controller/Support/SetupStatusReader.php`, `lib/Controller/SetupController.php`,
  `lib/Controller/UiController.php`, `lib/Search/Controller/DashboardController.php`,
  `package.json`, `package-lock.json`.
- No API change: the endpoints keep answering as before.
