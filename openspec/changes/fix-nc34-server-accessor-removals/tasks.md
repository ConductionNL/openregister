## 1. Fix the reported crash (OrganisationMapper)

- [x] 1.1 Inject `\OCP\IConfig` into `lib/Db/OrganisationMapper.php` constructor as `private readonly IConfig $config`
- [x] 1.2 Replace `\OC::$server->getSystemConfig()->getValue('dbtableprefix', 'oc_')` at line 876 with `$this->config->getSystemValue('dbtableprefix', 'oc_')`
- [x] 1.3 Update `tests/Unit/Db/OrganisationMapperTest.php` to pass an `IConfig` mock to the constructor — N/A (no dedicated unit test exists; mapper is DI-resolved everywhere and only mocked — not instantiated — by other unit tests)
- [x] 1.4 Run `composer check:strict` and confirm `OrganisationMapper` passes — static suite (lint, phpcs, phpmd, psalm, phpstan) scoped to the file all pass; phpunit skipped here (needs live DB env)
- [x] 1.5 Commit: "fix(nc34): inject IConfig into OrganisationMapper, replace getSystemConfig" — 2a9e15ac9

## 2. Migrate Db/ mappers

- [ ] 2.1 `AuditTrailMapper`: inject `IUserSession`, `IRequest`, `LoggerInterface`; replace the 7 named-accessor call sites at lines 304, 323, 324, 1009, 1050, 1103, 1203 with `$this->userSession`, `$this->request`, `$this->logger`
- [ ] 2.2 Update `tests/Unit/Db/AuditTrailMapperTest.php` with new mocks
- [ ] 2.3 `SearchTrailMapper`: inject `LoggerInterface`; replace 2 `getLogger()` call sites at lines 773, 1036 with `$this->logger`
- [ ] 2.4 Update `tests/Unit/Db/SearchTrailMapperTest.php` with new logger mock
- [ ] 2.5 Run `composer phpunit -- tests/Unit/Db` and confirm green
- [ ] 2.6 Commit: "fix(nc34): migrate Db mappers to constructor DI for request/session/logger"

## 3. Migrate Service/ layer

- [ ] 3.1 `UserService`: inject `IDBConnection` and `\OCP\L10N\IFactory`; replace `\OC::$server->getDatabaseConnection()` at line 554 and `\OC::$server->getL10NFactory()->findLanguage()` at line 604
- [ ] 3.2 Update `tests/Unit/Service/UserServiceTest.php` with new mocks
- [ ] 3.3 `ReferentialIntegrityService`: inject `IDBConnection`; replace `\OC::$server->getDatabaseConnection()` at lines 352 and 850
- [ ] 3.4 Update `tests/Unit/Service/Object/ReferentialIntegrityServiceTest.php` with new mocks
- [ ] 3.5 `RetentionService`: inject `IDBConnection`; replace `\OC::$server->getDatabaseConnection()` at line 570
- [ ] 3.6 Update `tests/Unit/Service/RetentionServiceTest.php` with new mocks
- [ ] 3.7 Run `composer phpunit -- tests/Unit/Service` and confirm green
- [ ] 3.8 Commit: "fix(nc34): migrate Service layer to constructor DI for DB/L10N"

## 4. Migrate Controller/ layer

- [ ] 4.1 `DeletedController`: inject `IGroupManager`; replace `\OC::$server->getGroupManager()` at line 81
- [ ] 4.2 Update `tests/Unit/Controller/DeletedControllerTest.php` with `IGroupManager` mock
- [ ] 4.3 `GraphQLController`: inject `IURLGenerator`, `ContentSecurityPolicyNonceManager`, `CsrfTokenManager`; replace 3 call sites at lines 199, 208, 209
- [ ] 4.4 Update `tests/Unit/Controller/GraphQLControllerTest.php` with new mocks
- [ ] 4.5 Run `composer phpunit -- tests/Unit/Controller` and confirm green
- [ ] 4.6 Commit: "fix(nc34): migrate GraphQL and Deleted controllers to constructor DI"

## 5. Migrate BackgroundJob, Notification, Command

- [ ] 5.1 `DestructionCheckJob`: inject `IDBConnection`; replace `\OC::$server->getDatabaseConnection()` at line 172
- [ ] 5.2 Update `tests/Unit/BackgroundJob/DestructionCheckJobTest.php` with `IDBConnection` mock
- [ ] 5.3 `Notifier`: inject `IURLGenerator`; replace 2 call sites at lines 135, 144
- [ ] 5.4 Update `tests/Unit/Notification/NotifierTest.php` with `IURLGenerator` mock
- [ ] 5.5 `SolrDebugCommand`: identify the specific service(s) resolved via `getRegisteredAppContainer('openregister')` at line 256; inject each as an explicit constructor dependency
- [ ] 5.6 Update `tests/Unit/Command/SolrDebugCommandTest.php` with new mocks
- [ ] 5.7 Run `composer phpunit -- tests/Unit/BackgroundJob tests/Unit/Notification tests/Unit/Command` and confirm green
- [ ] 5.8 Commit: "fix(nc34): migrate background jobs, notifier, and commands to constructor DI"

