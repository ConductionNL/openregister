# Tasks: reply-threading-by-headers

## 1. Data

- [ ] 1.1 Migration: `rfc_message_id` and `direction` on `openregister_email_links`, indexed on `rfc_message_id`.
- [ ] 1.2 Read the header at link time in `EmailProvider`; `BackfillEmailLinkMessageIdJob` one-shot with a kill switch.
- [ ] 1.3 Outbound link written by the dispatch leaf's e-mail channel when `object` is bound.

## 2. Resolve

- [ ] 2.1 `EmailsController::resolve()` with the RBAC scoping and the granted
      system scope (ADR-099). **The HEADER ORDER and the refusals are built**
      in `lib/Service/Notification/ReplyThreadResolver.php`; the controller,
      the scoping and the routes are not.
      `In-Reply-To` is read before `References`, and `References` is walked
      from its LAST entry because that is the nearest ancestor.
      **Nothing is ever guessed.** No fuzzy match, no prefix match, no subject
      fallback — the `[ZAAK-…]` tag is deliberately not read, because it is
      the guess that files one citizen's reply on another citizen's case.
      **A chain naming two objects resolves NEITHER** and names both as
      candidates: `References` accumulates every ancestor, and somebody
      replying about case A while quoting a mail about case B hands us both.
      Picking the first, the last or the newest is a coin flip with a
      disclosure on one side.
      **A reply with no usable reference is `unthreaded`, a named answer.** It
      is real, it arrived and a person has to see it; an empty result leaves
      it in a queue nobody reads while the system looks healthy. Only a
      `threaded` result may be filed without a human.
- [ ] 2.2 `by-message` also matches by `rfcMessageId`.

## 3. Tests

- [ ] 3.1 Unit tests for the scopes, the backfill and the outbound link;
      Newman for resolve. **The order and the refusals are tested**:
      `tests/Unit/Service/Notification/ReplyThreadResolverTest.php` (13),
      including two objects belonging to different people resolving neither,
      a partial id never matching, and case-insensitive header names.

## 4. Woo programme amendment: collected mail as threads (REQ-RTH-010, REQ-RTH-011, row 19.6)

- [ ] 4.1 Read `In-Reply-To` and `References` in `EmlParser::extractHeaders()` and carry them on `EmlStructure`, reusing `ReplyThreadResolver`'s header normalisation. Verify: `tests/Unit/Service/TextExtraction/EmlThreadHeadersTest.php::testInReplyToAndReferencesAreRead` (fails today: the keys do not exist), `testHeaderNamesAreCaseInsensitive`.
- [ ] 4.2 Migration and mapper for `openregister_mail_headers`; write the row from `TextExtractionService` when the source is an `.eml`, replace on re-extraction, delete beside `ChunkMapper::deleteBySource()`. Verify: `tests/Unit/Service/MailHeaderStoreTest.php::testExtractionStoresTheHeaderRow` through `extractFile()`.
- [ ] 4.3 `lib/Service/Mail/MailThreadAssembler.php`. Verify: `tests/Unit/Service/Mail/MailThreadAssemblerTest.php::testAReplyChainIsOneThread`, `testAnEditedSubjectStaysInTheThread`, `testAParentOutsideTheSetIsAGap`, `testEqualSubjectsWithoutHeadersStayApart`, `testADuplicateMessageIdIsOneNode`.
- [ ] 4.4 `GET /api/files/{fileId}/thread` on `FileSidebarController` (`#[NoAdminRequired]`, read check per member in the method). Verify: `tests/Unit/Controller/MailThreadEndpointTest.php::testTheThreadIsReturnedInDateOrder`, `testAnUnreadableMemberIsAGapWithoutHeaders`; hydra gates route-auth and no-admin-idor pass; a Newman request in `tests/newman/` uploads three fixture `.eml` files to an object and reads the thread of the last.
- [ ] 4.5 "Conversation" section in the files sidebar for `.eml` files (`src/files-sidebar.js` tab). Verify: `tests/e2e/ci/mail-thread-reader.spec.ts` uploads the five-message fixture, opens the fourth in Files, sees five entries with the fourth marked and opens the first by clicking it.
- [ ] 4.6 Contract for dossiq: response keys `thread[].fileId|messageId|parent|sentAt|from|subject|gap` and `position`. Verify: `tests/Contract/MailThreadContractTest.php`; `dossiq/woo-review-triage` carries the consumer test. dossiq requires OpenRegister; without dossiq the sidebar section is the reader.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
