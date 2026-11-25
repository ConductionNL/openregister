<?php

/**
 * Every PHPStan baseline entry names a file that still exists.
 *
 * PHPStan is configured with `reportUnmatchedIgnoredErrors: false`, so an entry
 * whose file was deleted or moved stays in the baseline silently and makes the
 * tracked debt look larger than it is. The burn-down counts entries, so a stale
 * entry is a false number on the dashboard; this test keeps that part honest.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/openregister-legacy-quality-cleanup/tasks.md#phase-4--phpstan-burn-down
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

class PhpstanBaselinePathsExistTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * Read the `path:` values of one baseline file.
	 *
	 * @param string $file Absolute path of a phpstan-baseline*.neon file.
	 *
	 * @return string[] The paths, relative to the repository root.
	 *
	 * @spec openspec/changes/openregister-legacy-quality-cleanup/tasks.md#phase-4--phpstan-burn-down
	 */
	public static function baselinePaths(string $file): array {
		$matches = [];
		preg_match_all('/^\s*path:\s*(\S+)\s*$/m', (string)file_get_contents($file), $matches);

		return $matches[1];
	}//end baselinePaths()

	/**
	 * The path reader finds every entry and nothing else.
	 *
	 * @return void
	 */
	public function testThePathReaderReadsEveryEntry(): void {
		$file = tempnam(sys_get_temp_dir(), 'baseline');
		file_put_contents(
			$file,
			"parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: \"#^a path: here$#\"\n\t\t\tcount: 1\n\t\t\tpath: lib/A.php\n"
			. "\t\t-\n\t\t\tmessage: \"#^b$#\"\n\t\t\tcount: 2\n\t\t\tpath: lib/Sub/B.php\n"
		);

		try {
			$this->assertSame(expected: ['lib/A.php', 'lib/Sub/B.php'], actual: self::baselinePaths(file: $file));
		} finally {
			unlink($file);
		}
	}//end testThePathReaderReadsEveryEntry()

	/**
	 * No baseline file names a path that is gone.
	 *
	 * @return void
	 */
	public function testEveryBaselineEntryNamesAnExistingFile(): void {
		$files = glob(self::ROOT . '/phpstan-baseline*.neon');
		$this->assertNotEmpty(actual: $files, message: 'No phpstan-baseline*.neon found at the repository root.');

		$missing = [];
		foreach ($files as $file) {
			foreach (self::baselinePaths(file: $file) as $path) {
				if (file_exists(self::ROOT . '/' . $path) === false) {
					$missing[] = basename($file) . ': ' . $path;
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: array_values(array_unique($missing)),
			message: 'These baseline entries name files that no longer exist; delete the entries.'
		);
	}//end testEveryBaselineEntryNamesAnExistingFile()
}//end class