## 6. Migrate Migration/ classes

- [ ] 6.1 `Version1Date20250830120000`: add `public function __construct(private readonly IDBConnection $connection)`; replace `\OC::$server->getDatabaseConnection()` at line 124 with `$this->connection`
- [ ] 6.2 `Version1Date20250908180000`: same treatment for line 80
- [ ] 6.3 `Version1Date20250929120000`: same treatment for line 112
- [ ] 6.4 `Version1Date20251103120000`: same treatment for line 131
- [ ] 6.5 Verify migrations still run cleanly: `docker-compose up db nextcloud`, install app, observe `occ migrations:execute openregister <version>` for each touched version
- [ ] 6.6 Remove (or clean up) the commented-out line in `Version1Date20250902130000.php:86`
- [ ] 6.7 Commit: "fix(nc34): migrate migration classes to constructor DI for IDBConnection"

## 7. Fix docblock reference

- [ ] 7.1 Update `lib/Event/ToolRegistrationEvent.php:43` docblock example from `\OC::$server->get(MyCMSTool::class)` to `\OCP\Server::get(MyCMSTool::class)` (OCP public API in the documentation, even while we discourage service-locator internally)
- [ ] 7.2 Commit: "docs(nc34): update ToolRegistrationEvent docblock to use OCP API"

## 8. Build the PHPCS sniff

- [ ] 8.1 Create directory `phpcs-custom-sniffs/CustomSniffs/Sniffs/Nextcloud/`
- [ ] 8.2 Implement `NoLegacyServerAccessorsSniff.php`:
  - Listen for `T_VARIABLE` (`$server`) or use `T_STRING` (`OC`) + `T_DOUBLE_COLON` + `T_VARIABLE` sequence detection — mirror the approach in `NamedParametersSniff`
  - Match the token pattern `OC :: $server -> getX` where `X` starts with an uppercase letter (named accessor)
  - Report as error code `CustomSniffs.Nextcloud.NoLegacyServerAccessors.LegacyNamedAccessor` with message `"Named accessor \\OC::$server->%s() is removed in Nextcloud 34. Inject %s via the constructor instead."` — interpolate the method name and the approved OCP replacement from a static map
  - Do NOT flag PSR-11 `->get(...)` in this change (D4 in design.md) — that is deferred
  - Ignore `T_COMMENT`, `T_DOC_COMMENT`, and string-literal tokens so docblock examples do not trip the sniff
- [ ] 8.3 Register the sniff in `phpcs.xml`:
  ```xml
  <rule ref="./phpcs-custom-sniffs/CustomSniffs/Sniffs/Nextcloud/NoLegacyServerAccessorsSniff.php">
      <type>error</type>
  </rule>
  ```
- [ ] 8.4 Write `tests/Unit/CustomSniffs/NoLegacyServerAccessorsSniffTest.php` with at least these cases:
  - ✅ positive: `\OC::$server->getSystemConfig()` reports an error
  - ✅ positive: `\OC::$server->getDatabaseConnection()` reports an error
  - ✅ positive: `\OC::$server->getLogger()` reports an error
  - ❌ negative: `\OC::$server->get(IConfig::class)` does NOT report (PSR-11 deferred)
  - ❌ negative: `$this->config` does NOT report
  - ❌ negative: docblock string `"\\OC::$server->getX()"` does NOT report
- [ ] 8.5 Run `composer phpcs` against `lib/` — confirm zero errors (all named accessors are gone after tasks 1–7)
- [ ] 8.6 Commit: "chore(phpcs): add NoLegacyServerAccessorsSniff to prevent regressions"

## 9. Final verification

- [ ] 9.1 Run `composer check:strict` from the project root — confirm zero errors across PHPCS, PHPMD, Psalm, PHPStan, and PHPUnit
- [ ] 9.2 Grep `lib/` for `OC::\$server->get[A-Z]` — confirm zero matches
- [ ] 9.3 Grep the full repo (including `tests/`) for `OC::\$server->get[A-Z]` — confirm only docblock/comment occurrences remain (and none in `lib/`)
- [ ] 9.4 Start the dev environment, hit `/apps/openregister/api/organisations` or equivalent, confirm no `Call to undefined method OC\Server::getSystemConfig()` trace in `data/nextcloud.log`
- [ ] 9.5 Run a smoke test of the four touched migrations by executing `occ migrations:execute openregister <version>` for each (or install the app fresh and verify the migration runner succeeds)
- [ ] 9.6 Update PHPStan baseline only if the counts changed; do NOT add new baseline entries (fix the code instead)
- [ ] 9.7 Open PR titled `fix(nc34): replace removed \OC::$server named accessors with constructor DI + enforcement`
