<?php

/**
 * The configuration registrar's factories ask the container for the same services, in the same order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCA\OpenRegister\Service\Configuration\ConfigurationServiceRegistrar;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SchemaImportInstaller;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ConfigurationServiceRegistrarWiringTest extends TestCase {

	/**
	 * Every factory asks the container for exactly these services, in this order.
	 *
	 * @return void
	 */
	public function testEveryFactoryAsksForTheSameServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		ConfigurationServiceRegistrar::register(context: $context);

		$actual = [];
		foreach ($context->factories as $name => $factory) {
			$requested = [];
			$factory($this->recordingContainer(requested: $requested));
			$actual[$name] = $requested;
		}

		$this->assertSame(expected: self::expectedRequests(), actual: $actual);
	}//end testEveryFactoryAsksForTheSameServicesInOrder()

	/**
	 * A missing optional import service is logged at its own level and the handler is still built.
	 *
	 * @return void
	 */
	public function testMissingOptionalImportServicesAreLoggedAtTheirLevel(): void {
		$context = new RecordingRegistrationContext();
		ConfigurationServiceRegistrar::register(context: $context);

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message) use (&$logged): void {
				$logged[] = 'debug: ' . $message;
			}
		);
		$logger->method('warning')->willReturnCallback(
			function (string $message) use (&$logged): void {
				$logged[] = 'warning: ' . $message;
			}
		);

		$missing   = [
			ShippedConfigurationGuard::class,
			AppImportJobRecorder::class,
			SchemaImportInstaller::class,
			'OCP\IUserSession',
		];
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($missing, $logger) {
				if (in_array($id, $missing, true) === true) {
					throw new RuntimeException('no ' . $id);
				}

				if ($id === 'Psr\Log\LoggerInterface') {
					return $logger;
				}

				return $this->createMock($id);
			}
		);

		$handler = $context->factories[ImportHandler::class]($container);

		$this->assertInstanceOf(ImportHandler::class, $handler);
		$this->assertSame(
			[
				'debug: [Application] ShippedConfigurationGuard unavailable for ImportHandler: no ' . ShippedConfigurationGuard::class,
				'warning: [Application] AppImportJobRecorder unavailable for ImportHandler: no ' . AppImportJobRecorder::class,
				'warning: [Application] SchemaImportInstaller unavailable for ImportHandler: no ' . SchemaImportInstaller::class,
				'debug: [Application] IUserSession unavailable for ImportHandler: no OCP\IUserSession',
			],
			$logged
		);
	}//end testMissingOptionalImportServicesAreLoggedAtTheirLevel()

	/**
	 * A container double that records every id it is asked for.
	 *
	 * @param array $requested The ids asked for, in order.
	 *
	 * @return IAppContainer The container.
	 */
	private function recordingContainer(array &$requested): IAppContainer {
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$requested) {
				$requested[] = $id;
				return $this->createMock($id);
			}
		);

		return $container;
	}//end recordingContainer()

	/**
	 * The ids each factory asks for, in order, keyed by the registered name.
	 *
	 * @return array<string, list<string>>
	 */
	private static function expectedRequests(): array {
		return [
			'OCA\\OpenRegister\\Service\\Configuration\\UploadHandler' => [
				'Psr\\Log\\LoggerInterface',
			],
			'OCA\\OpenRegister\\Service\\Configuration\\GitHubHandler' => [
				'OCP\\Http\\Client\\IClientService',
				'OCP\\IAppConfig',
				'OCP\\IConfig',
				'OCP\\ICacheFactory',
				'OCA\\OpenRegister\\Service\\Configuration\\AttributionFormatter',
				'Psr\\Log\\LoggerInterface',
			],
			'OCA\\OpenRegister\\Service\\Configuration\\ImportHandler' => [
				'OCP\\IConfig',
				'Psr\\Log\\LoggerInterface',
				'OCA\\OpenRegister\\Service\\ShippedBaseline\\ShippedConfigurationGuard',
				'OCA\\OpenRegister\\Service\\Configuration\\AppImportJobRecorder',
				'OCA\\OpenRegister\\Service\\Configuration\\SchemaImportInstaller',
				'OCA\\OpenRegister\\Db\\SchemaMapperInterface',
				'OCA\\OpenRegister\\Db\\RegisterMapperInterface',
				'OCA\\OpenRegister\\Db\\MagicMapperInterface',
				'OCA\\OpenRegister\\Db\\ConfigurationMapper',
				'OCA\\OpenRegister\\Db\\MappingMapper',
				'OCP\\IAppConfig',
				'OCA\\OpenRegister\\Kernel\\Contracts\\ObjectOperationsInterface',
				'OCA\\OpenRegister\\Db\\MagicMapperInterface',
				'OCA\\OpenRegister\\Db\\MagicMapperInterface',
				'OCA\\OpenRegister\\Service\\FileService',
				'OCA\\OpenRegister\\Files\\Service\\File\\RegisterFolderProvisionerInterface',
				'OCA\\OpenRegister\\Kernel\\Contracts\\NoteServiceInterface',
				'OCA\\OpenRegister\\Service\\TaskService',
				'OCP\\IUserSession',
				'OCP\\IGroupManager',
				'OCP\\IUserManager',
				'OCA\\OpenRegister\\Service\\Authorization\\GroupProvisioner',
				'OCA\\OpenRegister\\Service\\Schema\\SchemaVersioningService',
				'OCA\\OpenRegister\\Service\\Archival\\SelectionListSeeder',
				'OCA\\OpenRegister\\Service\\Object\\SaveObject\\ObjectSlugHandler',
			],
			'OCA\\OpenRegister\\Service\\Configuration\\ExportHandler' => [
				'OCA\\OpenRegister\\Db\\SchemaMapperInterface',
				'OCA\\OpenRegister\\Db\\RegisterMapperInterface',
				'OCA\\OpenRegister\\Db\\MagicMapperInterface',
				'OCA\\OpenRegister\\Db\\ConfigurationMapper',
				'OCA\\OpenRegister\\Db\\MappingMapper',
				'Psr\\Log\\LoggerInterface',
			],
			'OCA\\OpenRegister\\Service\\ConfigurationService' => [
				'OCA\\OpenRegister\\Db\\ConfigurationMapper',
				'OCP\\App\\IAppManager',
				'OCP\\IAppConfig',
				'Psr\\Log\\LoggerInterface',
				'OCA\\OpenRegister\\Service\\Configuration\\GitHubHandler',
				'OCA\\OpenRegister\\Service\\Configuration\\GitLabHandler',
				'OCA\\OpenRegister\\Service\\Configuration\\CacheHandler',
				'OCA\\OpenRegister\\Service\\Configuration\\PreviewHandler',
				'OCA\\OpenRegister\\Service\\Configuration\\ExportHandler',
				'OCA\\OpenRegister\\Service\\Configuration\\UploadHandler',
			],
			'OCA\\OpenRegister\\Service\\Settings\\ValidationOperationsHandler' => [
				'OCA\\OpenRegister\\Db\\SchemaMapperInterface',
				'Psr\\Log\\LoggerInterface',
			],
			'OCA\\OpenRegister\\Service\\SettingsService' => [
				'OCP\\IConfig',
				'Psr\\Log\\LoggerInterface',
				'OCP\\IDBConnection',
				'OCA\\OpenRegister\\Service\\Settings\\ValidationOperationsHandler',
				'OCA\\OpenRegister\\Service\\Settings\\SearchBackendHandler',
				'OCA\\OpenRegister\\Service\\Settings\\LlmSettingsHandler',
				'OCA\\OpenRegister\\Service\\Settings\\FileSettingsHandler',
				'OCA\\OpenRegister\\Service\\Settings\\ObjectRetentionHandler',
				'OCA\\OpenRegister\\Service\\Settings\\CacheSettingsHandler',
				'OCA\\OpenRegister\\Service\\Settings\\ConfigurationSettingsHandler',
			],
			'OCA\\OpenRegister\\Service\\TenantKeyService' => [
				'OCP\\IDBConnection',
				'OCP\\Security\\ICrypto',
				'Psr\\Log\\LoggerInterface',
			],
			'OCA\\OpenRegister\\Service\\ShippedBaseline\\ShippedBaselineStore' => [
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationValueStore',
				'Psr\\Log\\LoggerInterface',
			],
			'OCA\\OpenRegister\\Service\\ShippedBaseline\\ShippedAuthorizationDecision' => [],
			'OCA\\OpenRegister\\Service\\ShippedBaseline\\ShippedConfigurationGuard' => [
				'OCA\\OpenRegister\\Service\\ShippedBaseline\\ShippedBaselineStore',
				'OCA\\OpenRegister\\Service\\ShippedBaseline\\ShippedAuthorizationDecision',
				'OCA\\OpenRegister\\Db\\AuditTrailMapperInterface',
				'OCP\\IUserSession',
				'Psr\\Log\\LoggerInterface',
			],
			'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationValueStore' => [
				'OCA\\OpenRegister\\Db\\ConfigurationValueMapper',
				'OCP\\IAppConfig',
			],
			'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationDraftService' => [
				'OCA\\OpenRegister\\Db\\ConfigurationDraftSetMapperInterface',
				'OCA\\OpenRegister\\Db\\ConfigurationDraftMapperInterface',
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationValueStore',
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationKeyRegistry',
				'OCP\\IUserSession',
				'OCP\\IAppConfig',
			],
			'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\DeploymentPreviewService' => [
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationDraftService',
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationValueStore',
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationKeyRegistry',
			],
			'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\DeploymentService' => [
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationDraftService',
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\DeploymentPreviewService',
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationValueStore',
				'OCA\\OpenRegister\\Db\\ConfigurationDeploymentMapperInterface',
				'OCA\\OpenRegister\\Db\\ConfigurationDraftSetMapperInterface',
				'OCP\\IDBConnection',
				'OCP\\IUserSession',
				'Psr\\Log\\LoggerInterface',
			],
			'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationExplainer' => [
				'OCA\\OpenRegister\\Service\\ConfigurationDeployment\\ConfigurationValueStore',
				'OCA\\OpenRegister\\Db\\ConfigurationDeploymentMapperInterface',
			],
		];
	}//end expectedRequests()
}//end class
