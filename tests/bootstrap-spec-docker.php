<?php
/**
 * Custom bootstrap to run spec-branch unit tests inside the Docker container
 * without touching the main `/var/www/html/custom_apps/openregister` mount.
 *
 * The trick: skip `OC_App::loadApps()` entirely, register our OWN PSR-4
 * autoloaders that resolve OCA\OpenRegister\* to the spec worktree at
 * /tmp/wt-or, plus a thin OC and OCP loader pointing at /var/www/html so
 * mocks of NC interfaces (IUserSession, IDBConnection, …) still resolve.
 *
 * Pure unit tests only — no DB, no NC service container, no logged-in user.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

// 1. Spec worktree composer autoloader (includes PHPUnit, GuzzleHttp, etc).
require_once '/tmp/wt-or/vendor/autoload.php';

// 2. NC 3rd-party composer autoloader (PSR, Symfony, Doctrine, etc).
if (file_exists('/var/www/html/3rdparty/autoload.php')) {
    require_once '/var/www/html/3rdparty/autoload.php';
}

// 3. OC core + OCP interfaces resolver — needed so createMock(IUserSession::class)
//    resolves the real interface from /var/www/html/lib.
spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'OC\\') === 0) {
        $file = '/var/www/html/lib/private/'.str_replace('\\', '/', substr($class, 3)).'.php';
        if (file_exists($file)) {
            require_once $file;
        }
        return;
    }

    if (strpos($class, 'OCP\\') === 0) {
        $file = '/var/www/html/lib/public/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (file_exists($file)) {
            require_once $file;
        }
        return;
    }
});

// 4. Spec-branch OpenRegister classes — MUST be the resolved version, not
//    the older one in /var/www/html/custom_apps/openregister.
spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'OCA\\OpenRegister\\') !== 0) {
        return;
    }

    $relative = substr($class, strlen('OCA\\OpenRegister\\'));

    // Tests\* lives under /tmp/wt-or/tests/.
    if (strpos($relative, 'Tests\\') === 0) {
        $relativePath = str_replace('\\', '/', substr($relative, strlen('Tests\\')));
        $file         = '/tmp/wt-or/tests/'.$relativePath.'.php';
    } else {
        $relativePath = str_replace('\\', '/', $relative);
        $file         = '/tmp/wt-or/lib/'.$relativePath.'.php';
    }

    if (file_exists($file)) {
        require_once $file;
    }
}, true, true);
