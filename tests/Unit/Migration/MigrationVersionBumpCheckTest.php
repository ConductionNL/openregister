<?php

/**
 * The gate that keeps a migration and the app version together.
 *
 * WHY THIS TEST BUILDS ITS OWN REPOSITORIES. The thing under test is a
 * statement about a git diff, so a test that inspects THIS repository can only
 * report whatever today's branch happens to look like: it would pass on a
 * branch with no migrations for a reason that has nothing to do with the check
 * working. Each case below lays down a small repository with a known history,
 * so the red cases are red because the check found the defect and the green
 * cases are green because there was none.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Exercises scripts/check-migration-version-bump.php over synthetic repositories.
 *
 * @package OCA\OpenRegister\Tests\Unit\Migration
 */
class MigrationVersionBumpCheckTest extends TestCase {

	/**
	 * Absolute path of the fixture repository for the running test.
	 *
	 * @var string|null
	 */
	private ?string $repo = null;

	/**
	 * Remove the fixture repository.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->repo !== null && is_dir($this->repo) === true) {
			exec('rm -rf ' . escapeshellarg($this->repo));
		}

		$this->repo = null;
		parent::tearDown();
	}

	/**
	 * Absolute path to the script under test.
	 *
	 * @return string
	 */
	private function script(): string {
		return dirname(__DIR__, 3) . '/scripts/check-migration-version-bump.php';
	}

	/**
	 * Create a repository holding an info.xml at $version and one migration,
	 * committed on a branch named `development`.
	 *
	 * @param string $version The starting app version.
	 *
	 * @return string The repository path.
	 */
	private function repoAtVersion(string $version): string {
		$dir = sys_get_temp_dir() . '/or-migration-gate-' . bin2hex(random_bytes(6));
		mkdir($dir . '/appinfo', 0777, true);
		mkdir($dir . '/lib/Migration', 0777, true);

		file_put_contents($dir . '/appinfo/info.xml', $this->infoXml($version));
		file_put_contents($dir . '/lib/Migration/Version1Date20260101000000.php', "<?php\n// base migration\n");
		file_put_contents($dir . '/README.md', "base\n");

		$this->sh('git init -q -b development', $dir);
		$this->sh('git config user.email t@example.org', $dir);
		$this->sh('git config user.name Test', $dir);
		$this->sh('git config commit.gpgsign false', $dir);
		$this->sh('git add -A', $dir);
		$this->sh('git commit -q -m base', $dir);
		$this->sh('git checkout -q -b feature', $dir);

		$this->repo = $dir;
		return $dir;
	}

	/**
	 * Minimal info.xml carrying a version.
	 *
	 * @param string $version The version.
	 *
	 * @return string
	 */
	private function infoXml(string $version): string {
		return "<?xml version=\"1.0\"?>\n<info>\n    <id>openregister</id>\n    <version>" . $version . "</version>\n</info>\n";
	}

