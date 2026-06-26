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

// Remove the vendored nextcloud/ocp stub paths from the PSR4 map AND classmap
// BEFORE loading base.php. The v31 stubs ship an older OCP\ICertificateManager
// that lacks getDefaultCertificatesBundlePath(). NC 34's concrete
// OC\Security\CertificateManager uses #[\Override] on that method. When PHP 8.4
// loads OCP\ICertificateManager from the v31 stub (which doesn't declare the
// method) and then loads OC\Security\CertificateManager (which uses #[\Override]
// on getDefaultCertificatesBundlePath()), it raises a fatal error because
// #[\Override] cannot find a matching parent method.
//
// Composer generates a classmap that takes priority over PSR4 prefixes, so we
// must purge BOTH the PSR4 prefix entries AND all classmap entries whose target
// lives under the vendored nextcloud/ocp tree.
$vendorOcpRoot = realpath(__DIR__ . '/../vendor/nextcloud/ocp');
foreach (spl_autoload_functions() as $autoloadFn) {
    if (is_array($autoloadFn) === true
        && is_object($autoloadFn[0]) === true
        && get_class($autoloadFn[0]) === \Composer\Autoload\ClassLoader::class
    ) {
        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = $autoloadFn[0];

        // 1. Remove PSR4 prefixes that point into the vendored OCP stub directory.
        $prefixes = $loader->getPrefixesPsr4();
        foreach (['OCP\\', 'NCU\\'] as $ns) {
            if (isset($prefixes[$ns]) === true) {
                $kept = [];
                foreach ($prefixes[$ns] as $path) {
                    $real = realpath($path);
                    if ($real !== false
                        && $vendorOcpRoot !== false
                        && strncmp($real, $vendorOcpRoot, strlen($vendorOcpRoot)) === 0
                    ) {
                        continue;
                    }
                    $kept[] = $path;
                }
                $loader->setPsr4($ns, $kept);
            }
        }//end foreach

        // 2. Remove classmap entries whose file lives under the vendored OCP stub
        //    directory (these win over PSR4 and would re-introduce the stale stubs).
        if ($vendorOcpRoot !== false) {
            $classMap = $loader->getClassMap();
            $toUnset  = [];
            foreach ($classMap as $class => $filePath) {
                $real = realpath($filePath);
                if ($real !== false
                    && strncmp($real, $vendorOcpRoot, strlen($vendorOcpRoot)) === 0
                ) {
                    $toUnset[] = $class;
                }
            }
            if (count($toUnset) > 0) {
                $cleaned = $classMap;
                foreach ($toUnset as $class) {
                    unset($cleaned[$class]);
                }
                $rp = new \ReflectionProperty(\Composer\Autoload\ClassLoader::class, 'classMap');
                $rp->setAccessible(true);
                $rp->setValue($loader, $cleaned);
            }
        }//end if
    }//end if
}//end foreach

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

// Keep a lightweight marker for debugging; write to stdout (not stderr) so
// PHPUnit's @runInSeparateProcess harness does not mistake it for an error.
// (PHPUnit\Framework\Exception is thrown when the subprocess has any stderr output.)
if (PHP_SAPI === 'cli' && getenv('PHPUNIT_BOOTSTRAP_VERBOSE') !== false && getenv('PHPUNIT_BOOTSTRAP_VERBOSE') !== '') {
    echo '[UNIT TEST BOOTSTRAP] Full Nextcloud bootstrap complete - \OC::$server available' . PHP_EOL;
}
