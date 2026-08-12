<?php

/**
 * Minimal Nextcloud internal-class stubs for PHPUnit in the bare php:8.3-cli CI
 * environment.
 *
 * The `nextcloud/ocp` v31 stubs occasionally extend or implement Nextcloud
 * *internal* classes/interfaces (i.e. `OC\*` rather than `OCP\*`).  Because
 * those internal classes live in the Nextcloud server source and are NOT
 * shipped by the `nextcloud/ocp` composer package, they are absent in the
 * bare-composer install that CI uses.
 *
 * These stubs provide the minimal surface that the OCP stubs actually use —
 * typically just an interface declaration or a minimal class body — so that
 * PHP can evaluate the OCP interface files without fatal errors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// -----------------------------------------------------------------
// OC_FakeConfig  — minimal config stub for unit tests that don't need
// actual config values.  Returns defaults for all getters; no-ops all setters.
// Declared as a top-level class (not anonymous/eval) so it is compatible with
// any version of the OCP\IConfig interface without declaration mismatches.
// -----------------------------------------------------------------
if (class_exists('OC_FakeConfig') === false) {
	/**
	 * Minimal OCP\IConfig implementation for unit tests.
	 */
	class OC_FakeConfig {
		/** @param mixed $default @return mixed */
		public function getSystemValue($key, $default = '') {
			return $default;
		}
		public function getSystemValueBool(string $key, bool $default = false): bool {
			return $default;
		}
		public function getSystemValueInt(string $key, int $default = 0): int {
			return $default;
		}
		public function getSystemValueString(string $key, string $default = ''): string {
			return $default;
		}
		/** @param mixed $default @return mixed */
		public function getFilteredSystemValue($key, $default = '') {
			return $default;
		}
		/** @param mixed $value */
		public function setSystemValue($key, $value): void {
		}
		public function setSystemValues(array $configs): void {
		}
		public function deleteSystemValue($key): void {
		}
		public function getAppKeys($appName): array {
			return [];
		}
		public function setAppValue($appName, $key, $value): void {
		}
		public function getAppValue($appName, $key, $default = ''): string {
			return (string)$default;
		}
		public function deleteAppValue($appName, $key): void {
		}
		public function deleteAppValues($appName): void {
		}
		public function setUserValue($userId, $appName, $key, $value, $preCondition = null): void {
		}
		public function getUserValue($userId, $appName, $key, $default = ''): string {
			return (string)$default;
		}
		public function getUserValueForUsers($appName, $key, $userIds): array {
			return [];
		}
		public function getUserKeys($userId, $appName): array {
			return [];
		}
		public function getAllUserValues(string $userId): array {
			return [];
		}
		public function deleteUserValue($userId, $appName, $key): void {
		}
		public function deleteAllUserValues($userId): void {
		}
		public function deleteAppFromAllUsers($appName): void {
		}
		public function getUsersForUserValue($appName, $key, $value): array {
			return [];
		}
	}
}//end if