	/**
	 * Run a shell command inside a directory, ignoring its output.
	 *
	 * @param string $cmd The command.
	 * @param string $cwd The working directory.
	 *
	 * @return void
	 */
	private function sh(string $cmd, string $cwd): void {
		exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' >/dev/null 2>&1');
	}

	/**
	 * Invoke the check against a fixture repository.
	 *
	 * @param string $dir  The repository.
	 * @param string $base The base ref to compare against.
	 *
	 * @return array{code: int, output: string}
	 */
	private function check(string $dir, string $base = 'development'): array {
		$cmd = 'php ' . escapeshellarg($this->script())
			. ' --repo=' . escapeshellarg($dir)
			. ' --base=' . escapeshellarg($base) . ' 2>&1';

		$out  = [];
		$code = 0;
		exec($cmd, $out, $code);

		return [
			'code'   => $code,
			'output' => implode("\n", $out),
		];
	}

	/**
	 * Add a migration file and commit it.
	 *
	 * @param string $dir  The repository.
	 * @param string $name The class/file name without extension.
	 *
	 * @return void
	 */
	private function addMigration(string $dir, string $name = 'Version1Date20260906090000'): void {
		file_put_contents($dir . '/lib/Migration/' . $name . '.php', "<?php\n// added\n");
		$this->sh('git add -A', $dir);
		$this->sh('git commit -q -m "add migration"', $dir);
	}

	/**
	 * THE DEFECT. A migration is added and the version is left alone.
	 *
	 * @return void
	 */
	public function testAddingAMigrationWithoutBumpingTheVersionFails(): void {
		$dir = $this->repoAtVersion('2.0.14');
		$this->addMigration($dir);

		$result = $this->check($dir);

		$this->assertSame(1, $result['code'], "expected a violation, got:\n" . $result['output']);
		$this->assertStringContainsString(
			'lib/Migration/Version1Date20260906090000.php',
			$result['output'],
			'the failure must name the migration it found, not just fail'
		);
		$this->assertStringContainsString('2.0.14', $result['output']);
	}

	/**
	 * THE FIX. The same change with the version moved passes.
	 *
	 * @return void
	 */
	public function testAddingAMigrationWithAVersionBumpPasses(): void {
		$dir = $this->repoAtVersion('2.0.14');
		file_put_contents($dir . '/lib/Migration/Version1Date20260906090000.php', "<?php\n// added\n");
		file_put_contents($dir . '/appinfo/info.xml', $this->infoXml('2.0.15'));
		$this->sh('git add -A', $dir);
		$this->sh('git commit -q -m "add migration and bump"', $dir);

		$result = $this->check($dir);

		$this->assertSame(0, $result['code'], "expected a pass, got:\n" . $result['output']);
	}

	/**
	 * A prerelease version moves the way version_compare reads it, and the
	 * fleet's release bumps all look like this. `2.0.14-unstable.<stamp>` ->
	 * `2.0.15-unstable.<stamp>` must count as a move.
	 *
	 * @return void
	 */
	public function testAPrereleaseBumpCounts(): void {
		$dir = $this->repoAtVersion('2.0.14-unstable.20260901212512');
		file_put_contents($dir . '/lib/Migration/Version1Date20260906090000.php', "<?php\n// added\n");
		file_put_contents($dir . '/appinfo/info.xml', $this->infoXml('2.0.15-unstable.20260905134511'));
		$this->sh('git add -A', $dir);
		$this->sh('git commit -q -m "add migration and bump"', $dir);

		$this->assertSame(0, $this->check($dir)['code']);
	}

	/**
	 * THE CONTROL. Without a new migration the check has nothing to say, even
	 * though the version has not moved — so a pass above means the check looked
	 * and found nothing, not that it always passes.
	 *
	 * @return void
	 */
	public function testChangingOtherFilesWithoutAMigrationPasses(): void {
		$dir = $this->repoAtVersion('2.0.14');
		file_put_contents($dir . '/README.md', "edited\n");
		file_put_contents($dir . '/lib/Migration/Version1Date20260101000000.php', "<?php\n// edited in place\n");
		$this->sh('git add -A', $dir);
		$this->sh('git commit -q -m "edit only"', $dir);

		$result = $this->check($dir);

		$this->assertSame(0, $result['code'], "expected a pass, got:\n" . $result['output']);
		$this->assertStringContainsString('nothing to check', $result['output']);
	}

	/**
	 * A version that moves BACKWARDS is not a bump. Nextcloud compares with
	 * version_compare, so a lower version is as inert as an equal one.
	 *
	 * @return void
	 */
	public function testLoweringTheVersionIsNotABump(): void {
		$dir = $this->repoAtVersion('2.0.14');
		file_put_contents($dir . '/lib/Migration/Version1Date20260906090000.php', "<?php\n// added\n");
		file_put_contents($dir . '/appinfo/info.xml', $this->infoXml('2.0.13'));
		$this->sh('git add -A', $dir);
		$this->sh('git commit -q -m "add migration, lower version"', $dir);

		$this->assertSame(1, $this->check($dir)['code']);
	}

	/**
	 * The uncommitted case, which is where a developer meets this first: an
	 * untracked migration in the working tree counts as added.
	 *
	 * @return void
	 */
	public function testAnUntrackedMigrationInTheWorkingTreeCounts(): void {
		$dir = $this->repoAtVersion('2.0.14');
		file_put_contents($dir . '/lib/Migration/Version1Date20260906090000.php', "<?php\n// not committed yet\n");

		$result = $this->check($dir);

		$this->assertSame(1, $result['code'], "expected a violation, got:\n" . $result['output']);
		$this->assertStringContainsString('Version1Date20260906090000.php', $result['output']);
	}

	/**
	 * A base ref it cannot resolve gets exit 2, never exit 0. A check that
	 * cannot see the base has no verdict, and reporting a pass there is the
	 * silent-skip failure this whole gate exists to remove.
	 *
	 * @return void
	 */
	public function testAnUnresolvableBaseRefRefusesToGiveAVerdict(): void {
		$dir = $this->repoAtVersion('2.0.14');
		$this->addMigration($dir);

		$result = $this->check($dir, 'no-such-branch');

		$this->assertSame(2, $result['code'], "expected 'no verdict', got:\n" . $result['output']);
		$this->assertStringContainsString('no verdict', $result['output']);
	}

	/**
	 * A directory that is not a git repository gets exit 2 for the same reason.
	 *
	 * @return void
	 */
	public function testANonRepositoryRefusesToGiveAVerdict(): void {
		$dir = sys_get_temp_dir() . '/or-migration-gate-notarepo-' . bin2hex(random_bytes(6));
		mkdir($dir, 0777, true);
		$this->repo = $dir;

		$this->assertSame(2, $this->check($dir)['code']);
	}
}
