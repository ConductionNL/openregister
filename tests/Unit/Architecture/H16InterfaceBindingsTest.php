<?php

/**
 * The H16 classes named from outside their component are reached through interfaces.
 *
 * McpToolsService was named by the agent scope in lib/Service. It now implements
 * McpToolCatalogueInterface, which McpWiring::FACTORY_BINDINGS aliases to it. CompanySearch
 * was built with `new` by CompanyLookupController; the controller now builds it
 * through CompanySearchFactoryInterface, bound in FeatureBindings to
 * CompanySearchFactory. A missing alias
 * is a runtime 500 that no other unit test sees, so this test pins the binding as
 * well as the implementation and the lib files still naming the class.
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

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Mcp\McpWiring;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolCatalogueInterface;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\Bindings\FeatureBindings;
use OCA\OpenRegister\Service\Integration\Providers\CompanySearch;
use OCA\OpenRegister\Service\Integration\Providers\CompanySearchFactory;
use OCA\OpenRegister\Service\Integration\Providers\CompanySearchFactoryInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * @coversNothing
 */
final class H16InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [its component under lib/, the interface outside callers use, the class bound to it, the map binding it].
	 *
	 * @var array<class-string, array{0: string, 1: class-string, 2: class-string, 3: string}>
	 */
	private const CLASSES = [
		McpToolsService::class => ['Mcp', McpToolCatalogueInterface::class, McpToolsService::class, McpWiring::class . '::FACTORY_BINDINGS'],
		CompanySearch::class   => ['Service', CompanySearchFactoryInterface::class, CompanySearchFactory::class, FeatureBindings::class . '::BINDINGS'],
	];

	/**
	 * Lib files outside the component still allowed to name the class, and why.
	 *
	 * None: McpAgentScope types against McpToolCatalogueInterface, and
	 * CompanyLookupController builds its search through CompanySearchFactoryInterface.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_OUTSIDE = [
		McpToolsService::class => [],
		CompanySearch::class   => [],
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
	 * The bound class implements the interface, and the bootstrap's binding map aliases it.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsImplementedAndBound(string $class): void {
		[, $contract, $bound, $map] = self::CLASSES[$class];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($bound), $bound . ' implements ' . $contract);
		$this->assertSame($bound, constant($map)[$contract] ?? null, $contract . ' is in ' . $map);

		$context = new RecordingRegistrationContext();
		(new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
		$resolved = [];
		foreach ($context->aliases as $alias) {
			$resolved[$alias['alias']] = $alias['target'];
		}

		$this->assertSame($bound, $resolved[$contract] ?? null, $contract . ' is bound by the bootstrap');
	}//end testTheInterfaceIsImplementedAndBound()

	/**
	 * Outside its component, only the kept files name the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testOnlyTheKeptFilesOutsideTheComponentNameTheClass(string $class): void {
		$component = self::CLASSES[$class][0] . '/';
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$naming    = [];
		foreach ($this->libFiles() as $relative => $path) {
			if (str_starts_with($relative, $component) === false
				&& preg_match('/\b' . $short . '\b/', (string) file_get_contents($path)) === 1
			) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(self::KEPT_OUTSIDE[$class], $naming, 'Type against ' . self::CLASSES[$class][1] . ' instead.');
	}//end testOnlyTheKeptFilesOutsideTheComponentNameTheClass()

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
}//end class
