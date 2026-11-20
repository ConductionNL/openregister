<?php

/**
 * The configuration registrar registers its services in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Listener\Registrar\ConfigurationListenerRegistrar;
use OCA\OpenRegister\Kernel\Contracts\Search\SearchBackendSettingsInterface;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Listener\ReferenceCardDeclarationCheckListener;
use OCA\OpenRegister\Service\Configuration\ConfigurationServiceRegistrar;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use OCP\AppFramework\IAppContainer;

class ConfigurationServiceRegistrarTest extends TestCase {

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
				'OCA\OpenRegister\Service\Translation\TranslationProviderInterface',
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
				'OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationBundleService',
				'OCA\OpenRegister\Service\ConfigurationDeployment\SettingsDraftGate',
				'OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSeedService',
			],
			actual: $context->services
		);
	}//end testRegistersTheServicesInOrder()

	/**
	 * Every factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks for.
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
			$this->assertInstanceOf(expected: $name, actual: $factory($container), message: $name);
		}
	}//end testEveryFactoryBuildsItsService()

	/**
	 * The registrar subscribes Configuration's own listeners: its schema declaration check, then ConfigurationListenerRegistrar's.
	 * Search's settings port resolves to the search backend handler, which implements it; it is the only alias.
	 *
	 * @return void
	 */
	public function testTheSearchBackendSettingsAreAnsweredByTheHandler(): void {
		$context = new RecordingRegistrationContext();
		ConfigurationServiceRegistrar::register(context: $context);

		$this->assertSame(
			expected: [['alias' => SearchBackendSettingsInterface::class, 'target' => SearchBackendHandler::class]],
			actual: $context->aliases
		);
		$this->assertInstanceOf(expected: SearchBackendSettingsInterface::class, actual: new SearchBackendHandler());
	}//end testTheSearchBackendSettingsAreAnsweredByTheHandler()

	/**
	 * The only listener the registrar registers is Configuration's own schema declaration check.
	 *
	 * Every other listener stays with ListenerRegistrar.
	 *
	 * @return void
	 */
	public function testRegistersOnlyTheConfigurationListeners(): void {
		$context = new RecordingRegistrationContext();
		ConfigurationServiceRegistrar::register(context: $context);

		$own = new RecordingRegistrationContext();
		ConfigurationListenerRegistrar::register(context: $own);

		$this->assertSame(
			expected: [
				['event' => SchemaDeclarationChecksEvent::class, 'listener' => ReferenceCardDeclarationCheckListener::class],
				[
					'event' => 'OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent',
					'listener' => 'OCA\OpenRegister\Listener\ShareableConfigTypeRegistrationListener',
				],
			],
			actual: $context->listeners
		);
		$this->assertSame(expected: $own->listeners, actual: array_slice($context->listeners, 1));
	}//end testRegistersOnlyTheConfigurationListeners()
}//end class
