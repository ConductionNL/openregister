# Lane f-platform (follow-up, 09-28)

## register-folder-at-import (portaliq#29 option 1, follow-up to #4116)
- Branch feat/register-folder-at-import cut --no-track from origin/development 5e641d9d8d (#4116 merged there as ecaba04a96).
- Change: RegisterFolderProvisioner (new), ImportHandler::importFromApp hook via optional setter, Application wiring,
  repair step CreateMissingRegisterFolders (post-migration, last), docs paragraph, 3 test classes.
- openspec validate --strict valid. php -l 0, phpcs (lib) 0, phpmd 0, phpstan (fresh cache) 0, psalm (touched) 0,
  phpunit --filter (5 classes) 16 tests OK.
- Commit 19ee0f7336 pushed. Next: check:strict, npm lint/format/l10n, gates, PR.
- Gates (base origin/development, 14 files) exit 1 on gate-110 (repair step without <version> move): fixed ef1eb85d4f
  (2.1.33-unstable.20260928180000); checker standalone 0, check:migration-version 0. Gates 23/24/96/112 advisory.
- check:strict once: exit 1 only from test:all's missing coverage driver; lint/mv/phpcs/phpmd/psalm/phpstan pass;
  full suite 24287 tests, 0 failures, 25 skipped. npm lint 0, format 0, test:l10n 0.
- Commits 19ee0f7336, ef1eb85d4f, 9e1129f04c pushed. PR https://github.com/ConductionNL/openregister/pull/4136 . DONE.
- portaliq change (drop GREP_INVERT) written in the PR body, not made. CI not read.
