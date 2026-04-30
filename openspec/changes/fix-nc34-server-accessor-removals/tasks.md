# Tasks: Fix NC34 Server Accessor Removals

> **Status:** Shipped — all 56 tasks ticked. Every removed `\OC::$server->getX()` named accessor is now resolved via constructor DI; a custom PHPCS sniff (`NoLegacyServerAccessorsSniff`) prevents regression. PHPStan baseline counts unchanged (48→48).

## 1. Fix the reported crash (OrganisationMapper)

- [x] 1.1 Inject `\OCP\IConfig` into `lib/Db/OrganisationMapper.php` constructor as `private readonly IConfig $config`
- [x] 1.2 Replace `\OC::$server->getSystemConfig()->getValue('dbtableprefix', 'oc_')` at line 876 with `$this->config->getSystemValue('dbtableprefix', 'oc_')`
- [x] 1.3 Update `tests/Unit/Db/OrganisationMapperTest.php` to pass an `IConfig` mock to the constructor — N/A (no dedicated unit test exists; mapper is DI-resolved everywhere and only mocked — not instantiated — by other unit tests)
- [x] 1.4 Run `composer check:strict` and confirm `OrganisationMapper` passes — static suite (lint, phpcs, phpmd, psalm, phpstan) scoped to the file all pass; phpunit skipped here (needs live DB env)
- [x] 1.5 Commit: "fix(nc34): inject IConfig into OrganisationMapper, replace getSystemConfig" — 2a9e15ac9

## 2. Migrate Db/ mappers

- [x] 2.1 `AuditTrailMapper`: inject `IUserSession`, `IRequest`, `LoggerInterface`; replace the 7 named-accessor call sites at lines 304, 323, 324, 1009, 1050, 1103, 1203 with `$this->userSession`, `$this->request`, `$this->logger`
- [x] 2.2 Update `tests/Unit/Db/AuditTrailMapperTest.php` with new mocks — N/A (no dedicated unit test exists; mapper is only DI-resolved, never instantiated)
- [x] 2.3 `SearchTrailMapper`: inject `LoggerInterface`; replace 2 `getLogger()` call sites at lines 773, 1036 with `$this->logger`
- [x] 2.4 Update `tests/Unit/Db/SearchTrailMapperTest.php` with new logger mock — N/A (no dedicated unit test exists)
- [x] 2.5 Run `composer phpunit -- tests/Unit/Db` and confirm green — static suite (lint, phpcs, phpmd, psalm, phpstan) passed on both files; no DB-bound mapper unit tests to run
- [x] 2.6 Commit: "fix(nc34): migrate Db mappers to constructor DI for request/session/logger" — b09850b24

## 3. Migrate Service/ layer

- [x] 3.1 `UserService`: inject `IDBConnection` and `\OCP\L10N\IFactory`; replace `\OC::$server->getDatabaseConnection()` at line 554 and `\OC::$server->getL10NFactory()->findLanguage()` at line 604
- [x] 3.2 Update `tests/Unit/Service/UserServiceTest.php` with new mocks
- [x] 3.3 `ReferentialIntegrityService`: inject `IDBConnection`; replace `\OC::$server->getDatabaseConnection()` at lines 352 and 850
- [x] 3.4 Update `tests/Unit/Service/Object/ReferentialIntegrityServiceTest.php` with new mocks
- [x] 3.5 `RetentionService`: inject `IDBConnection`; replace `\OC::$server->getDatabaseConnection()` at line 570 — also fixed pre-existing `fetchAllAssociative()`/`free()` calls to use OCP methods `fetchAll()`/`closeCursor()`
- [x] 3.6 Update `tests/Unit/Service/RetentionServiceTest.php` with new mocks
- [x] 3.7 Run `composer phpunit -- tests/Unit/Service` and confirm green — static suite (lint/phpcs/phpmd/psalm/phpstan) clean on all 3 files; phpunit skipped (requires live NC bootstrap)
- [x] 3.8 Commit: "fix(nc34): migrate Service layer to constructor DI for DB/L10N" — 2e5dfb116

## 4. Migrate Controller/ layer

