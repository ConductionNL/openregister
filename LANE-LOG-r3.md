# Lane log r3-platform (openregister, 2026-09-27 evening)

Clone: /home/rubenlinde/memcap-work/lq-lanes/or-primitives. Never staged.
TMPDIR for every run: /tmp/claude-1000/or-r3-tmp (outside the checkout). Isolated HOME for phpmd: /tmp/claude-1000/or-r3-tmp/home.

## 1. docx-structured-reader
- Branch feat/docx-structured-reader, cut --no-track from origin/development ae898b0659.
- Dedupe: no docx-structure change in openspec/changes, archive or specs (text-extraction-word is flat text only); no open PR.
- Artifacts: proposal, spec (10 ADDED requirements, REQ-DOCX-001..010), design, tasks. openspec validate --strict: valid.
- Code: DocumentExtractor (file handling, flat text from WordExtractor with structure fallback), DocumentBodyParser (sections, blocks,
  block cap), DocumentContentReader (paragraph inline pass, tables), DocumentStyleMap (headings, title, list numbering),
  OoxmlElements (local-name lookups). OoxmlPackage reused, header comment only. Split driven by phpmd class complexity (first cut 89).
- LibreOffice Writer is NOT installed on this box (only draw/impress), so the LibreOffice-shaped test document is hand-built from
  LO 24.2's DOCX conventions. Cross-check against python-docx 1.2.0 (Word's default template, scratch install, not committed):
  all structure right, flat text identical to WordExtractor. Finding: "List Bullet 2" is its own list instance (recorded in design).
- Diff-scoped: php -l 0; phpcs (lib) 0; phpmd per file (isolated HOME) 0; phpstan 0; psalm on touched files 0;
  phpunit --filter 'DocumentExtractorTest|PresentationExtractorTest|WordExtractorTest' 85 tests 0.
- Commit 644ef4c315 pushed.
- check:strict (once, with-slot): exit 1 only from test:all "No code coverage driver available"; lint/migration/phpcs/phpmd/
  psalm/phpstan pass; 24213 tests no failure. phpunit --no-coverage full: exit 0 (24213, 25 skipped, 1 warning not ours).
- npm lint 0 (932 inherited warnings), format 0, test:l10n 0. Hydra gates --scope-to-diff: exit 1, only gate-112 newman-reach
  (21 inherited collections); gate-16/46/57 pass; gate-68 checker wiring skip.
- opsx-verify: 0 CRITICAL, 0 WARNING; added the style-chain cap test (2ef8feaf99, 49 tests green). Task 3.2 ticked. Pushed.
- PR https://github.com/ConductionNL/openregister/pull/4111 . DONE (session killed by account limit mid-verify, resumed 09-28).
- 20:5x: check:strict (with-slot, HOME=/tmp/claude-1000/or-r3-tmp/home) and hydra gates --scope-to-diff running in background;
  logs /tmp/claude-1000/or-r3-tmp/c1-check-strict.log and c1-gates.log. npm lint 0 (932 inherited warnings), format 0, test:l10n 0.

## 2. register-folder-on-first-upload (portaliq#29), DRAFTED IN SCRATCH while change 1's check:strict holds the tree
- Drafts: /tmp/claude-1000/or-r3-tmp/c2/work/ (mirrors repo paths): openspec change (proposal, file-actions delta with
  REQ-RFFU-001/002, design, tasks), lib/Db/RegisterFolderRecorder.php (new, compare-and-set of the folder column),
  lib/Service/File/FolderManagementHandler.php (records via recorder, getOrCreateFolder race helper, ctor param + suppression),
  lib/AppInfo/Application.php (closure passes folderRecorder), tests (RegisterFolderRecorderTest, FirstUploadTest with fake root,
  FolderManagementHandlerRegistrationTest, 3 existing handler tests updated).
- Why not SystemOperationContext::run around update(): verifyOrganisationAccess() has no system bypass, so a portal request
  (default org) still fails on a register in another org; and the update event would report a register edit nobody made.
- Why a new class: RegisterMapper at 983/1000 phpmd class-length lines; method measured 1018. Handler ctor at 9 params; 10th
  trips ExcessiveParameterList -> the codebase's standard ctor suppression (90 files carry it).
- Verified on drafts via /tmp/claude-1000/or-r3-tmp/c2/bootstrap.php (prepended autoloader): recorder 4, first-upload 5,
  handler 27, access control 12, system context 7, registration 1, all green. Mutants (update() back; race lookup removed)
  both caught. phpmd/phpcs on the two lib drafts 0.
- Next: after check:strict for change 1 ends -> PR 1, then cut feat/register-folder-on-first-upload, copy drafts in.
- Draft backup (WSL-restart safe): <clone>/.tmp/r3-c2-drafts/ (untracked, never staged).
- 09-28 resume: branch feat/register-folder-on-first-upload cut --no-track from origin/development d6bb0ba50f; base files unchanged
  since the drafts' base (git diff ae898b0659 HEAD empty for them); drafts copied in. Dedupe found openregister#2515 (same defect,
  OR side) and consolidate-permission-handling point 4 / task 4 (fix belongs in folder init, no wider trust): cited in proposal/design.
- openspec validate --strict valid. php -l 0, phpcs 0, phpmd (recorder, handler, Application with baseline) 0, phpstan 0, psalm 0,
  phpunit --filter 'FolderManagementHandler|RegisterFolderRecorder|CreateFileHandler|FileService' 73 tests 0.
- Commit e372e261c5 pushed. Next: check:strict + npm + gates, PR, opsx-verify.
- check:strict (once): exit 1. lint/migration/phpcs/phpmd/psalm pass; phpstan 1000+ PHANTOM errors (entities lose Entity parent)
  from a stale result cache in /tmp/claude-1000/or-r3-tmp/tmp; fresh cache dir rerun of full phpstan: exit 0. test:all exit 1 only
  from the missing coverage driver; full phpunit --no-coverage exit 0 (24179 tests, 25 skipped).
- npm lint 0 / format 0 / test:l10n 0. Gates --scope-to-diff exit 1, only inherited gate-112; gate-6/16/46/57 pass.
- opsx-verify: 0 CRITICAL, 0 WARNING after adding a docs paragraph (docs/api/objects.md). Tasks 6/6 ticked.
- Commits e372e261c5, 2c6ba02c33, 0bbd9ff011 pushed. PR https://github.com/ConductionNL/openregister/pull/4116 . DONE.

## Lane end (09-28)
- CI read once: PR 4111 44 checks, 36 pass, 8 skipping, 0 fail. PR 4116 35 of 44 reported, 33 pending, 0 fail: NOT a green yet,
  read once more before landing.
- Clone idle on feat/register-folder-on-first-upload, tree clean apart from untracked logs and .tmp/.
