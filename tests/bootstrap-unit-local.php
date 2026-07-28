<?php
/**
 * Local bootstrap for unit tests that do not need the Nextcloud runtime.
 *
 * Loads Composer's autoloader and registers a PSR-4 path-based autoloader
 * for the `nextcloud/ocp` stub package — which ships PHP interfaces but does
 * not declare its own PSR-4 entry, expecting NC's `lib/base.php` to wire it.
 * Also provides minimal in-process class stubs for `Doctrine\DBAL\*` symbols
 * that `OCP\DB\QueryBuilder\IQueryBuilder` references (`ParameterType`,
 * `ArrayParameterType`, `Connection`, `Types`) — these aren't shipped in
 * vendor/ for production builds. Suitable for pure mockist unit tests of
 * service / handler / mapper-adapter classes that depend on OCP/NCU
 * interfaces but never touch the live DI container.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP + NCU stub namespaces from nextcloud/ocp. The stub package
// ships PHP files but no PSR-4 in its own composer.json — outside the NC
// runtime nothing registers them. Add the path manually so PHPUnit's mock
// generator can resolve interfaces like OCP\IUserSession.
$ocpStubBase = __DIR__ . '/../vendor/nextcloud/ocp';
if (is_dir($ocpStubBase . '/OCP') === true) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('OCP\\', $ocpStubBase . '/OCP');
    $loader->addPsr4('NCU\\', $ocpStubBase . '/NCU');
    $loader->register(true);
}

// Internal (non-OCP) Nextcloud symbols referenced by OCP stub files at parse
// time (e.g. OCP\Files\IRootFolder extends OC\Hooks\Emitter). The stub file
// guards every definition with interface_exists/class_exists, so loading it
// here is idempotent and order-independent — without it, any test that mocks
// IRootFolder fails unless another suite happened to load the stub first.
require_once __DIR__ . '/stubs/NextcloudInternalStubs.php';

// Doriath custody-leaf contract stubs + test fixtures (credential-doriath-leaf).
// PART 1 is class_exists-guarded (the real OCA\Doriath\* classes win whenever the
// Doriath app is autoloadable), PART 2 declares the always-present
// OCA\OpenRegister\Tests\Fixtures\Doriath\* recording fakes that the credential
// Doriath-store unit tests inject through the production classes' protected seams.
// Loading it here (never via composer autoload — see the file header) is what makes
// DoriathCredentialStoreTest / CredentialStoreResolverTest resolve their fixtures.
require_once __DIR__ . '/stubs/DoriathStubs.php';

// Minimal in-process Doctrine\DBAL\* stubs.
// OCP\DB\QueryBuilder\IQueryBuilder references ParameterType::*, Types::*,
// ArrayParameterType::*, and Connection::* class constants at parse time
// (used for interface-constant defaults). doctrine/dbal is not shipped in
// vendor/ for runtime, so the autoloader can't find these. Defining the
// classes here is sufficient: only the constants are read; behaviour is
// never invoked from tests.
if (class_exists('Doctrine\\DBAL\\ParameterType', false) === false) {
    eval(
        'namespace Doctrine\\DBAL {
            class ParameterType {
                const NULL = 0;
                const INTEGER = 1;
                const STRING = 2;
                const LARGE_OBJECT = 3;
                const BOOLEAN = 5;
                const BINARY = 16;
                const ASCII = 17;
            }
            class ArrayParameterType {
                const INTEGER = 101;
                const STRING = 102;
                const ASCII = 117;
                const BINARY = 116;
            }
            class Connection {
                const PARAM_INT_ARRAY = 101;
                const PARAM_STR_ARRAY = 102;
            }
        }'
    );
}

if (class_exists('Doctrine\\DBAL\\Types\\Types', false) === false) {
    eval(
        'namespace Doctrine\\DBAL\\Types {
            class Types {
                const ARRAY = "array";
                const ASCII_STRING = "ascii_string";
                const BIGINT = "bigint";
                const BINARY = "binary";
                const BLOB = "blob";
                const BOOLEAN = "boolean";
                const DATE_MUTABLE = "date";
                const DATE_IMMUTABLE = "date_immutable";
                const DATEINTERVAL = "dateinterval";
                const DATETIME_MUTABLE = "datetime";
                const DATETIME_IMMUTABLE = "datetime_immutable";
                const DATETIMETZ_MUTABLE = "datetimetz";
                const DATETIMETZ_IMMUTABLE = "datetimetz_immutable";
                const DECIMAL = "decimal";
                const FLOAT = "float";
                const GUID = "guid";
                const INTEGER = "integer";
                const JSON = "json";
                const OBJECT = "object";
                const SIMPLE_ARRAY = "simple_array";
                const SMALLINT = "smallint";
                const STRING = "string";
                const TEXT = "text";
                const TIME_MUTABLE = "time";
                const TIME_IMMUTABLE = "time_immutable";
            }
        }'
    );
}

// OCP\DB\QueryBuilder\IExpressionBuilder uses constant references from Doctrine's ExpressionBuilder.
// Without this stub, PHPUnit cannot create mocks of IExpressionBuilder in local tests.
if (class_exists('Doctrine\\DBAL\\Query\\Expression\\ExpressionBuilder', false) === false) {
    eval(
        'namespace Doctrine\\DBAL\\Query\\Expression {
            class ExpressionBuilder {
                const EQ  = "=";
                const NEQ = "<>";
                const LT  = "<";
                const LTE = "<=";
                const GT  = ">";
                const GTE = ">=";
            }
        }'
    );
}
