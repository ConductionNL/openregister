# Tasks: shell-reads-from-initial-state

- [x] 1.1 `SetupStatusReader` builds the setup status document; `SetupController::status()` answers it.
- [x] 1.2 `ShellBootReads` provides `walkthrough_preferences` for a signed-in user and `setup_status` for an administrator only.
- [x] 1.3 The SPA renders (`UiController`, Search `DashboardController::page`) provide the reads.
- [x] 1.4 Raise `@conduction/nextcloud-vue` to ^2.234.4 (initial-state readers).
- [x] 2.1 PHPUnit: `ShellBootReadsTest` (guest, user, unset preference, non-admin without setup status, admin document, both renders), `SetupStatusReaderTest`.
- [x] 3.1 Live, sandbox reference stack: OR Registers fires 16 instead of 19 requests (setup/status and both walkthrough reads gone); numbers in the PR.
