<?php

/**
 * The A27 Files classes with a high fan-in are reached through interfaces.
 *
 * ChunkMapper and FileOwnershipHandler were each named by more than ten lib
 * files. Each now has narrow interfaces next to it; callers type against and
 * resolve those, and FilesRegistrar aliases each interface to the class. A
 * missing alias is a runtime 500 that no other unit test sees, so this test pins
 * the binding as well as the implementation and the files still naming the class.
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

use OCA\OpenRegister\Files\Db\ChunkIndexOverviewInterface;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Files\Db\ChunkStoreInterface;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class A27InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interfaces its callers type against.
	 *
	 * @var array<class-string, list<class-string>>
	 */
	private const CLASSES = [
		ChunkMapper::class => [ChunkIndexOverviewInterface::class, ChunkSourceReaderInterface::class, ChunkStoreInterface::class],
		FileOwnershipHandler::class => [FileOwnershipHandlerInterface::class],
	];

	/**
	 * Lib files, other than the class itself, still allowed to name the class, and why.
	 *
	 * FilesRegistrar binds the interfaces to the class. ObjectHandler is changed
	 * by an open PR and is left for after it lands; TextExtractionService passes its
	 * mapper on to ObjectHandler, so it keeps the class type until ObjectHandler
	 * moves.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [
		ChunkMapper::class => [
			'Files/FilesRegistrar.php',
			'Files/Service/TextExtraction/ObjectHandler.php',
			'Files/Service/TextExtractionService.php',
		],
		FileOwnershipHandler::class => ['Files/FilesRegistrar.php'],
	];

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (array_keys(self::CLASSES) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements each interface, and FilesRegistrar binds each to it.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testEachInterfaceIsImplementedAndBound(string $class): void {
		foreach (self::CLASSES[$class] as $contract) {
			$this->assertTrue(interface_exists($contract), $contract . ' exists');
			$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
			$this->assertSame($class, FilesRegistrar::BINDINGS[$contract] ?? null, $contract . ' is bound');
		}
	}//end testEachInterfaceIsImplementedAndBound()

	/**
	 * Only the kept callers name the class in code.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testOnlyTheKeptCallersNameTheClass(string $class): void {
		$own    = str_replace('\\', '/', substr($class, strlen('OCA\\OpenRegister\\'))) . '.php';
		$naming = [];
		foreach ($this->libFiles() as $relative => $path) {
			if ($relative !== $own && $this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(self::KEPT_CALLERS[$class], $naming, 'Type against ' . implode(' or ', self::CLASSES[$class]) . ' instead.');
	}//end testOnlyTheKeptCallersNameTheClass()

	/**
	 * Every lib PHP file, keyed by its path under lib/, sorted.
	 *
	 * @return array<string, string>
	 */
	private function libFiles(): array {
		$lib   = (string) realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			if ($file->getExtension() === 'php') {
				$files[substr($file->getPathname(), strlen($lib) + 1)] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end libFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$bare      = str_contains($source, 'use ' . $class . ';') || str_contains($source, 'namespace ' . $namespace . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ((in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true && ltrim($token[1], '\\') === $class)
				|| ($bare === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
