<?php

/**
 * Core binds its own service interfaces in CoreServiceBindings, through ServiceRegistrar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\ServiceRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every Core entry is one alias, registered once, to the same class as before.
 *
 * @coversNothing
 */
final class CoreServiceBindingsTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * ServiceRegistrar aliases every entry of the Core map.
	 *
	 * @return void
	 */
	public function testServiceRegistrarAliasesEveryEntry(): void {
		$context = new RecordingRegistrationContext();
		ServiceRegistrar::register(context: $context);
		$aliases = array_column($context->aliases, 'target', 'alias');

		$this->assertNotSame(expected: [], actual: CoreServiceBindings::BINDINGS);
		$this->assertSame(expected: CoreServiceBindings::BINDINGS, actual: array_intersect_key($aliases, CoreServiceBindings::BINDINGS));
	}//end testServiceRegistrarAliasesEveryEntry()

	/**
	 * Each class implements the interface it is bound under.
	 *
	 * @return void
	 */
	public function testEveryInterfaceIsImplementedByItsClass(): void {
		foreach (CoreServiceBindings::BINDINGS as $interface => $class) {
			$this->assertTrue(condition: (new ReflectionClass($class))->implementsInterface($interface), message: $class . ' implements ' . $interface);
		}
	}//end testEveryInterfaceIsImplementedByItsClass()

	/**
	 * The app registers each Core id exactly once, as an alias of its class, so it resolves to that class's shared instance.
	 *
	 * @return void
	 */
	public function testTheAppBindsEveryIdOnceToItsClass(): void {
		$context = new RecordingRegistrationContext();
		(new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);

		foreach (CoreServiceBindings::BINDINGS as $interface => $class) {
			$targets = array_column(array_filter($context->aliases, static fn (array $alias): bool => $alias['alias'] === $interface), 'target');
			$this->assertSame(expected: [$class], actual: $targets, message: $interface . ' is aliased once, to ' . $class);
			$this->assertNotContains(needle: $interface, haystack: $context->services, message: $interface . ' has no factory of its own');
		}
	}//end testTheAppBindsEveryIdOnceToItsClass()

	/**
	 * ContractBindings registers none of the Core entries again.
	 *
	 * @return void
	 */
	public function testContractBindingsDoesNotRegisterThemAgain(): void {
		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);
		$aliases = array_column($context->aliases, 'target', 'alias');

		$this->assertSame(expected: [], actual: array_intersect_key($aliases, CoreServiceBindings::BINDINGS));
	}//end testContractBindingsDoesNotRegisterThemAgain()

	/**
	 * The map binds only classes that count to Core in scripts/component-map.csv.
	 *
	 * @return void
	 */
	public function testEveryClassCountsToCore(): void {
		[$files, $dirs] = $this->csvRows();
		$elsewhere      = [];
		foreach (CoreServiceBindings::BINDINGS as $class) {
			$path = 'lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\OpenRegister\\'))) . '.php';
			$area = $files[$path] ?? $this->directoryArea(path: $path, dirs: $dirs);
			if ($area !== 'Core') {
				$elsewhere[] = $class . ' counts to ' . $area;
			}
		}

		$this->assertSame(expected: [], actual: $elsewhere);
	}//end testEveryClassCountsToCore()

	/**
	 * The csv rows: [file rows, directory rows], each `lib/...` path => component.
	 *
	 * @return array{0: array<string, string>, 1: array<string, string>}
	 */
	private function csvRows(): array {
		$handle = fopen(self::ROOT . '/scripts/component-map.csv', 'r');
		$this->assertNotFalse(condition: $handle);
		$header = (array) fgetcsv($handle, null, ',', '"', '');
		$files  = [];
		$dirs   = [];
		while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
			if ($line === [null]) {
				continue;
			}

			$row  = array_combine($header, $line);
			$path = 'lib/' . trim((string) $row['file']);
			if (str_ends_with($path, '/') === true) {
				$dirs[rtrim($path, '/')] = trim((string) $row['component']);
				continue;
			}

			$files[$path] = trim((string) $row['component']);
		}

		fclose($handle);
		return [$files, $dirs];
	}//end csvRows()

	/**
	 * The component of the deepest directory row above a path.
	 *
	 * @param string                $path The `lib/...` path.
	 * @param array<string, string> $dirs Directory rows.
	 *
	 * @return string
	 */
	private function directoryArea(string $path, array $dirs): string {
		$directory = dirname($path);
		while ($directory !== 'lib' && $directory !== '.') {
			if (isset($dirs[$directory]) === true) {
				return $dirs[$directory];
			}

			$directory = dirname($directory);
		}

		return 'no row';
	}//end directoryArea()
}//end class