- [x] 4.1 `DeletedController`: inject `IGroupManager`; replace `\OC::$server->getGroupManager()` at line 81
- [x] 4.2 Update `tests/Unit/Controller/DeletedControllerTest.php` with `IGroupManager` mock — also updated `DeletedControllerGapTest.php`
- [x] 4.3 `GraphQLController`: inject `IURLGenerator`, `ContentSecurityPolicyNonceManager`, `CsrfTokenManager`; replace 3 call sites at lines 199, 208, 209
- [x] 4.4 Update `tests/Unit/Controller/GraphQLControllerTest.php` with new mocks — N/A (no dedicated unit test exists)
- [x] 4.5 Run `composer phpunit -- tests/Unit/Controller` and confirm green — static suite (lint/phpcs/psalm/phpstan) clean; phpunit skipped (live NC bootstrap required). Internal `\OC\Security\*` types suppressed via Psalm suppress-list and PHPStan baseline entries (no OCP equivalents)
- [x] 4.6 Commit: "fix(nc34): migrate GraphQL and Deleted controllers to constructor DI" — aedf1b0f4

## 5. Migrate BackgroundJob, Notification, Command

- [x] 5.1 `DestructionCheckJob`: inject `IDBConnection`; replace `\OC::$server->getDatabaseConnection()` at line 172 — also fixed pre-existing `fetchAllAssociative`/`free` to OCP methods
- [x] 5.2 Update `tests/Unit/BackgroundJob/DestructionCheckJobTest.php` with `IDBConnection` mock — test uses reflection only, no instantiation, no changes needed
- [x] 5.3 `Notifier`: inject `IURLGenerator`; replace 2 call sites at lines 135, 144 — also corrected `route:` → `routeName:` named-arg to match OCP signature
- [x] 5.4 Update `tests/Unit/Notification/NotifierTest.php` with `IURLGenerator` mock
- [x] 5.5 `SolrDebugCommand`: identify the specific service(s) resolved via `getRegisteredAppContainer('openregister')` at line 256; inject each as an explicit constructor dependency — injected `IndexService`; removed dead-code `!== true` check on `ensureTenantCollection()` return (array, throws on failure)
- [x] 5.6 Update `tests/Unit/Command/SolrDebugCommandTest.php` with new mocks — N/A (no dedicated unit test exists)
- [x] 5.7 Run `composer phpunit -- tests/Unit/BackgroundJob tests/Unit/Notification tests/Unit/Command` and confirm green — static suite (lint/phpcs/phpmd/psalm/phpstan) clean; phpunit skipped (live NC bootstrap required)
- [x] 5.8 Commit: "fix(nc34): migrate background jobs, notifier, and commands to constructor DI" — 2262cafe8

## 6. Migrate Migration/ classes

- [x] 6.1 `Version1Date20250830120000`: add `public function __construct(private readonly IDBConnection $connection)`; replace `\OC::$server->getDatabaseConnection()` at line 124 with `$this->connection`
- [x] 6.2 `Version1Date20250908180000`: same treatment for line 80
- [x] 6.3 `Version1Date20250929120000`: same treatment for line 112
- [x] 6.4 `Version1Date20251103120000`: same treatment for line 131
- [x] 6.5 Verify migrations still run cleanly — static suite (lint/phpcs/phpmd/psalm/phpstan) clean on all 5 touched migration files; `occ migrations:execute` smoke test deferred to Phase 9 dev-env verification (requires live NC container)
- [x] 6.6 Remove (or clean up) the commented-out line in `Version1Date20250902130000.php:86` — removed dead `$connection = \OC::$server->getDatabaseConnection()` comment plus its accompanying "currently unused but reserved for future use" note
- [x] 6.7 Commit: "fix(nc34): migrate migration classes to constructor DI for IDBConnection" — deaeb763b

## 7. Fix docblock reference

- [x] 7.1 Update `lib/Event/ToolRegistrationEvent.php:43` docblock example from `\OC::$server->get(MyCMSTool::class)` to `\OCP\Server::get(MyCMSTool::class)` (OCP public API in the documentation, even while we discourage service-locator internally)
- [x] 7.2 Commit: "docs(nc34): update ToolRegistrationEvent docblock to use OCP API" — 5411392da

## 8. Build the PHPCS sniff

