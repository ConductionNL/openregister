<?php

/**
 * No managed file is read or written through a local filesystem path.
 *
 * The documents live wherever the Files API puts them (the data directory,
 * object storage or an External storage mount), so only the Files API may
 * touch their bytes. A local-path shortcut works on the data directory and
 * breaks the moment the store is replaced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans lib/ for local-path access to file bytes.
 *
 * @coversNothing
 */
class DocumentBytePathTest extends TestCase {

	/**
	 * The local-path shortcuts that bypass the Files API.
	 */
	private const SHORTCUTS = ['getLocalFile(', 'getLocalFolder(', "'datadirectory'"];

	/**
	 * Where a shortcut is allowed, and why: file => reason.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [
		'Service/Configuration/ConfigurationServiceRegistrar.php' => 'builds the appdata_openregister configuration cache path, which holds no documents',
	];

	/**
	 * Every shortcut in lib/ outside the allowed files, as "file:line: code".
	 *
	 * @return list<string>
	 */
	public static function shortcutsIn(string $lib): array {
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if (isset(self::ALLOWED[$relative]) === true) {
				continue;
			}

			foreach (file($file->getPathname()) as $number => $line) {
				foreach (self::SHORTCUTS as $shortcut) {
					if (str_contains($line, $shortcut) === true) {
						$found[] = sprintf('%s:%d: %s', $relative, ($number + 1), trim($line));
					}
				}
			}
		}

		sort($found);
		return $found;
	}//end shortcutsIn()

	public function testNoManagedFileIsReadByLocalPath(): void {
		$found = self::shortcutsIn(dirname(__DIR__, 4) . '/lib');

		$this->assertSame([], $found, "A managed file is reached through a local path:\n" . implode("\n", $found));
	}//end testNoManagedFileIsReadByLocalPath()

	public function testTheScanFindsAShortcut(): void {
		$lib = sys_get_temp_dir() . '/or-bytepath-' . bin2hex(random_bytes(4));
		mkdir($lib . '/Service', 0777, true);
		file_put_contents($lib . '/Service/Reader.php', "<?php\n\$path = \$node->getStorage()->getLocalFile(\$internal);\n");

		try {
			$this->assertSame(['Service/Reader.php:2: $path = $node->getStorage()->getLocalFile($internal);'], self::shortcutsIn($lib));
		} finally {
			unlink($lib . '/Service/Reader.php');
			rmdir($lib . '/Service');
			rmdir($lib);
		}
	}//end testTheScanFindsAShortcut()

	public function testTheAllowedFileStillHoldsItsShortcut(): void {
		$source = (string)file_get_contents(dirname(__DIR__, 4) . '/lib/Service/Configuration/ConfigurationServiceRegistrar.php');

		$this->assertStringContainsString("'datadirectory'", $source, 'the exception is stale: remove it from ALLOWED');
	}//end testTheAllowedFileStillHoldsItsShortcut()
}//end class
