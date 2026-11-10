<?php

declare(strict_types=1);

/**
 * Guard for the phpoffice/phpword replacement in composer.json
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Dependencies
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Dependencies;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * composer.json replaces phpoffice/phpword, so it is never installed, although
 * theodo-group/llphant requires it. That holds only while openregister never
 * reaches the llphant classes that need PhpWord. These tests fail as soon as
 * code under lib/ references one of them, or PhpWord itself.
 */
class PhpWordReplacedTest extends TestCase {

	/**
	 * Root of the app.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The PHP source of every file under a directory, keyed by path.
	 *
	 * @param string $directory The directory.
	 *
	 * @return array<string, string>
	 */
	private static function sources(string $directory): array {
		$sources = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() === 'php') {
				$sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
			}
		}

		return $sources;
	}//end sources()

	/**
	 * The llphant classes that use PhpWord, and the llphant classes that use those.
	 *
	 * @return list<string> Fully qualified class names.
	 */
	private static function llphantClassesNeedingPhpWord(): array {
		$sources = self::sources(directory: self::root() . '/vendor/theodo-group/llphant/src');
		$needing = [];
		foreach ($sources as $source) {
			if (str_contains($source, 'PhpOffice\\PhpWord') === true) {
				$needing[] = self::className(source: $source);
			}
		}

		foreach ($sources as $source) {
			foreach ($needing as $class) {
				$short = substr($class, (strrpos($class, '\\') + 1));
				if (preg_match('/\b' . preg_quote($short, '/') . '\b/', $source) === 1) {
					$needing[] = self::className(source: $source);
					break;
				}
			}
		}

		return array_values(array_unique(array_filter($needing)));
	}//end llphantClassesNeedingPhpWord()

	/**
	 * The fully qualified name of the class a source file declares.
	 *
	 * @param string $source The PHP source.
	 *
	 * @return string
	 */
	private static function className(string $source): string {
		preg_match('/^namespace\s+([^;]+);/m', $source, $namespace);
		preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $source, $class);

		return trim(($namespace[1] ?? '') . '\\' . ($class[1] ?? ''), '\\');
	}//end className()

	/**
	 * composer.json replaces phpword, and the lock does not hold it.
	 *
	 * @return void
	 */
	public function testPhpWordIsReplacedAndNotLocked(): void {
		$composer = json_decode((string) file_get_contents(self::root() . '/composer.json'), true);
		$this->assertSame('*', ($composer['replace']['phpoffice/phpword'] ?? null));
		$this->assertArrayNotHasKey('phpoffice/phpword', $composer['require'] ?? []);

		$lock = json_decode((string) file_get_contents(self::root() . '/composer.lock'), true);
		$names = array_column(array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []), 'name');
		$this->assertNotContains('phpoffice/phpword', $names);
	}//end testPhpWordIsReplacedAndNotLocked()

	/**
	 * No PhpWord class can be autoloaded.
	 *
	 * @return void
	 */
	public function testPhpWordIsNotAutoloadable(): void {
		$this->assertFalse(class_exists('PhpOffice\\PhpWord\\IOFactory'));
		$this->assertFalse(class_exists('PhpOffice\\PhpWord\\PhpWord'));
	}//end testPhpWordIsNotAutoloadable()

	/**
	 * No code under lib/ references PhpWord or an llphant class that needs it.
	 *
	 * @return void
	 */
	public function testLibNeverReachesPhpWord(): void {
		$forbidden = self::llphantClassesNeedingPhpWord();
		$this->assertContains('LLPhant\\Embeddings\\DataReader\\DocxReader', $forbidden, 'The scan finds the class known to use PhpWord.');
		$forbidden[] = 'PhpOffice\\PhpWord';

		foreach (self::sources(directory: self::root() . '/lib') as $path => $source) {
			foreach ($forbidden as $class) {
				$this->assertStringNotContainsString($class, $source, $path . ' references ' . $class . ', which needs phpoffice/phpword.');
			}
		}
	}//end testLibNeverReachesPhpWord()
}//end class
