<?php
/**
 * Lightweight bootstrap for Integration provider unit tests.
 *
 * The integration service classes (IntegrationProvider, ExternalIntegrationRouter,
 * OpenProjectProvider, IntegrationRegistry) do not require the full Nextcloud
 * server environment. This bootstrap loads only the Composer autoloader and
 * stubs the minimal OCP interfaces needed so PHPUnit mock creation works.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-6
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load the Nextcloud OCP stubs (PSR-4) so the full OCP interface surface
// — IQueryBuilder, IAppManager, App, etc. — is available for mocking and
// for constructing the Application class under reflection. Without this
// the bundled minimal IConfig stub shadows only a sliver of OCP and the
// real provider tests (which type-hint many OCP services) cannot mock them.
$ocpStubsDir = __DIR__ . '/../vendor/nextcloud/ocp';
if (is_dir($ocpStubsDir) === true) {
    $ocpLoader = new \Composer\Autoload\ClassLoader();
    $ocpLoader->addPsr4('OCP\\', $ocpStubsDir . '/OCP');
    $ocpLoader->register(true);
}

// OCP\DB\QueryBuilder\IQueryBuilder references Doctrine\DBAL\ParameterType,
// which is not a Composer dependency of this app but ships in the Nextcloud
// server's 3rdparty tree. Load it from whichever location is present so the
// query-builder type-hints resolve when mocking DB-backed providers.
foreach ([
    __DIR__ . '/../../../3rdparty/doctrine/dbal/src',
    '/var/www/html/3rdparty/doctrine/dbal/src',
] as $doctrineDbalDir) {
    if (is_dir($doctrineDbalDir) === true) {
        $dbalLoader = new \Composer\Autoload\ClassLoader();
        $dbalLoader->addPsr4('Doctrine\\DBAL\\', $doctrineDbalDir);
        $dbalLoader->register(true);
        break;
    }
}

// Minimal \OC stub. OCP\AppFramework\App::__construct() touches
// \OC::$server->getConfig()->getSystemValueBool('debug') purely for a
// dev-mode setup-warning. The DI factory test instantiates Application
// (an OCP\AppFramework\App) under reflection, so provide a no-op server
// whose config reports debug=false; nothing else is exercised.
if (class_exists('\OC', false) === false) {
    eval('class OC { public static $server; }');
    \OC::$server = new class {
        public function getConfig(): object
        {
            return new class {
                public function getSystemValueBool(string $key, bool $default = false): bool
                {
                    return false;
                }
            };
        }

        /**
         * Resolve a service id to a no-op double. Provider code reached
         * under unit test (e.g. MarkerLookupTrait's catch-block logging via
         * \OCP\Server::get(LoggerInterface)) only ever calls fire-and-forget
         * methods on the resolved service, so a magic no-op object suffices.
         */
        public function get(string $serviceName): object
        {
            return new class {
                public function __call(string $name, array $arguments): mixed
                {
                    return null;
                }
            };
        }

        /**
         * Return the registered per-app DI container. The DI factory test
         * constructs Application (an OCP App) only to capture the factory
         * closures it registers; the framework container itself is never
         * exercised, so a magic no-op object satisfies the constructor.
         */
        public function getRegisteredAppContainer(string $appName): object
        {
            return new class {
                public function __call(string $name, array $arguments): mixed
                {
                    return null;
                }
            };
        }
    };
}

// Stub minimal OCP\IConfig only when the OCP stubs are absent, so the
// fallback path keeps working in environments without the stub package.
if (interface_exists('\OCP\IConfig') === false) {
    eval('namespace OCP; interface IConfig { public function getSystemValue(string $valueName, mixed $default = ""): mixed; public function getSystemValueString(string $key, string $default = ""): string; public function getUserValue(string $userId, string $appName, string $key, string $default = ""): string; public function setUserValue(string $userId, string $appName, string $key, string $value): void; }');
}
