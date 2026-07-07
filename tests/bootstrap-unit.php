<?php
/**
 * Bootstrap file for Unit Tests
 *
 * This bootstrap loads the full Nextcloud environment since tests run inside
 * the Nextcloud Docker container. This gives access to \OC::$server and the
 * full DI container, enabling tests to cover code that depends on Nextcloud services.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Load minimal Doctrine DBAL stubs so nextcloud/ocp v31 interface constants
// (IQueryBuilder::PARAM_NULL = ParameterType::NULL, etc.) can be evaluated
// in the bare php:8.3-cli CI environment where doctrine/dbal is not installed.
require_once __DIR__ . '/stubs/DoctrineDbalStubs.php';

// Load minimal Nextcloud internal-class stubs (OC\Hooks\Emitter, etc.) that
// the nextcloud/ocp v31 stubs reference but are not shipped by the OCP package.
require_once __DIR__ . '/stubs/NextcloudInternalStubs.php';

// Load the Doriath contract stubs + test fixtures for the credential-broker
// Doriath custody leaf (class_exists-guarded — a real Doriath install wins).
require_once __DIR__ . '/stubs/DoriathStubs.php';

// Bootstrap Nextcloud — since we run inside the Docker container,
// the full environment (including \OC::$server) is available.
if (file_exists(__DIR__ . '/../../../lib/base.php')) {
    require_once __DIR__ . '/../../../lib/base.php';
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib)) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('Test\\', $serverTestsLib);
    $loader->register(true);
}

// Ensure OpenRegister's ZipStream v3 wins over a co-installed forms app's
// ZipStream v2 vendor copy. In some dev/CI containers, several apps register
// PSR4 prefixes for `ZipStream\`; whichever registers first wins for any class
// that exists in its tree. The forms app's v2 copy ships `ZipStream\Option\Archive`,
// which makes PhpSpreadsheet's writer dispatcher (ZipStream0) pick the
// `ZipStream2::newZipStream` path. That path calls `new ZipStream(null, $options)`,
// but our installed ZipStream is v3, whose constructor requires
// `ZipStream\OperationMode` as the first argument — leading to spurious
// `Argument #1 ($operationMode) must be of type ZipStream\OperationMode, null given`
// TypeErrors in any test that writes an Xlsx file. Dropping the forms-app
// prefix entry forces the dispatcher to take the ZipStream3 (v3-native) path.
foreach (spl_autoload_functions() as $autoload) {
    if (is_array($autoload) === true
        && is_object($autoload[0]) === true
        && get_class($autoload[0]) === \Composer\Autoload\ClassLoader::class
    ) {
        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader   = $autoload[0];
        $prefixes = $loader->getPrefixesPsr4();
        if (isset($prefixes['ZipStream\\']) === true) {
            $kept = [];
            $dropped = false;
            foreach ($prefixes['ZipStream\\'] as $path) {
                $real = realpath($path);
                if ($real !== false && strpos($real, '/custom_apps/forms/') !== false) {
                    $dropped = true;
                    continue;
                }
                $kept[] = $path;
            }
            if ($dropped === true) {
                $loader->setPsr4('ZipStream\\', $kept);
            }
        }

        // The forms app also ships pre-generated classmap entries pointing at
        // its v2 vendor copy. Wipe any classmap entry whose target sits under
        // the forms vendor tree for ZipStream classes — these win over PSR4.
        $classMap = $loader->getClassMap();
        $toUnset  = [];
        foreach ($classMap as $class => $path) {
            if (strncmp($class, 'ZipStream\\', 10) !== 0) {
                continue;
            }
            $real = realpath($path);
            if ($real !== false && strpos($real, '/custom_apps/forms/') !== false) {
                $toUnset[] = $class;
            }
        }
        if (count($toUnset) > 0) {
            $cleaned = $classMap;
            foreach ($toUnset as $class) {
                unset($cleaned[$class]);
            }
            // Re-seed the classmap (addClassMap merges, so we have to wipe via reflection).
            $rp = new \ReflectionProperty(\Composer\Autoload\ClassLoader::class, 'classMap');
            $rp->setAccessible(true);
            $rp->setValue($loader, $cleaned);
        }
    }
}

// Bootstrap message suppressed: error_log() writes to STDERR and PHPUnit's
// beStrictAboutOutputDuringTests mode treats any output during the bootstrap
// as a test error (PHPUnit\Framework\Exception).  The bootstrap runs once and
// the message is only useful during active debugging.
