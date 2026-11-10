<?php

/**
 * DescriptorConfigIds is reached through its constants interface outside lib/Service.
 *
 * The configuration ids the import repair steps run under are constants of
 * DescriptorConfigIdsInterface, which DescriptorConfigIds implements; the repair
 * steps name the interface. The interface carries constants only, so it needs no
 * container binding.
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

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIds;
use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F12DescriptorConfigIdsInterfaceTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Interface => [the class that implements it, a class of the same component].
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const BINDINGS = [
		DescriptorConfigIdsInterface::class => [DescriptorConfigIds::class, DescriptorConfigIds::class],
	];

	/**
	 * Classes no lib file outside their own component names any more.
	 *
	 * @var list<class-string>
	 */
	private const HIDDEN = [
		DescriptorConfigIds::class,
	];

	/**
	 * One case per interface.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function interfaces(): array {
		$cases = [];
		foreach (array_keys(self::BINDINGS) as $contract) {
			$cases[$contract] = [$contract];
		}

		return $cases;
	}//end interfaces()

	/**
	 * One case per hidden class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function hiddenClasses(): array {
		$cases = [];
		foreach (self::HIDDEN as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end hiddenClasses()

	/**
	 * The class implements the interface, and the interface lives in the class's own component.
	 *
	 * @param class-string $contract The interface.
	 *
	 * @return void
	 */
	#[DataProvider('interfaces')]
	public function testTheClassImplementsTheInterface(string $contract): void {
		[$class] = self::BINDINGS[$contract];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
		$this->assertSame(explode('\\', $class)[2], explode('\\', $contract)[2], 'Declared in its own component');
		$this->assertSame((new \ReflectionClass($contract))->getConstants(), array_intersect_key((new \ReflectionClass($class))->getConstants(), (new \ReflectionClass($contract))->getConstants()), 'Every id still resolves on the class');
	}//end testTheClassImplementsTheInterface()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame([], $naming, $class . ' is named outside its component; type against its interface instead.');
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

			$qualified = in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true
				&& ltrim($token[1], '\\') === $class;
			if ($qualified === true || ($imported === true && $token[0] === T_STRING && $token[1] === $short)) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
