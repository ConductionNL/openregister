<?php
/**
 * Bootstrap file for PHPUnit tests
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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

/**
 * Resolve the Nextcloud installation root.
 *
 * Priority:
 *   1. `OPENREGISTER_TEST_NC_ROOT` env var (explicit override; useful for
 *      CI and parallel agents running from git worktrees outside the
 *      standard `apps-extra/openregister/` layout).
 *   2. Walk up from this file looking for a `lib/base.php` whose
 *      parent also looks like an NC root (must have `apps/` and
 *      `core/` siblings — the source tree shape, not just any random
 *      `lib/base.php`).
 *   3. Legacy fallback: `__DIR__ . '/../../../'` (the original behaviour
 *      when openregister is checked out under `apps-extra/`).
 *
 * Returns null when no candidate matches; pure unit tests that don't
 * need the NC container still run via the composer autoloader above.
 *
 * @return string|null Absolute path to the NC root, or null when not found.
 */
function openregister_locate_nc_root(): ?string
{
    $explicit = getenv('OPENREGISTER_TEST_NC_ROOT');
    if (is_string($explicit) === true && $explicit !== '' && is_file($explicit . '/lib/base.php') === true) {
        return rtrim($explicit, '/');
    }

    $dir = __DIR__;
    for ($depth = 0; $depth < 8; $depth++) {
        $dir = dirname($dir);
        if ($dir === '/' || $dir === '' || $dir === '.') {
            break;
        }

        // Identify the NC source/install root by the sibling layout —
        // `lib/base.php` plus the documented top-level dirs. Tolerates
        // both the source-tree layout (with `tests/`) and a deployed
        // install (without `tests/`).
        if (is_file($dir . '/lib/base.php') === true
            && is_dir($dir . '/apps') === true
            && is_dir($dir . '/core') === true
        ) {
            return $dir;
        }
    }

    return null;
}

// Bootstrap Nextcloud if not already done. Caller can opt out via
// OPENREGISTER_TEST_SKIP_NC=1 to force pure-unit mode (e.g. parallel
// agents in detached worktrees that don't have a writable NC instance
// to point at).
$skipNc = getenv('OPENREGISTER_TEST_SKIP_NC');
$skipNc = is_string($skipNc) === true && filter_var($skipNc, FILTER_VALIDATE_BOOLEAN) === true;

if ($skipNc === false && !defined('OC_CONSOLE')) {
    $ncRoot = openregister_locate_nc_root();

    if ($ncRoot !== null) {
        try {
            require_once $ncRoot . '/lib/base.php';

            // Source-tree only: NC's PHPUnit harness adds `Test\TestCase`
            // and friends. Deployed installs don't ship the tests dir,
            // so pull it in only when present.
            if (is_file($ncRoot . '/tests/autoload.php') === true) {
                require_once $ncRoot . '/tests/autoload.php';
            }

            // Load all enabled apps.
            \OC_App::loadApps();

            // Load our specific app.
            \OC_App::loadApp('openregister');

            // Clear hooks for testing.
            OC_Hook::clear();
        } catch (\Throwable $e) {
            // The NC root we found exists but isn't installed (e.g. a
            // bare server checkout used as the parent of multiple
            // worktrees). Fall through to pure-unit mode rather than
            // aborting the test run — the failing message above is
            // less actionable than the bootstrap message below.
            fwrite(
                STDERR,
                sprintf(
                    "[openregister/tests/bootstrap] NC root at %s could not be initialised (%s).\n"
                    . "  Falling through to composer autoload only — pure unit tests will run; container-bound tests will fail clearly.\n"
                    . "  Set OPENREGISTER_TEST_SKIP_NC=1 to silence this and skip NC bootstrap entirely.\n",
                    $ncRoot,
                    $e->getMessage()
                )
            );
        }
    } else {
        // No NC root in scope — pure unit tests still work via the
        // composer autoloader already loaded above; tests that touch
        // the container will fail with a clear "OC server not
        // bootstrapped" error rather than the previous silent skip.
        fwrite(
            STDERR,
            "[openregister/tests/bootstrap] Nextcloud root not found; running with composer autoload only.\n"
            . "  Set OPENREGISTER_TEST_NC_ROOT to the NC server source root if you need integration/DB tests.\n"
        );
    }
}
