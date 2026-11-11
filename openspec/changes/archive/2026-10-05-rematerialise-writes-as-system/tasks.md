# Tasks: rematerialise-writes-as-system

- [x] 1.1 The command saves each re-evaluated row with RBAC and multitenancy off.
- [x] 1.2 It exits non-zero when any row fails (already true; covered by the test).
- [x] 2.1 `tests/Unit/Command/RematerialiseCalculationsAsSystemTest.php`: the save asks the real PermissionHandler, with the flags the command used, the update question; red on development with the live message.
