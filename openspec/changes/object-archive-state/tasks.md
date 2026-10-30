# Tasks: object-archive-state

## 1. The marker and the guard

- [x] 1.1 `@self.archived` (`by`, `at`, `reason`) written outside the object's data, with an audit entry on archive and on unarchive (D-2).
- [x] 1.2 Write guard: a write to an archived object's data is refused with a message naming the archive, the archiver and the time (D-3).
- [x] 1.3 `x-openregister-archive` accepted by the schema annotation validator; the endpoint refuses 422 on a schema that does not declare it (D-5).

## 2. API

- [x] 2.1 Routes `POST` and `DELETE /api/objects/{register}/{schema}/{id}/archive`, requiring `update` (openregister ADR-010).
- [x] 2.2 `@self.archived` rendered on object reads and lists.

## 3. Exclusion

- [x] 3.1 Query parser: archived objects excluded by default, `_archived=true` and `_archived=any` (D-4).
- [x] 3.2 The same default in the aggregation endpoint and in every search provider, so a tile and its list agree.

## 4. Tests

- [x] 4.1 `tests/e2e/ci/object-archive-state.spec.ts`: archive, confirm it leaves the list, confirm the write refusal, restore.
- [x] 4.2 Unit tests for the guard, the query default, the aggregation default and the annotation validator; Newman for the routes.
- [x] 4.3 `openspec validate object-archive-state --strict`.

## Discovery cluster 29

- [x] C29.1 A frozen state beside archived: visible, searchable, refusing data writes, with an authorised unfreeze (D-C29-1).
- [x] C29.3 An immutable-once-set property rule, refused on change whatever the state (D-C29-3).
- [x] C29.7 Freezing and unfreezing on the audit trail.
- [x] C29.8 Tests: frozen appears in the list and refuses a write, archived does not appear, the immutable refusal.

### Not in the first PR, and why

- [ ] C29.2 A lifecycle state may declare that entering it freezes the object (D-C29-2).

  The freeze itself ships, and `@self.frozen` already carries the `state` that
  declared it, so the marker this needs is in place. What is missing is the
  listener that reads a `freezesObject` key off
  `x-openregister-lifecycle.states` and calls it. That listener has to sit
  beside `ArchivalNominationListener`, which the archiving lane is still
  editing on its third PR. Two lanes registering listeners on
  `ObjectTransitionedEvent` in the same file is how one of them gets dropped in
  a merge.

- [ ] C29.4 An object closed to new entries while staying readable.
- [ ] C29.5 A withdrawn entry: out of the working timeline, in the record, readable with actor and reason (D-C29-4).
- [ ] C29.6 A locked note refusing edits, beside `note-edit-history`.

  All three are about TIMELINE ENTRIES and NOTES, not about the object. They
  belong in the files the timeline lane owns, and building half of them here
  would collide with that lane and leave the other half in place claiming a
  requirement is met. The object-level primitives they will build on are done:
  `ObjectStateWriteException` already carries a named state and an actor, and
  the `frozen` marker is the pattern a per-entry lock copies.

- [ ] C29.9 Hand over to the dossiq lane with candidate ids C-case-core-8,
      C-tasks-and-phases-35, C-documents-5, C-communication-4,
      C-communication-12 and C-communication-13.

  The contract the consumers need is in the PR body: the two states, the four
  endpoints, the query flag and the `immutable` property keyword. C-case-core-8
  (frozen and readable), C-tasks-and-phases-35 (frozen by a closing phase, once
  C29.2 lands) and C-documents-5 (a final document) are answered by what ships
  here. C-communication-4, -12 and -13 wait on the timeline lane.

## Woo programme amendment: file writes honour the freeze (REQ-OAS-007, supports 5.18 and 19.15)

- [x] W.1 (`lib/Service/Object/FileWriteGuard.php`, called on the change path of `FilesController::ensureObjectAccess()`, so every write action goes through it; `FileService` writes reach the guard through `FrozenNodeWriteListener`, because they go through the Nextcloud node API; `tests/Unit/Service/Object/FileWriteGuardTest.php`) Add `lib/Service/Object/FileWriteGuard.php` (resolves the owning object, throws `ObjectStateWriteException::frozen()` or `::archived()`); call it from every write action in `FilesController` (`create`, `save`, `createMultipart`, `update`, `delete`, `rename`, `move`, `batch`, `lock`, `unlock`) and from the `FileService` write methods those actions use. Verify: `tests/Unit/Service/Object/FileWriteGuardTest.php::testEveryFilesControllerWriteActionCallsTheGuard` enumerates the controller's public methods by reflection and fails on a write action without the call; `testAFrozenObjectRefusesAnUpload` through `FilesController::create()` with a real `ObjectEntity` carrying the marker (fails today: the upload succeeds); `testAnUnfrozenObjectAcceptsFileWritesAgain`; `testReadsStayAllowed`.
- [x] W.2 (`lib/Listener/FrozenNodeWriteListener.php`; write and create throw a `HintException`, because Nextcloud's hook emitter swallows every other exception there; delete and rename use `abortOperation()`. "Unresolvable" means the owner lookup FAILED; "no such object" is the folder of an object still being created and is let through; `tests/Unit/Listener/FrozenNodeWriteListenerTest.php`) Add `lib/Listener/FrozenNodeWriteListener.php` on `BeforeNodeWrittenEvent`, `BeforeNodeDeletedEvent`, `BeforeNodeRenamedEvent` and `BeforeNodeCreatedEvent`, registered in `lib/AppInfo/Application.php`, calling `abortOperation()` for a node in a frozen or archived object's folder and for an unresolvable owner inside the register tree. Verify: `tests/Unit/Listener/FrozenNodeWriteListenerTest.php::testAWriteIntoAFrozenObjectFolderIsAborted` and `testAnUnresolvableOwnerInTheRegisterTreeIsRefused`, constructing the real event classes; `testANodeOutsideTheRegisterTreeIsIgnored`.
- [x] W.3 (`ObjectStateWriteException::toResponseBody()`, `FilesController::stateRefused()`; `tests/Unit/Controller/FilesFrozenResponseTest.php`) Map the exception to 409 with `{error, state, by, at, reason}` in the files controller responses. Verify: `tests/Unit/Controller/FilesFrozenResponseTest.php`.
- [ ] W.4 (written, live pass, decision 139: `tests/newman/openregister-frozen-files.postman_collection.json` (not registered in run-all.sh until its first live run; the WebDAV PUT is left out because no API user reaches the openregister home over WebDAV without a share) and the new case in `tests/e2e/ci/object-archive-state.spec.ts`) Through the callers: a Newman sequence in `tests/newman/` freezes an object, gets 409 on `POST .../files` and a failed WebDAV `PUT` into its folder, unfreezes, and gets 200; `tests/e2e/ci/object-archive-state.spec.ts` gains a case that freezes an object and sees the upload refused with the reason in the files tab.
- [x] W.5 (`tests/Unit/Contract/FrozenFileWriteContractTest.php`, in the unit suite so it runs on every PR; the freeze route now passes `state` through) Contract for the consumers: freeze through `POST /api/objects/{register}/{schema}/{id}/freeze` with `{reason, state?}`, the 409 body above on a file write. Verify: `tests/Contract/FrozenFileWriteContractTest.php` pins both; `opencatalogi/publication-withdrawal-aftercare` and `dossiq/woo-delivered-set-is-a-record` carry their consumer tests. Every consumer requires OpenRegister, so there is no absent-app path.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
