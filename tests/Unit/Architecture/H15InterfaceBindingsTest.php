<?php

/**
 * The H15 Files classes are hidden behind interfaces outside the Files component.
 *
 * PreviewSize is a value object the Files controller builds and the preview
 * generator in lib/Service reads; the generator now types against
 * PreviewSizeInterface. A value object is passed, never resolved from the
 * container, so it has no registrar binding. Chunk was named by one stale
 * use line in lib/Db/EntityRelationMapper.php, now removed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Service\File\PreviewSize;
use OCA\OpenRegister\Files\Service\File\PreviewSizeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class H15InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The class implements the interface its outside callers type against.
	 *
	 * @return void
	 */
	public function testPreviewSizeImplementsItsInterface(): void {
		$this->assertTrue(interface_exists(PreviewSizeInterface::class));
		$this->assertContains(PreviewSizeInterface::class, class_implements(PreviewSize::class));

		$size = new PreviewSize(width: 64, height: 32);
		$this->assertSame(64, $size->width());
		$this->assertSame(32, $size->height());
		$this->assertSame('64x32', $size->label());
	}//end testPreviewSizeImplementsItsInterface()

	/**
	 * One case per hidden class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function hiddenClasses(): array {
		return [
			'Chunk'       => [Chunk::class],
			'PreviewSize' => [PreviewSize::class],
		];
	}//end hiddenClasses()

	/**
	 * No lib file outside the Files component names the class in code.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoFileOutsideFilesNamesTheClass(string $class): void {
		$naming = [];
		$files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = str_replace('\\', '/', substr((string) $file->getPathname(), strlen(self::LIB) + 1));
			if ($file->isFile() === false || str_ends_with($path, '.php') === false || str_starts_with($path, 'Files/') === true) {
				continue;
			}

			if (preg_match('/' . preg_quote($class, '/') . '(?![A-Za-z0-9_])/', self::code(path: $file->getPathname())) === 1) {
				$naming[] = $path;
			}
		}

		$this->assertSame([], $naming, $class . ' is named outside lib/Files');
	}//end testNoFileOutsideFilesNamesTheClass()

	/**
	 * The source of a file without its comments, so a docblock mention does not count.
	 *
	 * @param string $path The file.
	 *
	 * @return string The code.
	 */
	private static function code(string $path): string {
		$code = '';
		foreach (token_get_all((string) file_get_contents($path)) as $token) {
			if (is_array($token) === true && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
				continue;
			}

			if (is_array($token) === true) {
				$token = $token[1];
			}

			$code .= $token;
		}

		return $code;
	}//end code()
}//end class