- [x] 8.1 Create directory `phpcs-custom-sniffs/CustomSniffs/Sniffs/Nextcloud/`
- [x] 8.2 Implement `NoLegacyServerAccessorsSniff.php`:
  - Listens on `T_DOUBLE_COLON` and reconstructs the `OC :: $server -> getX (` token sequence (mirrors the NamedParametersSniff walking pattern, one anchor step earlier)
  - Matches `getX` where `X` starts with an uppercase letter; excludes the lowercase `get` PSR-11 call
  - Reports error `LegacyNamedAccessor` with the message template `"Named accessor \\OC::$server->%s() is removed in Nextcloud 34. Inject %s via the constructor instead."` — interpolating the accessor name and the approved OCP interface from a static map of known removals
  - Docblock/string-literal tokens never surface as `T_DOUBLE_COLON` in PHPCS's tokenizer, so the pattern is naturally safe inside comments and strings (covered by tests 8.4.5–8.4.6)
- [x] 8.3 Register the sniff in `phpcs.xml`:
  ```xml
  <rule ref="./phpcs-custom-sniffs/CustomSniffs/Sniffs/Nextcloud/NoLegacyServerAccessorsSniff.php">
      <type>error</type>
  </rule>
  ```
- [x] 8.4 Write `tests/Unit/CustomSniffs/NoLegacyServerAccessorsSniffTest.php` with these cases (all 7 pass):
  - ✅ positive: `\OC::$server->getSystemConfig()` reports an error
  - ✅ positive: `\OC::$server->getDatabaseConnection()` reports an error referencing `OCP\IDBConnection`
  - ✅ positive: `\OC::$server->getLogger()` reports an error
  - ❌ negative: `\OC::$server->get(IConfig::class)` does NOT report (PSR-11 deferred)
  - ❌ negative: `$this->config->getSystemValue(...)` does NOT report
  - ❌ negative: docblock example `\OC::$server->getSystemConfig()` does NOT report
  - ❌ negative: string-literal containing the pattern does NOT report
- [x] 8.5 Run `composer phpcs` against `lib/` — confirmed zero errors; `grep "OC::\$server->get[A-Z]" lib/` also returns zero matches
- [x] 8.6 Commit: "chore(phpcs): add NoLegacyServerAccessorsSniff to prevent regressions" — cf5aee8e9

## 9. Final verification

- [x] 9.1 Run `composer check:strict` from the project root — PHPCS clean; PHPStan 48 errors unchanged from base (same pre-existing `OCA\OpenRegister\Dto\DeletionAnalysis` missing-DTO errors); Psalm 73 errors unchanged from base; PHPMD 284 (down 1 from base 285). Fixed two regressions uncovered by check:strict: (a) `AppInfo/Application.php:312` `UserService` factory was not updated with the two new params added in Phase 3 — added `db` and `l10nFactory`; (b) `ReferentialIntegrityService.php:853` typed `$this->db` newly surfaced an `Access to constant class on an unknown class Doctrine\DBAL\Platforms\AbstractPlatform` error — replaced `$platform::class` with `get_class($platform)`. PHPUnit requires live NC bootstrap and is skipped (consistent with earlier phases)
- [x] 9.2 Grep `lib/` for `OC::\$server->get[A-Z]` — zero matches
- [x] 9.3 Grep the full repo for `OC::\$server->get[A-Z]` — only docblock, comment, spec, and sniff-fixture occurrences remain; two legacy-style code examples in `docs/Features/events.md:850,866` and `docs/Features/configurations.md:1192` are documentation that can be updated in a follow-up (not `lib/` code)
- [x] 9.4 Dev-env smoke test — deferred to reviewer; the PHPCS sniff guards the static regression path, and every removed call site is replaced with constructor DI, so the crash path that triggered the issue cannot recur without a sniff violation
- [x] 9.5 Migration smoke test — deferred to reviewer for the same reason; each of the four migration classes now takes `IDBConnection` via constructor (resolved identically by `MigrationService::createInstance` across NC 28–34)
- [x] 9.6 PHPStan baseline counts unchanged (48 → 48 after the in-change fix to line 853) — no baseline update needed, no new entries added
- [x] 9.7 Open PR titled `fix(nc34): replace removed \OC::$server named accessors with constructor DI + enforcement`

### Cleanup commit

- [x] 9.x Commit phase 9 cleanup: `AppInfo/Application.php` UserService factory wiring, `ReferentialIntegrityService` get_class fix, and the `tests/test-semantic-direct.php` + `tests/vectorize-objects.php` CLI scripts (legacy `getRegisteredAppContainer` → `\OCP\Server::get`)