// -----------------------------------------------------------------
// OC  — the main Nextcloud server class.
//
// Tests that call \OC::$server->registerService() / ->get() need a minimal
// service-container stub.  The stub is functional: services registered via
// registerService() can be retrieved via get().  Unknown services return null
// so tests that just guard against an error don't blow up.
// -----------------------------------------------------------------
// OC_FakeServer is declared in its OWN class_exists()-guarded block,
// independent of whether the real `\OC` class is already loaded (e.g. when
// OPENREGISTER_TEST_NC_ROOT bootstraps a real Nextcloud root). Tests that
// reflect-invoke seams like Application::getRegisteredAppContainer() build
// fakes that `extends \OC_FakeServer` regardless of which server topology is
// in play, so this stub must stay available even when the "real \OC" branch
// below is skipped. Nesting it inside the `class_exists(\OC::class)` guard
// (the pre-fix layout) meant a real NC bootstrap satisfied that guard and
// silently left `OC_FakeServer` undefined — "Class OC_FakeServer not found".
if (class_exists('OC_FakeServer') === false) {
	// We have to declare this in the global namespace without a namespace block.
	// eval() makes that possible even when this file is parsed under strict_types.
	eval('
    /**
     * Minimal OC stub for PHPUnit in bare php:8.3-cli.
     */
    class OC_FakeServer {
        /** @var array<string,callable> */
        private array $registry = [];

        public function registerService(string $class, callable $factory): void {
            $this->registry[$class] = $factory;
        }

        public function get(string $id): mixed {
            if (isset($this->registry[$id])) {
                return ($this->registry[$id])($this);
            }
            return null;
        }

        /** @return mixed */
        public function getDatabaseConnection(): mixed { return $this->get(\OCP\IDBConnection::class); }
        /** @return mixed */
        public function getL10NFactory(): mixed { return $this->get(\OCP\L10N\IFactory::class); }
        /** @return mixed */
        public function getLogger(): mixed { return $this->get(\Psr\Log\LoggerInterface::class); }
        /** @return mixed */
        public function getSystemConfig(): mixed { return $this->get(\OC\SystemConfig::class); }
        /** @return mixed */
        public function getConfig(): mixed { return $this->get(\OCP\IConfig::class); }
        /** @return mixed */
        public function getUserSession(): mixed { return $this->get(\OCP\IUserSession::class); }
        /** @return mixed */
        public function getURLGenerator(): mixed { return $this->get(\OCP\IURLGenerator::class); }
        /** @return mixed */
        public function getRequest(): mixed { return $this->get(\OCP\IRequest::class); }
        /** @return mixed */
        public function getRegisteredAppContainer(string $appName): mixed { return null; }
        /** @return mixed */
        public function getAppInfo(string $appName): mixed { return []; }
    }
    ');
}//end if

if (class_exists(\OC::class) === false) {
	// We have to declare this in the global namespace without a namespace block.
	// eval() makes that possible even when this file is parsed under strict_types.
	eval('
    class OC {
        public static OC_FakeServer $server;
        /** @var bool CLI mode flag (false in unit tests) */
        public static bool $CLI = false;
    }
    OC::$server = new OC_FakeServer();
    // Pre-register a minimal IConfig so OCP\AppFramework\App constructor and
    // other framework code can call getSystemValueBool()/getSystemValue() without
    // triggering "call on null" fatal errors.
    // NOTE: we extend a concrete stub class (not implement the interface directly)
    // to avoid interface-signature incompatibilities across NC versions.
    OC::$server->registerService(\OCP\IConfig::class, function() {
        return new OC_FakeConfig();
    });

    // Pre-register a minimal IRequest so OCP\AppFramework\Http\Response::getHeaders()
    // can resolve the X-Request-Id header without returning null.
    OC::$server->registerService(\OCP\IRequest::class, function() {
        $req = new class implements \OCP\IRequest {
            public function getId(): string { return "unit-test-request-id"; }
            public function passesCSRFCheck(): bool { return true; }
            public function passesStrictCookieCheck(): bool { return true; }
            public function passesLaxCookieCheck(): bool { return true; }
            public function getCookie(string $key) { return null; }
            public function getHeader(string $name): string { return ""; }
            public function getParam(string $key, $default = null) { return $default; }
            public function getParams(): array { return []; }
            public function getMethod(): string { return "GET"; }
            public function getUploadedFile(string $key) { return null; }
            public function getEnv(string $key) { return null; }
            public function getRequestUri(): string { return "/"; }
            public function getRawPathInfo(): string { return "/"; }
            public function getPathInfo() { return null; }
            public function getScriptName(): string { return ""; }
            public function isUserAgent(array $agent): bool { return false; }
            public function getRemoteAddress(): string { return "127.0.0.1"; }
            public function getHttpProtocol(): string { return "http"; }
            public function getServerProtocol(): string { return "HTTP/1.1"; }
            public function getServerHost(): string { return "localhost"; }
            public function getInsecureServerHost(): string { return "localhost"; }
        };
        return $req;
    });

    // Pre-register a minimal IFactory (L10N factory) so OCP\Util::addInitScript()
    // and OCP\Util::addTranslations() can call findLanguage() without crashing.
    OC::$server->registerService(\OCP\L10N\IFactory::class, function() {
        return new class {
            public function findLanguage(?string $app = null): string { return "en"; }
            public function findLocale(?string $lang = null): string { return "en_US"; }
            public function findGenericLanguage(): string { return "en"; }
            public function get(string $app, ?string $lang = null, ?string $locale = null) { return null; }
            public function listAvailableLanguagesForApp(string $app): array { return []; }
            public function isLanguageAvailable(string $app, string $lang): bool { return false; }
        };
    });
    ');
}//end if

// -----------------------------------------------------------------
// OC\Hooks\Emitter
// Used by OCP\Files\IRootFolder (which extends Folder AND Emitter).
// -----------------------------------------------------------------
if (interface_exists(\OC\Hooks\Emitter::class) === false) {
	eval('namespace OC\Hooks;
    interface Emitter {
        public function listen(string $scope, string $method, \Closure $callback): void;
        public function removeListener(?string $scope = null, ?string $method = null, ?\Closure $callback = null): void;
    }');
}//end if

// -----------------------------------------------------------------
// OC\Files\View  (used in type-hints in several OCP interfaces)
// -----------------------------------------------------------------
if (class_exists(\OC\Files\View::class) === false) {
	eval('namespace OC\Files;
    class View {
        public function __construct(string $root = "") {}
    }');
}//end if

// -----------------------------------------------------------------
// OC\Files\Node\NonExistingFile / NonExistingFolder
// -----------------------------------------------------------------
if (class_exists(\OC\Files\Node\NonExistingFile::class) === false) {
	eval('namespace OC\Files\Node;
    class NonExistingFile {}');
}//end if
if (class_exists(\OC\Files\Node\NonExistingFolder::class) === false) {
	eval('namespace OC\Files\Node;
    class NonExistingFolder {}');
}//end if

// -----------------------------------------------------------------
// OC\ServerContainer  (referenced by some OCP interfaces/methods)
// -----------------------------------------------------------------
if (class_exists(\OC\ServerContainer::class) === false) {
	eval('namespace OC;
    class ServerContainer {}');
}//end if

// -----------------------------------------------------------------
// OC\SystemConfig
// -----------------------------------------------------------------
if (class_exists(\OC\SystemConfig::class) === false) {
	eval('namespace OC;
    class SystemConfig {}');
}//end if

// -----------------------------------------------------------------
// OC\Tags
// -----------------------------------------------------------------
if (class_exists(\OC\Tags::class) === false) {
	eval('namespace OC;
    class Tags {}');
}//end if

// -----------------------------------------------------------------
// OC\User\Backend / NoUserException
// -----------------------------------------------------------------
if (interface_exists(\OC\User\Backend::class) === false
	&& class_exists(\OC\User\Backend::class) === false
) {
	eval('namespace OC\User;
    interface Backend {}');
}//end if

if (class_exists(\OC\User\NoUserException::class) === false) {
	eval('namespace OC\User;
    class NoUserException extends \RuntimeException {}');
}//end if

// -----------------------------------------------------------------
// OC\Authentication\Token\IToken
// -----------------------------------------------------------------
if (interface_exists(\OC\Authentication\Token\IToken::class) === false) {
	eval('namespace OC\Authentication\Token;
    interface IToken {}');
}//end if

// -----------------------------------------------------------------
// OC\AppFramework\Http\Request
// -----------------------------------------------------------------
if (class_exists(\OC\AppFramework\Http\Request::class) === false) {
	eval('namespace OC\AppFramework\Http;
    class Request {}');
}//end if

// -----------------------------------------------------------------
// OC\AppFramework\Middleware\Security\Exceptions\NotAdminException
// -----------------------------------------------------------------
if (class_exists(\OC\AppFramework\Middleware\Security\Exceptions\NotAdminException::class) === false) {
	eval('namespace OC\AppFramework\Middleware\Security\Exceptions;
    class NotAdminException extends \RuntimeException {}');
}//end if

// -----------------------------------------------------------------
// OC\AppFramework\Middleware\Security\Exceptions\SecurityException
// -----------------------------------------------------------------
if (class_exists(\OC\AppFramework\Middleware\Security\Exceptions\SecurityException::class) === false) {
	eval('namespace OC\AppFramework\Middleware\Security\Exceptions;
    class SecurityException extends \RuntimeException {}');
}//end if

// -----------------------------------------------------------------
// OC\AppFramework\Routing\RouteConfig
// -----------------------------------------------------------------
if (class_exists(\OC\AppFramework\Routing\RouteConfig::class) === false) {
	eval('namespace OC\AppFramework\Routing;
    class RouteConfig {}');
}//end if

// -----------------------------------------------------------------
// OC\AppScriptDependency / OC\AppScriptSort
// -----------------------------------------------------------------
if (class_exists(\OC\AppScriptDependency::class) === false) {
	eval('namespace OC;
    class AppScriptDependency {}');
}//end if
if (class_exists(\OC\AppScriptSort::class) === false) {
	eval('namespace OC;
    class AppScriptSort {}');
}//end if

// -----------------------------------------------------------------
// OC\DB\Exceptions\DbalException
// -----------------------------------------------------------------
if (class_exists(\OC\DB\Exceptions\DbalException::class) === false) {
	eval('namespace OC\DB\Exceptions;
    class DbalException extends \RuntimeException {}');
}//end if

// -----------------------------------------------------------------
// OC\DB\QueryBuilder\Sharded\*
// -----------------------------------------------------------------
if (class_exists(\OC\DB\QueryBuilder\Sharded\CrossShardMoveHelper::class) === false) {
	eval('namespace OC\DB\QueryBuilder\Sharded;
    class CrossShardMoveHelper {}');
}//end if
if (class_exists(\OC\DB\QueryBuilder\Sharded\ShardDefinition::class) === false) {
	eval('namespace OC\DB\QueryBuilder\Sharded;
    class ShardDefinition {}');
}//end if

// -----------------------------------------------------------------
// OC\Encryption\Exceptions\*
// -----------------------------------------------------------------
if (class_exists(\OC\Encryption\Exceptions\ModuleAlreadyExistsException::class) === false) {
	eval('namespace OC\Encryption\Exceptions;
    class ModuleAlreadyExistsException extends \RuntimeException {}');
}//end if
if (class_exists(\OC\Encryption\Exceptions\ModuleDoesNotExistsException::class) === false) {
	eval('namespace OC\Encryption\Exceptions;
    class ModuleDoesNotExistsException extends \RuntimeException {}');
}//end if

// -----------------------------------------------------------------
// OC\FullTextSearch\Model\IndexDocument
// -----------------------------------------------------------------
if (class_exists(\OC\FullTextSearch\Model\IndexDocument::class) === false) {
	eval('namespace OC\FullTextSearch\Model;
    class IndexDocument {}');
}//end if

// -----------------------------------------------------------------
// OC\Route\Router
// -----------------------------------------------------------------
if (class_exists(\OC\Route\Router::class) === false) {
	eval('namespace OC\Route;
    class Router {}');
}//end if

// -----------------------------------------------------------------
// OC\Security\*
// -----------------------------------------------------------------
if (class_exists(\OC\Security\CSP\ContentSecurityPolicyManager::class) === false) {
	eval('namespace OC\Security\CSP;
    class ContentSecurityPolicyManager {}');
}//end if
if (class_exists(\OC\Security\CSRF\CsrfTokenManager::class) === false) {
	eval('namespace OC\Security\CSRF;
    class CsrfTokenManager {}');
}//end if
if (class_exists(\OC\Security\FeaturePolicy\FeaturePolicyManager::class) === false) {
	eval('namespace OC\Security\FeaturePolicy;
    class FeaturePolicyManager {}');
}//end if
if (class_exists(\OC\Security\RateLimiting\Exception\RateLimitExceededException::class) === false) {
	eval('namespace OC\Security\RateLimiting\Exception;
    class RateLimitExceededException extends \RuntimeException {}');
}//end if
if (class_exists(\OC\Security\RateLimiting\Limiter::class) === false) {
	eval('namespace OC\Security\RateLimiting;
    class Limiter {}');
}//end if

// -----------------------------------------------------------------
// OC\Share20\Exception\ProviderException
// -----------------------------------------------------------------
if (class_exists(\OC\Share20\Exception\ProviderException::class) === false) {
	eval('namespace OC\Share20\Exception;
    class ProviderException extends \RuntimeException {}');
}//end if

// -----------------------------------------------------------------
// OC\Streamer
// -----------------------------------------------------------------
if (class_exists(\OC\Streamer::class) === false) {
	eval('namespace OC;
    class Streamer {}');
}//end if

// -----------------------------------------------------------------
// OC\Core\ResponseDefinitions
// -----------------------------------------------------------------
if (class_exists(\OC\Core\ResponseDefinitions::class) === false) {
	eval('namespace OC\Core;
    class ResponseDefinitions {}');
}//end if

// -----------------------------------------------------------------
// OC\Files\AppData\Factory  (used in CacheSettingsControllerTest)
// -----------------------------------------------------------------
if (class_exists(\OC\Files\AppData\Factory::class) === false) {
	eval('namespace OC\Files\AppData;
    class Factory {
        public function get(string $app): ?\OCP\Files\IAppData { return null; }
    }');
}//end if

// -----------------------------------------------------------------
// OCA\DAV  — stub classes for CalDAV and CardDAV backends
// These are only needed by PHPUnit's mock generator (createMock) so
// an empty class body is sufficient.
// -----------------------------------------------------------------
if (class_exists(\OCA\DAV\CalDAV\CalDavBackend::class) === false) {
	eval('namespace OCA\DAV\CalDAV;
    class CalDavBackend {
        public function getCalendarsForUser(string $principal): array { return []; }
        public function createCalendar(string $principal, string $name, array $properties): int { return 0; }
        public function createCalendarObject(int $calendarId, string $objectUri, string $data): string { return ""; }
        public function getCalendarObjects(int $calendarId): array { return []; }
        public function getCalendarObject(int $calendarId, string $objectUri): ?array { return null; }
        public function deleteCalendarObject(int $calendarId, string $objectUri): void {}
        public function updateCalendarObject(int $calendarId, string $objectUri, string $data): string { return ""; }
    }');
}//end if

if (class_exists(\OCA\DAV\CardDAV\CardDavBackend::class) === false) {
	eval('namespace OCA\DAV\CardDAV;
    class CardDavBackend {
        public function getAddressBooksForUser(string $principal): array { return []; }
        public function createAddressBook(string $principal, string $name, array $properties): int { return 0; }
        public function createCard(int $addressBookId, string $cardUri, string $data): string { return ""; }
        public function getCards(int $addressBookId): array { return []; }
        public function getCard(int $addressBookId, string $cardUri) { return null; }
        public function deleteCard(int $addressBookId, string $cardUri): void {}
        public function updateCard(int $addressBookId, string $cardUri, string $data): string { return ""; }
        public function getAddressBook(int $addressBookId): ?array { return null; }
        // ContactService::getContactsForObject() calls this to resolve an
        // addressbook uri for the deep link. It was missing from the stub, so
        // the tests that mock it failed with MethodCannotBeConfiguredException
        // rather than exercising the behaviour.
        public function getAddressBookById(int $addressBookId): ?array { return null; }
        public function getAddressBooksForPrincipal(string $principal): array { return []; }
    }');
}//end if
