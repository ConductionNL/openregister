<?php

/**
 * The configuration, settings and deployment service registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCA\OpenRegister\Db\ConfigurationDraftMapper;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapper;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\Configuration\CacheHandler as ConfigurationCacheHandler;
use OCA\OpenRegister\Service\Configuration\ExportHandler as ConfigurationExportHandler;
use OCA\OpenRegister\Service\Configuration\ImportHandler as ConfigurationImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler as ConfigurationUploadHandler;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCA\OpenRegister\Service\Settings\ValidationOperationsHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Service\TenantKeyService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers the configuration, settings and configuration deployment services:
 * import and export handlers, the GitHub and GitLab handlers, the settings
 * services and the deployment services.
 *
 * Application::register() calls register() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every service it wires.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Factory closures list every constructor argument.
 */
final class ConfigurationServiceRegistrar {

	/**
	 * Register configuration-related services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerService(
			ConfigurationUploadHandler::class,
			function (ContainerInterface $container) {
				return new ConfigurationUploadHandler(
					client: new Client(),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register GitHubHandler explicitly to fix IConfig auto-wiring issue.
		$context->registerService(
			GitHubHandler::class,
			function (ContainerInterface $container) {
				return new GitHubHandler(
					client: $container->get('OCP\Http\Client\IClientService')->newClient(),
					appConfig: $container->get('OCP\IAppConfig'),
					config: $container->get('OCP\IConfig'),
					cacheFactory: $container->get('OCP\ICacheFactory'),
					attributionFormatter: $container->get('OCA\OpenRegister\Service\Configuration\AttributionFormatter'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register ImportHandler (with both alias and real name to prevent auto-wiring conflicts).
		$importHandlerFactory = function (
			ContainerInterface $container,
		): \OCA\OpenRegister\Service\Configuration\ImportHandler {
			return self::buildImportHandler(container: $container);
		};

		// Register under alias.
		$context->registerService(ConfigurationImportHandler::class, $importHandlerFactory);

		// Register under real class name (pointing to same factory).
		$context->registerService(
			'OCA\OpenRegister\Service\Configuration\ImportHandler',
			$importHandlerFactory
		);

		// Register ExportHandler with workflow dependencies.
		$context->registerService(
			ConfigurationExportHandler::class,
			function (ContainerInterface $container): ConfigurationExportHandler {
				$exportHandler = new ConfigurationExportHandler(
					schemaMapper: $container->get(SchemaMapper::class),
					registerMapper: $container->get(RegisterMapper::class),
					objectEntityMapper: $container->get(MagicMapper::class),
					configurationMapper: $container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
					mappingMapper: $container->get(MappingMapper::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);

				return $exportHandler;
			}
		);

		$context->registerService(
			ConfigurationService::class,
			function (ContainerInterface $container) {
				$dataDir = $container->get('OCP\IConfig')->getSystemValue('datadirectory', '');
				$appDataPath = $dataDir . '/appdata_openregister';

				return new ConfigurationService(
					schemaMapper: $container->get(SchemaMapper::class),
					registerMapper: $container->get(RegisterMapper::class),
					configurationMapper: $container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
					appManager: $container->get('OCP\App\IAppManager'),
					container: $container,
					appConfig: $container->get('OCP\IAppConfig'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					client: new Client(),
					objectService: $container->get(ObjectService::class),
					githubHandler: $container->get(GitHubHandler::class),
					gitlabHandler: $container->get(GitLabHandler::class),
					cacheHandler: $container->get(ConfigurationCacheHandler::class),
					previewHandler: $container->get(PreviewHandler::class),
					exportHandler: $container->get(ConfigurationExportHandler::class),
					// NOTE: ImportHandler is lazy-loaded in ConfigurationService to prevent circular dependency.
					uploadHandler: $container->get(ConfigurationUploadHandler::class),
					appDataPath: $appDataPath
				);
			}
		);

		self::registerSettingsServices(context: $context);
		self::registerConfigurationDeploymentServices(context: $context);
	}//end register()

	/**
	 * Build the configuration ImportHandler with everything it can reach.
	 *
	 * Lifted out of the registration closure so the registration reads as a
	 * list of registrations. The wiring below is unchanged, including which
	 * parts of it are allowed to be missing.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ConfigurationImportHandler The handler.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildImportHandler(ContainerInterface $container): ConfigurationImportHandler {
		$dataDir = $container->get('OCP\IConfig')->getSystemValue('datadirectory', '');
		$appDataPath = $dataDir . '/appdata_openregister';

		$logger = $container->get('Psr\Log\LoggerInterface');

		// The guard that keeps a local change to an app-shipped schema alive
		// across an upgrade (row 11.36). Optional on purpose: an instance whose
		// container cannot build it imports exactly as it did before the guard
		// existed, which is a known state rather than a broken one, and an
		// unattended `occ upgrade` must finish.
		$shippedGuard = null;
		try {
			$shippedGuard = $container->get(ShippedConfigurationGuard::class);
		} catch (\Throwable $e) {
			$logger->debug('[Application] ShippedConfigurationGuard unavailable for ImportHandler: ' . $e->getMessage());
		}

		// Stamps each app import with an import job id so a setup wizard can
		// remove the example set it loaded. Resolved like the guard above, but
		// its absence is logged as a warning: without it app imports cannot be
		// removed by job, which somebody should hear about.
		$importJobRecorder = null;
		try {
			$importJobRecorder = $container->get(\OCA\OpenRegister\Service\Configuration\AppImportJobRecorder::class);
		} catch (\Throwable $e) {
			$logger->warning('[Application] AppImportJobRecorder unavailable for ImportHandler: ' . $e->getMessage());
		}

		// Installs a schema's flows and notification webhooks when an import
		// changed nothing and so fired no SchemaUpdatedEvent. Resolved like the
		// guard: without it an unchanged re-import restores nothing it declares.
		$schemaInstaller = null;
		try {
			$schemaInstaller = $container->get(\OCA\OpenRegister\Service\Configuration\SchemaImportInstaller::class);
		} catch (\Throwable $e) {
			$logger->warning('[Application] SchemaImportInstaller unavailable for ImportHandler: ' . $e->getMessage());
		}

		$importHandler = new ConfigurationImportHandler(
			schemaMapper: $container->get(SchemaMapper::class),
			registerMapper: $container->get(RegisterMapper::class),
			objectEntityMapper: $container->get(MagicMapper::class),
			configurationMapper: $container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
			mappingMapper: $container->get(MappingMapper::class),
			client: new Client(),
			appConfig: $container->get('OCP\IAppConfig'),
			logger: $logger,
			appDataPath: $appDataPath,
			uploadHandler: $container->get(ConfigurationUploadHandler::class),
			objectService: $container->get(ObjectService::class),
			shippedGuard: $shippedGuard,
			importJobRecorder: $importJobRecorder,
			schemaInstaller: $schemaInstaller
		);

		// Inject MagicMapper for pre-creating magic mapper tables before seed
		// data import, and for routing seed data to the correct magic table.
		$importHandler->setMagicMapper($container->get(MagicMapper::class));
		$importHandler->setObjectMapper($container->get(MagicMapper::class));

		self::attachOptionalImportServices(
			importHandler: $importHandler,
			container: $container,
			logger: $logger
		);

		return $importHandler;
	}//end buildImportHandler()

	/**
	 * Attach the import services that are allowed to be missing.
	 *
	 * Each of these is optional on purpose, and each `catch` says which one
	 * was not there. A missing dependency must not break import for an app
	 * that does not seed related items, does not run under a session, or does
	 * not provision groups.
	 *
	 * @param ConfigurationImportHandler $importHandler The handler being built.
	 * @param ContainerInterface         $container     The container.
	 * @param LoggerInterface            $logger        Where an absence is noted.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function attachOptionalImportServices(
		ConfigurationImportHandler $importHandler,
		ContainerInterface $container,
		LoggerInterface $logger
	): void {
		// Setter => [service id, the name the log line used before this list existed].
		$optional = [
			'setFileService' => [\OCA\OpenRegister\Service\FileService::class, 'FileService'],
			'setRegisterFolderProvisioner' => [
				\OCA\OpenRegister\Service\File\RegisterFolderProvisioner::class,
				'RegisterFolderProvisioner',
			],
			'setNoteService' => [\OCA\OpenRegister\Service\NoteService::class, 'NoteService'],
			'setTaskService' => [\OCA\OpenRegister\Service\TaskService::class, 'TaskService'],
			'setUserSession' => ['OCP\IUserSession', 'IUserSession'],
			'setGroupManager' => ['OCP\IGroupManager', 'IGroupManager'],
			'setUserManager' => ['OCP\IUserManager', 'IUserManager'],
			'setGroupProvisioner' => [
				\OCA\OpenRegister\Service\Authorization\GroupProvisioner::class,
				'GroupProvisioner',
			],
			// Classifies, versions and logs the schema changes an import makes (#4102).
			'setSchemaVersioning' => [
				SchemaVersioningService::class,
				'SchemaVersioningService',
			],
			// Writes the selectielijst categories an app ships (`components.selectionLists`).
			'setSelectionListSeeder' => [
				\OCA\OpenRegister\Service\Archival\SelectionListSeeder::class,
				'SelectionListSeeder',
			],
		];

		foreach ($optional as $setter => $service) {
			[$id, $label] = $service;

			try {
				$importHandler->{$setter}($container->get($id));
			} catch (\Throwable $e) {
				$logger->debug('[Application] ' . $label . ' unavailable for ImportHandler: ' . $e->getMessage());
			}
		}
	}//end attachOptionalImportServices()

	/**
	 * Register settings-related services including handlers.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function registerSettingsServices(IRegistrationContext $context): void {
		$context->registerService(
			ValidationOperationsHandler::class,
			function (ContainerInterface $container) {
				return new ValidationOperationsHandler(
					validateHandler: null,
					schemaMapper: $container->get(SchemaMapper::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					container: $container
				);
			}
		);

		$context->registerService(
			SettingsService::class,
			function (ContainerInterface $container) {
				return new SettingsService(
					config: $container->get('OCP\IConfig'),
					auditTrailMapper: $container->get(AuditTrailMapper::class),
					cacheFactory: $container->get('OCP\ICacheFactory'),
					groupManager: $container->get('OCP\IGroupManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					organisationMapper: $container->get(OrganisationMapper::class),
					schemaCacheService: $container->get(SchemaCacheHandler::class),
					facetCacheSvc: $container->get(FacetCacheHandler::class),
					searchTrailMapper: $container->get(SearchTrailMapper::class),
					userManager: $container->get('OCP\IUserManager'),
					db: $container->get('OCP\IDBConnection'),
					objectCacheService: null,
					container: $container,
					appName: 'openregister',
					validOpsHandler: $container->get(ValidationOperationsHandler::class),
					searchBackendHandler: $container->get(SearchBackendHandler::class),
					llmSettingsHandler: $container->get(LlmSettingsHandler::class),
					fileSettingsHandler: $container->get(FileSettingsHandler::class),
					objRetentionHandler: $container->get(ObjectRetentionHandler::class),
					cacheSettingsHandler: $container->get(CacheSettingsHandler::class),
					cfgSettingsHandler: $container->get(ConfigurationSettingsHandler::class)
				);
			}
		);

		// Register TenantKeyService for audit-trail HMAC key management.
		$context->registerService(
			TenantKeyService::class,
			function (ContainerInterface $container) {
				return new TenantKeyService(
					db: $container->get('OCP\IDBConnection'),
					crypto: $container->get('OCP\Security\ICrypto'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);
	}//end registerSettingsServices()

	/**
	 * Register the configuration deployment lifecycle.
	 *
	 * Registered explicitly rather than autowired: every class here takes the
	 * app name as a defaulted string, and a container that resolves a scalar
	 * by guessing is a container that silently builds the wrong object.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function registerConfigurationDeploymentServices(IRegistrationContext $context): void {
		// The shipped-baseline guard reuses the deployment value store rather
		// than growing a second place to keep configuration about a schema,
		// which is why it is registered here beside it and not in a corner of
		// its own (row 11.36, ADR-012).
		$context->registerService(
			ShippedBaselineStore::class,
			function (ContainerInterface $container) {
				return new ShippedBaselineStore(
					values: $container->get(ConfigurationValueStore::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// The administrator's decision to take shipped authorization MUST be
		// shared: the re-import opens it (CountedRegisterImport) and the guard
		// deep in the import stack reads it, also when the import runs through
		// another app's SettingsService. Two instances would let the re-import
		// report a decision the guard never saw.
		$context->registerService(
			ShippedAuthorizationDecision::class,
			static function () {
				return new ShippedAuthorizationDecision();
			}
		);

		$context->registerService(
			ShippedConfigurationGuard::class,
			function (ContainerInterface $container) {
				$parts = new DescriptorParts();
				$comparator = new DivergenceComparator(parts: $parts);

				return new ShippedConfigurationGuard(
					baselines: $container->get(ShippedBaselineStore::class),
					merge: new GuardedDescriptorMerge(
						parts: $parts,
						comparator: $comparator,
						decision: $container->get(ShippedAuthorizationDecision::class)
					),
					comparator: $comparator,
					audit: $container->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
					session: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			ConfigurationValueStore::class,
			function (ContainerInterface $container) {
				return new ConfigurationValueStore(
					values: $container->get(ConfigurationValueMapper::class),
					appConfig: $container->get('OCP\IAppConfig'),
					appName: 'openregister'
				);
			}
		);

		$context->registerService(
			ConfigurationDraftService::class,
			function (ContainerInterface $container) {
				return new ConfigurationDraftService(
					sets: $container->get(ConfigurationDraftSetMapper::class),
					drafts: $container->get(ConfigurationDraftMapper::class),
					store: $container->get(ConfigurationValueStore::class),
					registry: $container->get(ConfigurationKeyRegistry::class),
					session: $container->get('OCP\IUserSession'),
					appConfig: $container->get('OCP\IAppConfig'),
					appName: 'openregister'
				);
			}
		);

		$context->registerService(
			DeploymentPreviewService::class,
			function (ContainerInterface $container) {
				return new DeploymentPreviewService(
					drafts: $container->get(ConfigurationDraftService::class),
					store: $container->get(ConfigurationValueStore::class),
					registry: $container->get(ConfigurationKeyRegistry::class)
				);
			}
		);

		$context->registerService(
			DeploymentService::class,
			function (ContainerInterface $container) {
				return new DeploymentService(
					drafts: $container->get(ConfigurationDraftService::class),
					previews: $container->get(DeploymentPreviewService::class),
					store: $container->get(ConfigurationValueStore::class),
					deployments: $container->get(ConfigurationDeploymentMapper::class),
					sets: $container->get(ConfigurationDraftSetMapper::class),
					db: $container->get('OCP\IDBConnection'),
					session: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			ConfigurationExplainer::class,
			function (ContainerInterface $container) {
				return new ConfigurationExplainer(
					store: $container->get(ConfigurationValueStore::class),
					deployments: $container->get(ConfigurationDeploymentMapper::class)
				);
			}
		);

	}//end registerConfigurationDeploymentServices()
}//end class
