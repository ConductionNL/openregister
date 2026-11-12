<?php

/**
 * The H19 classes are reached from outside their component through an interface.
 *
 * Each class listed here was named directly by a file outside its component.
 * Outside callers now type against an interface instead: a new one next to the
 * class, bound in the map of the class's own component (a missing alias is a
 * runtime 500 that no other unit test sees, so this test pins the binding), or
 * an existing one the class already implements, which needs no binding.
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

use JsonSerializable;
use OCA\OpenRegister\Service\Bindings\FlowBindings;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNodeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class H19InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface outside callers type against, and the map that binds it
	 * (null for an existing interface outside callers only receive, never resolve).
	 *
	 * @var array<class-string, array{class-string, class-string|null}>
	 */
	private const CLASSES = [
		LockObjectNode::class => [LockObjectNodeInterface::class, FlowBindings::class],
		BpmnMappingReport::class => [JsonSerializable::class, null],
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
	 * One case per class whose interface is resolved from the container.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function boundClasses(): array {
		$cases = [];
		foreach (self::CLASSES as $class => [, $registrar]) {
			if ($registrar !== null) {
				$cases[$class] = [$class];
			}
		}

		return $cases;
	}//end boundClasses()

	/**
	 * The class implements its interface.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		[$contract] = self::CLASSES[$class];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class in the map of the class's own component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('boundClasses')]
	public function testTheInterfaceIsBound(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		$this->assertSame(explode('\\', $class)[2], explode('\\', (string) $registrar)[2], 'Bound in its own component');
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

		$this->assertSame([], $naming, 'Type against ' . self::CLASSES[$class][0] . ' instead.');
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
