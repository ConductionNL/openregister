## ADDED Requirements

### Requirement: Public OCP API Only

Application code in `lib/` SHALL interact with Nextcloud services exclusively through the public `\OCP` API. Direct use of the internal `\OC\Server` container — including the `\OC::$server` static property and any of its named accessors (`getSystemConfig`, `getDatabaseConnection`, `getLogger`, `getUserSession`, `getRequest`, `getURLGenerator`, `getGroupManager`, `getL10NFactory`, `getCsrfTokenManager`, `getContentSecurityPolicyNonceManager`, `getRegisteredAppContainer`, and any future accessor on `\OC\Server`) — is prohibited.

#### Scenario: Class needs the system config
- **WHEN** a class in `lib/` needs to read a system-wide Nextcloud config value
- **THEN** the class MUST declare `\OCP\IConfig` as a constructor parameter and call `$this->config->getSystemValue(...)` at the call site
- **AND** the class MUST NOT call `\OC::$server->getSystemConfig()` or `\OCP\Server::get(IConfig::class)` as a replacement

#### Scenario: Class needs the database connection
- **WHEN** a class in `lib/` needs a database connection
- **THEN** the class MUST declare `\OCP\IDBConnection` as a constructor parameter
- **AND** the class MUST NOT call `\OC::$server->getDatabaseConnection()` or `\OCP\Server::get(IDBConnection::class)` as a replacement

#### Scenario: Class needs a logger
- **WHEN** a class in `lib/` needs to log
- **THEN** the class MUST declare `\Psr\Log\LoggerInterface` as a constructor parameter
- **AND** the class MUST NOT call `\OC::$server->getLogger()`

### Requirement: Constructor Dependency Injection

All services, controllers, mappers, background jobs, migrations, notifiers, and commands in `lib/` SHALL receive their Nextcloud framework collaborators via constructor dependency injection. Service-locator patterns — `\OCP\Server::get(X::class)` and `\OC::$server->get(X::class)` — SHALL NOT be introduced in new app code. They MAY remain in code paths that pre-exist this change and are not modified by it; when such a call site is touched for any reason, it MUST be migrated to constructor DI as part of the change.

#### Scenario: New class needs a Nextcloud service
- **WHEN** a new class is added to `lib/` that needs any Nextcloud service
- **THEN** the class MUST declare the required OCP interface (or Nextcloud-provided concrete class for items without an OCP interface, e.g. `ContentSecurityPolicyNonceManager`, `CsrfTokenManager`) as a constructor parameter
- **AND** the class MUST store it as a `private readonly` property

#### Scenario: Migration needs a database connection
- **WHEN** a `SimpleMigrationStep` subclass under `lib/Migration/` needs a database connection
- **THEN** the migration MUST declare `\OCP\IDBConnection` as a constructor parameter
- **AND** Nextcloud's `MigrationService::createInstance` SHALL resolve the migration via `\OCP\Server::get($class)` on all supported Nextcloud versions (28–34), so no service-locator fallback is required in the migration body

### Requirement: Static-Analysis Enforcement via PHPCS Sniff

The project SHALL ship a custom PHPCS sniff that fails the `composer phpcs` (and therefore `composer check:strict`) check when `lib/` contains a usage of `\OC::$server` — whether a named accessor (`\OC::$server->getX()`), a PSR-11 `get` (`\OC::$server->get(...)`), or any other access against the `\OC::$server` static property. The sniff SHALL apply to all files in `lib/`. No allowlist SHALL be added for specific call sites or file patterns.

#### Scenario: New commit reintroduces the pattern
- **WHEN** a developer commits code containing `\OC::$server->getX()` in `lib/`
- **THEN** `composer phpcs` SHALL report an error identifying the file and line
- **AND** `composer check:strict` SHALL exit non-zero
- **AND** the CI pipeline SHALL fail before merge

#### Scenario: New commit uses OCP interfaces correctly
- **WHEN** a developer commits code that uses `\OCP\IConfig` via constructor injection
- **THEN** `composer phpcs` SHALL pass for that file

#### Scenario: Existing code uses PSR-11 service-locator via \OC::$server
- **WHEN** PHPCS scans a file containing `\OC::$server->get(IConfig::class)`
- **THEN** the sniff SHALL flag this as an error
- **AND** the developer is expected to migrate the call to constructor DI

### Requirement: Approved Migration Targets for Removed Accessors

Each removed Nextcloud named accessor SHALL have a documented OCP replacement that applications use via constructor injection. The mapping SHALL be:

| Removed accessor | Replacement (inject via constructor) |
|---|---|
| `\OC::$server->getSystemConfig()` | `\OCP\IConfig` (call `getSystemValue`/`setSystemValue`) |
| `\OC::$server->getDatabaseConnection()` | `\OCP\IDBConnection` |
| `\OC::$server->getLogger()` | `\Psr\Log\LoggerInterface` |
| `\OC::$server->getUserSession()` | `\OCP\IUserSession` |
| `\OC::$server->getRequest()` | `\OCP\IRequest` |
| `\OC::$server->getURLGenerator()` | `\OCP\IURLGenerator` |
| `\OC::$server->getGroupManager()` | `\OCP\IGroupManager` |
| `\OC::$server->getL10NFactory()` | `\OCP\L10N\IFactory` |
| `\OC::$server->getCsrfTokenManager()` | `\OC\Security\CSRF\CsrfTokenManager` (inject the concrete class; no OCP interface exists) |
| `\OC::$server->getContentSecurityPolicyNonceManager()` | `\OC\Security\CSP\ContentSecurityPolicyNonceManager` (inject the concrete class; no OCP interface exists) |
| `\OC::$server->getRegisteredAppContainer($appId)` | Inject the specific collaborator the code actually needs; avoid raw container access |

#### Scenario: Developer looks up replacement for a removed accessor
- **WHEN** a developer encounters a removed `\OC::$server->getX()` accessor during maintenance
- **THEN** the replacement SHALL be found in the mapping table in this spec
- **AND** the developer SHALL migrate the call to constructor DI using the listed replacement

#### Scenario: Nextcloud adds a new framework service
- **WHEN** Nextcloud introduces a new OCP interface that OpenRegister needs
- **THEN** the mapping table in this spec SHALL be extended in the change that first depends on it
- **AND** the new service SHALL be consumed via constructor DI from its first use
