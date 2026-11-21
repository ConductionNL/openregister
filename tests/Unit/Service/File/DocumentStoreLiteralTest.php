<?php

/**
 * The document store root is named in one place.
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
 * Scans the string literals of lib/ for the default root.
 *
 * Comments may say `Open Registers`; code may not, outside DocumentStore.
 *
 * @coversNothing
 */
class DocumentStoreLiteralTest extends TestCase {

	/**
	 * Every PHP string literal in lib/ that contains the default root, as "file:line".
	 *
	 * @return list<string>
	 */
	private static function literals(string $lib): array {
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($relative === 'Service/File/DocumentStore.php') {
				continue;
			}

			foreach (token_get_all((string)file_get_contents($file->getPathname())) as $token) {
				if (is_array($token) === true
					&& in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) === true
					&& str_contains($token[1], 'Open Registers') === true
				) {
					$found[] = $relative . ':' . $token[2];
				}
			}
		}

		sort($found);
		return $found;
	}//end literals()

	public function testNoOtherOpenRegistersLiteralInLib(): void {
		$found = self::literals(dirname(__DIR__, 4) . '/lib');

		$this->assertSame([], $found, "The root is spelled out instead of read from DocumentStore:\n" . implode("\n", $found));
	}//end testNoOtherOpenRegistersLiteralInLib()
}//end class
