<?php

/**
 * The E18 format classes are built and typed only inside lib/Formats.
 *
 * ValidateObject built the five custom string formats with new, and
 * ExtendedFieldCheck and RecurrenceOccurrences built ExtendedFieldTypeValidator
 * and read its DEFAULT_OCCURRENCES constant. They now go through FormatFactory,
 * which constructs exactly the same objects, and type against
 * ExtendedFieldTypeValidatorInterface, which now carries the two constants. Neither
 * is resolved from the container, so there is no binding to pin.
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

use OCA\OpenRegister\Formats\CronFormat;
use OCA\OpenRegister\Formats\ExtendedFieldTypeValidator;
use OCA\OpenRegister\Formats\ExtendedFieldTypeValidatorInterface;
use OCA\OpenRegister\Formats\FormatFactory;
use OCA\OpenRegister\Formats\Iso8601DateTimeFormat;
use OCA\OpenRegister\Formats\SemVerFormat;
use OCA\OpenRegister\Formats\UserFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class E18FormatsInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface outside callers type against, and the registrar that
	 * binds it (null: not resolved from the container; FormatFactory builds it).
	 *
	 * @var array<class-string, array{class-string, class-string|null}>
	 */
	private const CLASSES = [
		ExtendedFieldTypeValidator::class => [ExtendedFieldTypeValidatorInterface::class, null],
	];

	/**
	 * Format classes outside callers get only from FormatFactory, with no interface of their own.
	 *
	 * @var list<class-string>
	 */
	private const FACTORY_BUILT = [
		CronFormat::class,
		Iso8601DateTimeFormat::class,
		SemVerFormat::class,
		UserFormat::class,
	];

	/**
	 * Outside files still allowed to name the class, and why.
	 *
	 * None are kept.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [];

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
	 * One case per factory-built format class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function factoryBuilt(): array {
		$cases = [];
		foreach (self::FACTORY_BUILT as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end factoryBuilt()

	/**
	 * FormatFactory builds the format, and no lib file outside Formats names it.
	 *
	 * @param class-string $class The format class.
	 *
	 * @return void
	 */
	#[DataProvider('factoryBuilt')]
	public function testTheFormatIsBuiltOnlyByTheFactory(string $class): void {
		$this->assertContains($class, array_map(get_class(...), FormatFactory::customStringFormats(userManager: $this->createMock(\OCP\IUserManager::class), planned: fn (string $uid): bool => false)));
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame([], $naming, 'Use FormatFactory::customStringFormats() instead.');
	}//end testTheFormatIsBuiltOnlyByTheFactory()

	/**
	 * The class implements its interface, and its registrar is in its own component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
		$this->assertSame(explode('\\', $class)[2], explode('\\', $contract)[2], 'The interface lives in the component of the class');
		if ($registrar !== null) {
			$this->assertSame(explode('\\', $class)[2], explode('\\', $registrar)[2], 'Bound in its own component');
		}
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class in the registrar of the class's component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsBound(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		if ($registrar === null) {
			$this->assertTrue(interface_exists($contract), 'Not container-bound: built by FormatFactory');
			return;
		}

		$bindings = constant($registrar . '::BINDINGS');
		$this->assertArrayHasKey($contract, $bindings);
		$this->assertSame($class, $bindings[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(self::KEPT_CALLERS[$class] ?? [], $naming, 'Type against ' . self::CLASSES[$class][0] . ' instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every lib PHP file outside the component of the class, keyed by its path under lib/.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(string $class): array {
		$component = explode('\\', $class)[2];
		$lib       = (string) realpath(self::LIB);
		$files     = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, $component . '/') === false) {
				$files[$relative] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end outsideFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short    = substr($class, (int) strrpos($class, '\\') + 1);
		$imported = str_contains($source, 'use ' . $class . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($this->tokenNames(token: $token, class: $class) === true
				|| ($imported === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()

	/**
	 * Whether a qualified-name token is the class.
	 *
	 * @param array{int, string, int} $token The token.
	 * @param class-string            $class The class.
	 *
	 * @return bool
	 */
	private function tokenNames(array $token, string $class): bool {
		if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === false) {
			return false;
		}

		return ltrim($token[1], '\\') === $class;
	}//end tokenNames()
}//end class
