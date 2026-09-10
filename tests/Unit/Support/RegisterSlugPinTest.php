<?php

/**
 * No code under lib/ pins a superseded register slug.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Support\RegisterSlugAliases;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The case that has never once been caught.
 *
 * ## What this guard does NOT catch
 *
 * It reads lines, not data flow. A superseded slug that arrives from app config,
 * from a manifest, or through more than one assignment is invisible to it, and so
 * is a `match` arm built at run time. That is why the behavioural tests in
 * ScheduleReconcilerIoTest exist alongside it: this guard stops the literal being
 * TYPED, and those stop the resolved slug being IGNORED. Measured on the mutation
 * that reinstated the literal, the unmigrated-instance test still passed, because
 * on that instance the pinned literal happens to be the right answer. Only the
 * migrated case failed, and only the migrated case has ever mattered.
 *
 * A consumer pinned to a superseded register slug on a MIGRATED instance does
 * not raise. `openregister_registers` has no row with that slug, so the read
 * matches nothing and returns an empty result set, which is byte-for-byte what
 * a healthy, empty register returns. There is no exception, no 404, no log line
 * that distinguishes the two. Every existing guard in this repository watches
 * behaviour, and this defect has no behaviour to watch: it is a feature that
 * quietly stops happening.
 *
 * So the guard is static, and it is repo-wide rather than diff-scoped. Diff
 * scope is right for debt a PR could reasonably be asked to carry; it is wrong
 * here, because every one of these references was written BEFORE the slug was
 * renamed and will therefore never appear in a diff. A diff-scoped version of
 * this test passes on a repository full of the defect.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
class RegisterSlugPinTest extends TestCase {

	/**
	 * Files allowed to name a superseded slug, and why.
	 *
	 * Each entry is a genuine exception, not a deferral: these files exist in
	 * order to name the old slug. Anything else added here should instead be
	 * calling the resolver.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [
		'lib/Support/RegisterSlugAliases.php' => 'the declared alias map itself',
		'lib/Support/FleetAppId.php'          => 'app ids, not register slugs; a different question with a different source of truth',
	];

	/**
	 * Source patterns that put a string literal in REGISTER position.
	 *
	 * Deliberately narrow. A slug is only a defect where it identifies a
	 * register; the same word in a log message, a transport name or an app id
	 * is not this defect and a guard that flagged it would be turned off.
	 *
	 * @var list<string>
	 */
	private const REGISTER_POSITION = [
		'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
	];

	/**
	 * No file under lib/ names a superseded register slug in register position.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testNoSourceFilePinsASupersededRegisterSlug(): void {
		$superseded = RegisterSlugAliases::supersededSlugs();
		$this->assertNotSame([], $superseded, 'The alias map must know at least one rename, or this guard proves nothing.');

		$findings = [];
		foreach ($this->sourceFiles() as $relative => $absolute) {
			if (isset(self::ALLOWED[$relative]) === true) {
				continue;
			}

			$lines = file($absolute, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
			if ($lines === false) {
				continue;
			}

			foreach ($lines as $index => $line) {
				foreach (self::REGISTER_POSITION as $pattern) {
					if (preg_match($pattern, $line, $matches) !== 1) {
						continue;
					}

					$slug = strtolower($matches[1]);
					if (isset($superseded[$slug]) === false) {
						continue;
					}

					$findings[] = sprintf(
						'%s:%d pins the superseded register slug \'%s\'. Resolve \'%s\' through '
						. 'RegisterSlugResolverInterface::resolve() instead, and branch on '
						. 'isResolved(), because reading with a slug this instance does not carry '
						. 'returns zero rows, not an error.',
						$relative,
						($index + 1),
						$slug,
						$superseded[$slug]
					);
				}
			}
		}

		$this->assertSame([], $findings, "Superseded register slugs are pinned:\n" . implode("\n", $findings));
	}//end testNoSourceFilePinsASupersededRegisterSlug()

	/**
	 * The guard actually looks at something.
	 *
	 * A file walker that silently finds no files is the classic hollow green:
	 * the assertion above would pass on an empty list forever. This pins the
	 * walker to a floor well below the real count, so a broken path fails here
	 * rather than passing there.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testTheGuardScansTheSourceTree(): void {
		$files = $this->sourceFiles();

		$this->assertGreaterThan(500, count($files), 'The walker must see lib/, or the guard above cannot fail.');
		$this->assertArrayHasKey(
			'lib/AppHost/Scheduling/ScheduleReconciler.php',
			$files,
			'The reconciler is the file this guard was written for; the walker must reach it.'
		);
	}//end testTheGuardScansTheSourceTree()

	/**
	 * The patterns match a pinned slug when one is present.
	 *
	 * Watched failing is not enough on its own once the tree is clean: from
	 * then on the guard passes whether or not its regexes still work. This
	 * feeds each register-position form a known-bad line and requires a match,
	 * so a regex that stops matching reddens immediately.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testEachRegisterPositionPatternStillMatches(): void {
		$samples = [
			'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/' => "\$objectService->setRegister('voorzieningen');",
			'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/'                    => "\$svc->find(id: \$id, register: 'openconnector', schema: 'source');",
			'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/'              => "'filters' => ['register' => 'openbuild', 'schema' => 'application'],",
			'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\tprivate const OB_REGISTER_SLUG = 'openbuild';",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$register = 'openbuild';",
		];

		$superseded = RegisterSlugAliases::supersededSlugs();

		foreach (self::REGISTER_POSITION as $pattern) {
			$this->assertArrayHasKey($pattern, $samples, 'Every register-position pattern needs a known-bad sample.');
			$this->assertSame(
				1,
				preg_match($pattern, $samples[$pattern], $matches),
				'Pattern must match its known-bad sample: ' . $pattern
			);
			$this->assertArrayHasKey(
				strtolower($matches[1]),
				$superseded,
				'The sample must capture a slug the alias map calls superseded: ' . $pattern
			);
		}
	}//end testEachRegisterPositionPatternStillMatches()

	/**
	 * Every PHP file under lib/, keyed by repository-relative path.
	 *
	 * @return array<string, string> Relative path => absolute path.
	 */
	private function sourceFiles(): array {
		$root = dirname(__DIR__, 3);
		$lib = $root . '/lib';

		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if (($file instanceof SplFileInfo) === false || $file->isFile() === false) {
				continue;
			}

			if ($file->getExtension() !== 'php') {
				continue;
			}

			$path = $file->getPathname();
			$files[ltrim(str_replace($root, '', $path), '/')] = $path;
		}

		return $files;
	}//end sourceFiles()
}//end class
