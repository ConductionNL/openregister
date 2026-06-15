## Why

Nextcloud 34 removed the legacy named accessors on `\OC\Server` (`getSystemConfig()`, `getDatabaseConnection()`, `getLogger()`, `getUserSession()`, `getRequest()`, `getURLGenerator()`, `getGroupManager()`, `getL10NFactory()`, `getCsrfTokenManager()`, `getContentSecurityPolicyNonceManager()`, `getRegisteredAppContainer()`). OpenRegister's `info.xml` already advertises `max-version="34"`, but the app still contains ~26 call sites using these removed methods — the first production crash is `Call to undefined method OC\Server::getSystemConfig()` in `OrganisationMapper.php:876`. This pattern has been cleaned multiple times before and keeps getting reintroduced, so we pair the cleanup with a static-analysis guard to prevent recurrence.

## What Changes

- **Replace every `\OC::$server->getX()` named-accessor call in `lib/` with constructor dependency injection using the OCP interface** — not another service-locator hop. Per ADR-004, apps MUST NOT use `\OC::$server` or static service locators; OCP interfaces injected through the constructor are the required pattern.
  - **Controllers, services, mappers, background jobs, notifiers, commands, and migrations** all get the needed OCP dependency added to their constructor and stored as a private readonly property; the call site becomes `$this->x`. Nextcloud's DI container (verified across NC 28–34 in `MigrationService::createInstance` via `\OCP\Server::get($class)`) resolves all of these — including migration classes — so no service-locator fallback is needed anywhere in app code.
  - Interface mapping at call sites:
    - `getSystemConfig()->getValue(...)` → inject `IConfig`, call `$this->config->getSystemValue(...)`
    - `getDatabaseConnection()` → inject `IDBConnection`
    - `getLogger()` → inject `LoggerInterface`
    - `getUserSession()` → inject `IUserSession`
    - `getRequest()` → inject `IRequest`
    - `getURLGenerator()` → inject `IURLGenerator`
    - `getGroupManager()` → inject `IGroupManager`
    - `getL10NFactory()` → inject `IFactory`
    - `getCsrfTokenManager()` → inject `CsrfTokenManager`
    - `getContentSecurityPolicyNonceManager()` → inject `ContentSecurityPolicyNonceManager`
    - `getRegisteredAppContainer('openregister')` → inject `IAppContainer` or replace the call with whatever the command actually needs (in `SolrDebugCommand` this is service lookup, which should be done via injected interfaces)
- **Fix the reported crash** at `lib/Db/OrganisationMapper.php:876` by injecting `IConfig` and using `$this->config->getSystemValue('dbtableprefix', 'oc_')`.
- **Add a new custom PHPCS sniff** `CustomSniffs\Sniffs\Nextcloud\NoLegacyServerAccessorsSniff` (mirroring the existing `NamedParametersSniff` structure) that flags any `\OC::$server->getX()` named-accessor call or bare `\OC::$server` usage as an error. No allowlist — the rule is absolute for app code.
- **Register the new sniff** in `phpcs.xml` so `composer phpcs` (and therefore `composer check:strict`) fails when the pattern returns.
- **Update affected unit tests** to provide mocks for the new constructor parameters.

**Out of scope** (deferred, tracked separately):
- The 446 `\OC::$server->get(X::class)` PSR-11-style calls — not crashing on NC 34 (they resolve identically to `\OCP\Server::get()`); migrating them to constructor DI is a separate modernization effort.
- The 60 `registerService` calls in tests — unrelated test-bootstrap concern.
- Broader service-locator-to-DI modernization beyond the named-accessor call sites.

## Capabilities

### New Capabilities

- `nextcloud-api-compat`: Defines OpenRegister's compatibility contract with the Nextcloud public API (`\OCP\*`). Codifies the rule that application code MUST use constructor DI with OCP interfaces, that `\OC::$server` (bare or via named accessors) is prohibited in `lib/`, and that the ban is enforced by a custom PHPCS sniff. Lists the approved migration targets for each removed accessor.

### Modified Capabilities

_None — no existing capability spec changes behavior; this is a framework-compatibility concern, not a feature change._

## Impact

- **Code**: ~26 call sites across ~13 files in `lib/`, all migrated to constructor DI:
  - **Mappers**: `OrganisationMapper` (adds `IConfig`), `AuditTrailMapper` (adds `IUserSession`, `IRequest`, `LoggerInterface`), `SearchTrailMapper` (adds `LoggerInterface`)
  - **Services**: `UserService` (adds `IDBConnection`, `IFactory`), `ReferentialIntegrityService` (adds `IDBConnection`), `RetentionService` (adds `IDBConnection`)
  - **Controllers**: `DeletedController` (adds `IGroupManager`), `GraphQLController` (adds `IURLGenerator`, `ContentSecurityPolicyNonceManager`, `CsrfTokenManager`)
  - **Background jobs**: `DestructionCheckJob` (adds `IDBConnection`)
  - **Notifier**: `Notifier` (adds `IURLGenerator`)
  - **Commands**: `SolrDebugCommand` (replace container lookup with an injected collaborator)
  - **Migrations**: `Version1Date20250830120000`, `Version1Date20251103120000`, `Version1Date20250908180000`, `Version1Date20250929120000` each add `IDBConnection` to their constructor (DI resolved by `MigrationService::createInstance` on all supported NC versions). The commented-out `Version1Date20250902130000` line is left alone or cleaned up.
- **Build**: new sniff file under `phpcs-custom-sniffs/CustomSniffs/Sniffs/Nextcloud/`; `phpcs.xml` gains one `<rule ref>` line; no Psalm/PHPStan config changes.
- **Tests**: unit tests for classes that gain constructor dependencies are updated to mock the new interfaces. No functional test rewrites — the behavior is identical.
- **Consumers**: no breaking changes — all affected call sites are internal. OCP interface return types are identical to the named-accessor returns.
- **Runtime**: restores app boot on NC 34 (was crashing inside `OrganisationMapper` on the first request that touches organisation state).
- **CI**: the new sniff runs under the existing `composer phpcs` step; no pipeline changes needed.
- **Dependent apps** (opencatalogi, softwarecatalog, docudesk, etc.): no impact — they consume OpenRegister via HTTP, not PHP interfaces.
