<?php

/**
 * The interfaces lane K22 added are bound to their class in their own component.
 *
 * ImportService drove the notify_push batch through the static NotifyPushBatch
 * entry class. It now types against NotifyPushBatchInterface, which
 * ListenerRegistrar binds to NotifyPushBatch, so no file outside lib/Listener
 * names the class.
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

use OCA\OpenRegister\Listener\ListenerRegistrar;
use OCA\OpenRegister\Listener\NotifyPushBatch;
use OCA\OpenRegister\Listener\NotifyPushBatchInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class K22InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface outside callers type against, and the class whose BINDINGS bind it.
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const CLASSES = [
		NotifyPushBatch::class => [NotifyPushBatchInterface::class, ListenerRegistrar::class],
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
	 * The class implements its interface, and both live in one component with the binding.
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
		$this->assertSame(explode('\\', $class)[2], explode('\\', $registrar)[2], 'Bound in its own component');
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsBound(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
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
		$component = explode('\\', $class)[2];
		$short     = substr((string) strrchr($class, '\\'), 1);
		$naming    = [];
		$files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$path = (string) $file->getPathname();
			if (str_ends_with($path, '.php') === false || str_contains($path, '/lib/' . $component . '/') === true) {
				continue;
			}

			if (preg_match('/\b' . preg_quote($short, '/') . '\b(?!Interface)/', (string) file_get_contents($path)) === 1) {
				$naming[] = $path;
			}
		}

		$this->assertSame([], $naming, 'Type against ' . self::CLASSES[$class][0] . ' instead.');
	}//end testNoOutsideFileNamesTheClass()
}//end class
