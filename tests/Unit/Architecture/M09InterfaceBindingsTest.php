<?php

/**
 * Pins the interfaces lane M09 added: each is bound in its component's
 * registrar map, the bound class implements it, the real bootstrap aliases
 * it, and no lib file outside the class's component names the class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Bindings\FeatureBindings;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatterInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Bindings of the M09 interfaces.
 *
 * @coversNothing
 */
final class M09InterfaceBindingsTest extends TestCase {

	/**
	 * Interface => [class, its top-level lib component, the registrar map].
	 *
	 * @var array<class-string, array{0: class-string, 1: string, 2: array<class-string, class-string>}>
	 */
	private const BINDINGS = [
		ObjectSearchResultFormatterInterface::class => [ObjectSearchResultFormatter::class, 'Service', FeatureBindings::BINDINGS],
	];

	/**
	 * The registrar map binds the interface to the class, which implements it.
	 *
	 * @return void
	 */
	public function testEachInterfaceIsBoundAndImplemented(): void {
		foreach (self::BINDINGS as $contract => [$service, , $map]) {
			$this->assertArrayHasKey($contract, $map);
			$this->assertSame($service, $map[$contract]);
			$this->assertContains($contract, class_implements($service));
		}
	}//end testEachInterfaceIsBoundAndImplemented()

	/**
	 * The real bootstrap aliases each interface to the class.
	 *
	 * @return void
	 */
	public function testTheBootstrapAliasesEachInterface(): void {
		$context = new RecordingRegistrationContext();
		(new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);

		$resolved = [];
		foreach ($context->aliases as $alias) {
			$resolved[$alias['alias']] = $alias['target'];
		}

		foreach (self::BINDINGS as $contract => [$service]) {
			$this->assertArrayHasKey($contract, $resolved);
			$this->assertSame($service, $resolved[$contract]);
		}
	}//end testTheBootstrapAliasesEachInterface()

	/**
	 * No lib file outside the class's component names the concrete class.
	 *
	 * @return void
	 */
	public function testNoFileOutsideTheComponentNamesTheClass(): void {
		$lib = dirname(__DIR__, 3) . '/lib';
		foreach (self::BINDINGS as [$service, $component]) {
			$short = (new ReflectionClass($service))->getShortName();
			$offenders = [];
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS));
			foreach ($files as $file) {
				$path = substr((string) $file->getPathname(), (strlen($lib) + 1));
				if (str_ends_with($path, '.php') === false || str_starts_with($path, $component . '/') === true) {
					continue;
				}

				if (preg_match('/\b' . $short . '\b(?!Interface)/', (string) file_get_contents((string) $file->getPathname())) === 1) {
					$offenders[] = $path;
				}
			}

			$this->assertSame([], $offenders, $short);
		}
	}//end testNoFileOutsideTheComponentNamesTheClass()
}//end class
