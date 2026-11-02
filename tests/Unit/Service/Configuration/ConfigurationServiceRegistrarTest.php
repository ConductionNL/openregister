<?php

/**
 * The configuration registrar registers its services in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ConfigurationServiceRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\FactoryWiringAssertions;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use OCP\AppFramework\IAppContainer;

class ConfigurationServiceRegistrarTest extends TestCase {
	use FactoryWiringAssertions;

	/**
	 * Optional dependencies these factories leave out on purpose.
	 *
	 * @var array<string,string>
	 */
	private const LEFT_OUT = [
		'OCA\OpenRegister\Service\Settings\ValidationOperationsHandler::$validateHandler' => 'Resolved from the container on first use'
			.' by getValidateHandler().',
		'OCA\OpenRegister\Service\SettingsService::$objectCacheService'                  => 'Assigned in the constructor and never read.',
	];

	/**
	 * Optional dependencies stored under another property name.
	 *
	 * @var array<string,string>
	 */
	private const HELD_BY = [
		'OCA\OpenRegister\Service\SettingsService::$validOpsHandler'     => 'validationOperationsHandler',
		'OCA\OpenRegister\Service\SettingsService::$objRetentionHandler' => 'objectRetentionHandler',
		'OCA\OpenRegister\Service\SettingsService::$cfgSettingsHandler'  => 'configurationSettingsHandler',
	];

	/**
	 * register() registers the configuration, settings and deployment services, in this order.
	 *
	 * A later registration of the same name replaces an earlier one, so the
	 * order is part of what the container hands out.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		ConfigurationServiceRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Service\Configuration\UploadHandler',
				'OCA\OpenRegister\Service\Configuration\GitHubHandler',
				'OCA\OpenRegister\Service\Configuration\ImportHandler',
				'OCA\OpenRegister\Service\Configuration\ImportHandler',
				'OCA\OpenRegister\Service\Configuration\ExportHandler',
				'OCA\OpenRegister\Service\ConfigurationService',
				'OCA\OpenRegister\Service\Settings\ValidationOperationsHandler',
				'OCA\OpenRegister\Service\SettingsService',
				'OCA\OpenRegister\Service\TenantKeyService',
				'OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore',
				'OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision',
				'OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard',
				'OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore',
				'OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService',
				'OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService',
				'OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService',
				'OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer',
			],
			actual: $context->services
		);
	}//end testRegistersTheServicesInOrder()

	/**
	 * Every factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks
	 * for. Each built service must also hold every nullable object dependency
	 * its constructor declares, except those on LEFT_OUT.
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		ConfigurationServiceRegistrar::register(context: $context);

		$doubles   = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$doubles) {
				$doubles[$id] ??= $this->createMock(originalClassName: $id);
				return $doubles[$id];
			}
		);

		foreach ($context->factories as $name => $factory) {
			$service = $factory($container);
			$this->assertInstanceOf(expected: $name, actual: $service, message: $name);
			$this->assertFactorySuppliedEveryOptionalDependency(service: $service, allowList: self::LEFT_OUT, properties: self::HELD_BY);
		}
	}//end testEveryFactoryBuildsItsService()

	/**
	 * The registrar registers services only; listeners stay with ListenerRegistrar.
	 *
	 * @return void
	 */
	public function testRegistersNoListeners(): void {
		$context = new RecordingRegistrationContext();
		ConfigurationServiceRegistrar::register(context: $context);

		$this->assertCount(expectedCount: 0, haystack: $context->listeners);
	}//end testRegistersNoListeners()
}//end class
