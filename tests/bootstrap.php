<?php

/**
 * Bootstrap file for PHPUnit tests
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

// Breadcrumb: the Doctrine-DBAL / NC-internal / OCP-fallback / Doriath stubs
// used to be loaded here, before the NC bootstrap. They now live BELOW the
// real-NC bootstrap block (see lines 132+); each stub is `class_exists()` /
// `interface_exists()` guarded and safely no-ops when the live NC already
// supplies the real class. Moving the loads down avoids the "Cannot declare
// class OC, because the name is already in use" race that could fire when a
// live NC beat the stubs to declaring `OC`.

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
function openregister_locate_nc_root(): ?string {
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

if ($skipNc === false && defined('OC_CONSOLE') === false) {
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

			// Say it once, then silence any child process — the same guard the
			// "no NC root" branch below already carries, which this branch was
			// missing.
			//
			// A `@runInSeparateProcess` test re-runs this bootstrap in a forked
			// PHPUnit worker, and ANY output from that worker corrupts the
			// channel PHPUnit uses to read the child's result back. The test
			// then fails with this very notice as its error message rather than
			// on its own merits. Observed on
			// AppHost\BootstrapTest::testSettingsPlaneRegistrationIsLazy, which
			// never touches the container and passes with the switch set.
			//
			// The child inherits this process's environment, so setting the
			// harness's existing skip switch here keeps the diagnostic for the
			// human running the suite while making every forked worker quiet.
			putenv('OPENREGISTER_TEST_SKIP_NC=1');
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

		// Say it once, then silence any child process.
		//
		// A `@runInSeparateProcess` test re-runs this bootstrap in a forked
		// PHPUnit worker, and ANY output from that worker corrupts the channel
		// PHPUnit uses to read the child's result back — the test then fails
		// with this very notice as its error message rather than on its own
		// merits (BootstrapTest::testRegistrationIsLazyAndDoesNotAutoloadGenerics).
		// The child inherits this process's environment, so setting the
		// harness's existing skip switch here keeps the diagnostic for the human
		// running the suite while making every forked worker quiet.
		putenv('OPENREGISTER_TEST_SKIP_NC=1');
	}
}

// Load minimal Doctrine DBAL stubs so nextcloud/ocp interface constants
// (IQueryBuilder::PARAM_NULL = ParameterType::NULL, etc.) can be evaluated in
// the bare php:8.3-cli CI environment where doctrine/dbal is not installed.
// The stubs are class_exists-guarded, so they are skipped when the real
// doctrine/dbal is present (e.g. inside a bootstrapped Nextcloud container).
// Mirrors tests/bootstrap-unit.php; without this, mocking IQueryBuilder in a
// pure-unit run fatals with "Class Doctrine\DBAL\ParameterType not found".
//
// Loaded AFTER the real-NC bootstrap attempt above (not before): every stub in
// this block and the two below is individually class_exists()/interface_exists()
// guarded against the REAL class, so ordering it after the real bootstrap lets
// those guards see the real classes when a live Nextcloud root was found and
// correctly no-op, instead of racing the real declarations and fataling with
// "Cannot declare class OC, because the name is already in use".
require_once __DIR__ . '/stubs/DoctrineDbalStubs.php';

// Load minimal Nextcloud internal-class stubs (OC\Hooks\Emitter, etc.) that
// the nextcloud/ocp stubs reference but the OCP package does not ship.
require_once __DIR__ . '/stubs/NextcloudInternalStubs.php';

// Register the nextcloud/ocp stubs for OCP\* / NCU\* — but ONLY here, in the test
// entry point, and only when no live Nextcloud already supplies them. Registered
// AFTER the Doctrine stubs above: IQueryBuilder declares constants that reference
// Doctrine\DBAL\ParameterType, evaluated the moment the file is parsed, so the
// placeholders must already be in the class table.
//
// This mapping must NEVER live in composer.json. `autoload-dev` IS baked into the
// generated autoloader by a plain `composer install`, and in the dev topology the
// app checkout IS the served app — Application.php requires vendor/autoload.php, so
// the stubs would shadow core's OCP on every request. With stubs pinned to a
// different Nextcloud major than the running server, core's `#[\Override]`
// attributes then have no matching parent method and PHP raises a COMPILE-TIME
// fatal that takes down the WHOLE instance (occ dead, 0 apps, every route 404/500).
// That is the 2026-07-12 outage. Static analysis does not need the mapping either:
// PHPStan reads the stubs via `scanDirectories`, Psalm via `<extraFiles>`.
if (interface_exists(\OCP\IUser::class) === false) {
	$ocpLoader = new \Composer\Autoload\ClassLoader();
	$ocpLoader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
	$ocpLoader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
	$ocpLoader->register();
}

// Load the Doriath contract stubs + test fixtures for the credential-broker
// Doriath custody leaf (class_exists-guarded — a real Doriath install wins).
require_once __DIR__ . '/stubs/DoriathStubs.php';

// Load the ContextChat contract stubs. `OCP\ContextChat\*` ships with the
// context_chat app, which is an OPTIONAL seam here (ContentProvider implements
// its interface, ContextChatService resolves the manager lazily) and therefore
// absent from a bare composer install — same situation as Doriath above.
// Guarded, so a real context_chat install always wins.
require_once __DIR__ . '/stubs/ContextChatStubs.php';
