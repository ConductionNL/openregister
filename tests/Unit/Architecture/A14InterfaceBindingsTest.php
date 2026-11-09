<?php

/**
 * The A14 Integration and ObjectSource classes are reached through narrow interfaces.
 *
 * Each class listed here was named directly by many lib files, several of them in
 * other components. It now has role interfaces next to it; the listed callers type
 * against those, and the registrar of the class's own area aliases each interface
 * to the class. A missing alias is a runtime 500 that no other unit test sees, so
 * this test pins the binding, the registration and the callers.
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

use OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface;
use OCA\OpenRegister\Service\Integration\IntegrationIdIndexInterface;
use OCA\OpenRegister\Service\Integration\IntegrationRegistrar;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\PageWidgetRegistryInterface;
use OCA\OpenRegister\Service\Integration\Providers\KvkLookupInterface;
use OCA\OpenRegister\Service\Integration\Providers\KvkProvider;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatcherInterface;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesSearchInterface;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistrar;
use OCA\OpenRegister\Service\ObjectSource\TablesCatalogueInterface;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaReconcilerInterface;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class A14InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Interface => the class that implements it, and the registrar that binds it.
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const CONTRACTS = [
		IntegrationCatalogueInterface::class => [IntegrationRegistry::class, IntegrationRegistrar::class],
		IntegrationIdIndexInterface::class => [IntegrationRegistry::class, IntegrationRegistrar::class],
		KvkLookupInterface::class => [KvkProvider::class, IntegrationRegistrar::class],
		MessageDispatcherInterface::class => [MessageDispatchProvider::class, IntegrationRegistrar::class],
		OpenCorporatesSearchInterface::class => [OpenCorporatesProvider::class, IntegrationRegistrar::class],
		PageWidgetRegistryInterface::class => [IntegrationRegistry::class, IntegrationRegistrar::class],
		TablesCatalogueInterface::class => [TablesTableReader::class, ObjectSourceRegistrar::class],
		TablesSchemaReconcilerInterface::class => [TablesSchemaSyncService::class, ObjectSourceRegistrar::class],
	];

	/**
	 * Interface => the lib files (under lib/) that type against it instead of the class.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const CALLERS = [
		IntegrationCatalogueInterface::class => [
			'Capabilities/CapabilityRegistrar.php',
			'Capabilities/IntegrationsCapability.php',
			'Controller/IntegrationsController.php',
			'Controller/ObjectIntegrationsController.php',
			'Controller/Support/ObjectIntegrationsDomain.php',
			'Mcp/BuiltIn/IntegrationsToolProvider.php',
			'Settings/IntegrationsAdminSettings.php',
		],
		IntegrationIdIndexInterface::class => [
			'Repair/LogDanglingLinkedTypes.php',
			'Service/Integration/PropertyReferenceTypeValidator.php',
			'Service/LinkedEntityIntegrations.php',
			'Service/LinkedEntityService.php',
		],
		KvkLookupInterface::class => ['Controller/CompanyLookupController.php'],
		MessageDispatcherInterface::class => ['Controller/MessageDispatchController.php'],
		OpenCorporatesSearchInterface::class => ['Controller/CompanyLookupController.php'],
		PageWidgetRegistryInterface::class => [
			'Search/Service/MapsOverviewService.php',
			'Service/AnalyticsSeriesService.php',
		],
		TablesCatalogueInterface::class => [
			'Command/TablesSyncCommand.php',
			'Repair/SeedTablesVirtualSchemas.php',
		],
		TablesSchemaReconcilerInterface::class => [
			'Command/TablesSyncCommand.php',
			'Listener/TablesTableDeletedListener.php',
			'Repair/SeedTablesVirtualSchemas.php',
		],
	];

	/**
	 * One case per interface.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function contracts(): array {
		$cases = [];
		foreach (array_keys(self::CONTRACTS) as $contract) {
			$cases[$contract] = [$contract];
		}

		return $cases;
	}//end contracts()

	/**
	 * One case per interface and caller.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	public static function callers(): array {
		$cases = [];
		foreach (self::CALLERS as $contract => $files) {
			foreach ($files as $file) {
				$cases[$contract . ' in ' . $file] = [$contract, $file];
			}
		}

		return $cases;
	}//end callers()

	/**
	 * The class implements the interface, and the registrar is in the same component.
	 *
	 * @param class-string $contract The interface.
	 *
	 * @return void
	 */
	#[DataProvider('contracts')]
	public function testTheClassImplementsTheInterface(string $contract): void {
		[$class, $registrar] = self::CONTRACTS[$contract];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
		$this->assertSame(explode('\\', $class)[2], explode('\\', $registrar)[2], 'Bound in its own component');
	}//end testTheClassImplementsTheInterface()

	/**
	 * The registrar's BINDINGS map the interface to the class.
	 *
	 * @param class-string $contract The interface.
	 *
	 * @return void
	 */
	#[DataProvider('contracts')]
	public function testTheInterfaceIsBound(string $contract): void {
		[$class, $registrar] = self::CONTRACTS[$contract];
		$bindings = constant($registrar . '::BINDINGS');
		$this->assertArrayHasKey($contract, $bindings);
		$this->assertSame($class, $bindings[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * The registrar's register() aliases the interface to the class.
	 *
	 * @param class-string $contract The interface.
	 *
	 * @return void
	 */
	#[DataProvider('contracts')]
	public function testRegisterAliasesTheInterface(string $contract): void {
		[$class, $registrar] = self::CONTRACTS[$contract];
		$context = new RecordingRegistrationContext();
		$registrar::register($context);
		$this->assertContains(['alias' => $contract, 'target' => $class], $context->aliases);
		$this->assertContains($class, $context->services, $class . ' is registered, so the alias resolves');
	}//end testRegisterAliasesTheInterface()

	/**
	 * The caller names the interface and no longer names the class.
	 *
	 * @param class-string $contract The interface.
	 * @param string       $file     The caller, relative to lib/.
	 *
	 * @return void
	 */
	#[DataProvider('callers')]
	public function testTheCallerTypesAgainstTheInterface(string $contract, string $file): void {
		$source = (string) file_get_contents(self::LIB . '/' . $file);
		$this->assertTrue($this->namesClass(source: $source, class: $contract), $file . ' names ' . $contract);
		$this->assertFalse($this->namesClass(source: $source, class: self::CONTRACTS[$contract][0]), $file . ' no longer names the class');
	}//end testTheCallerTypesAgainstTheInterface()

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
		$local     = str_contains($source, 'namespace ' . $namespace . ';');
		$imported  = str_contains($source, 'use ' . $class . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true && ltrim($token[1], '\\') === $class) {
				return true;
			}

			if (($imported === true || $local === true) && $token[0] === T_STRING && $token[1] === $short) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
