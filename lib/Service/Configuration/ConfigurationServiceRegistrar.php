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
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapperInterface;
use OCA\OpenRegister\Db\ConfigurationDraftMapperInterface;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapperInterface;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Configuration\CacheHandler as ConfigurationCacheHandler;
use OCA\OpenRegister\Service\Configuration\ExportHandler as ConfigurationExportHandler;
use OCA\OpenRegister\Service\Configuration\ImportHandler as ConfigurationImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler as ConfigurationUploadHandler;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRecords;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentValues;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
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
use OCP\IContainer;
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
 */
final class ConfigurationServiceRegistrar {

	/**
	 * The import services that are allowed to be missing.
	 *
	 * Setter => [service id, the name the log line used before this list existed].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const OPTIONAL_IMPORT_SERVICES = [
		'setFileService' => [\OCA\OpenRegister\Service\FileService::class, 'FileService'],
		'setRegisterFolderProvisioner' => [
			\OCA\OpenRegister\Files\Service\File\RegisterFolderProvisionerInterface::class,
			'RegisterFolderProvisioner',
		],
		'setNoteService' => [\OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface::class, 'NoteService'],
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
		// Decides the slug of a seed object, so a seed cannot take a slug another
		// live object holds (#4037); without it a seed slug is set as before.
		'setSlugHandler' => [
			\OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler::class,
			'ObjectSlugHandler',
		],
	];

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
		$context->registerService(ConfigurationUploadHandler::class, self::buildUploadHandler(...));

		// Register GitHubHandler explicitly to fix IConfig auto-wiring issue.
		$context->registerService(GitHubHandler::class, self::buildGitHubHandler(...));

		// Register ImportHandler (with both alias and real name to prevent auto-wiring conflicts).
		$importHandlerFactory = self::buildImportHandler(...);

		// Register under alias.
		$context->registerService(ConfigurationImportHandler::class, $importHandlerFactory);

		// Register under real class name (pointing to same factory).
		$context->registerService(
			'OCA\OpenRegister\Service\Configuration\ImportHandler',
			$importHandlerFactory
		);

		// Register ExportHandler with workflow dependencies.
		$context->registerService(ConfigurationExportHandler::class, self::buildExportHandler(...));
		$context->registerService(ConfigurationService::class, self::buildConfigurationService(...));

		self::registerSettingsServices(context: $context);
		self::registerConfigurationDeploymentServices(context: $context);
	}//end register()

	/**
	 * Build the configuration UploadHandler.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ConfigurationUploadHandler The handler.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildUploadHandler(ContainerInterface $container): ConfigurationUploadHandler {
		return new ConfigurationUploadHandler(
			client: new Client(),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end buildUploadHandler()

	/**
	 * Build the GitHubHandler.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return GitHubHandler The handler.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildGitHubHandler(ContainerInterface $container): GitHubHandler {
		return new GitHubHandler(
			client: $container->get('OCP\Http\Client\IClientService')->newClient(),
			appConfig: $container->get('OCP\IAppConfig'),
			config: $container->get('OCP\IConfig'),
			cacheFactory: $container->get('OCP\ICacheFactory'),
			attributionFormatter: $container->get('OCA\OpenRegister\Service\Configuration\AttributionFormatter'),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end buildGitHubHandler()

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
		$run           = self::startImportHandlerBuild(container: $container);
		$importHandler = self::newImportHandler(run: $run);

		// Inject MagicMapper for pre-creating magic mapper tables before seed
		// data import, and for routing seed data to the correct magic table.
		$importHandler->setMagicMapper($container->get(MagicMapperInterface::class));
		$importHandler->setObjectMapper($container->get(MagicMapperInterface::class));

		self::attachOptionalImportServices(
			importHandler: $importHandler,
			container: $container,
			logger: $run->logger
		);

		return $importHandler;
	}//end buildImportHandler()

	/**
	 * Start an ImportHandler build: the app data path, then the logger.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ImportHandlerBuildRun The build.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function startImportHandlerBuild(ContainerInterface $container): ImportHandlerBuildRun {
		$dataDir = $container->get('OCP\IConfig')->getSystemValue('datadirectory', '');

		$run = new ImportHandlerBuildRun(
			container: $container,
			logger: $container->get('Psr\Log\LoggerInterface')
		);
		$run->appDataPath = $dataDir . '/appdata_openregister';

		return $run;
	}//end startImportHandlerBuild()

	/**
	 * Construct the ImportHandler, resolving its three optional services first.
	 *
	 * The optional services are named first so they are resolved, and their
	 * absence logged, before any of the required services are asked for.
	 *
	 * @param ImportHandlerBuildRun $run The build.
	 *
	 * @return ConfigurationImportHandler The handler.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function newImportHandler(ImportHandlerBuildRun $run): ConfigurationImportHandler {
		return new ConfigurationImportHandler(
			shippedGuard: self::resolveShippedGuard(run: $run),
			importJobRecorder: self::resolveImportJobRecorder(run: $run),
			schemaInstaller: self::resolveSchemaInstaller(run: $run),
			schemaMapper: $run->container->get(SchemaMapperInterface::class),
			registerMapper: $run->container->get(RegisterMapperInterface::class),
			objectEntityMapper: $run->container->get(MagicMapperInterface::class),
			configurationMapper: $run->container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
			mappingMapper: $run->container->get(MappingMapper::class),
			appConfig: $run->container->get('OCP\IAppConfig'),
			logger: $run->logger,
			appDataPath: $run->appDataPath,
			objectService: $run->container->get(ObjectOperationsInterface::class)
		);
	}//end newImportHandler()

	/**
	 * Resolve the guard that keeps a local change to an app-shipped schema alive
	 * across an upgrade (row 11.36).
	 *
	 * Optional on purpose: an instance whose container cannot build it imports
	 * exactly as it did before the guard existed, which is a known state rather
	 * than a broken one, and an unattended `occ upgrade` must finish.
	 *
	 * @param ImportHandlerBuildRun $run The build.
	 *
	 * @return ShippedConfigurationGuard|null The guard, or null when it is not there.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function resolveShippedGuard(ImportHandlerBuildRun $run): ?ShippedConfigurationGuard {
		try {
			return $run->container->get(ShippedConfigurationGuard::class);
		} catch (\Throwable $e) {
			$run->logger->debug('[Application] ShippedConfigurationGuard unavailable for ImportHandler: ' . $e->getMessage());
		}

		return null;
	}//end resolveShippedGuard()

	/**
	 * Resolve the recorder that stamps each app import with an import job id,
	 * so a setup wizard can remove the example set it loaded.
	 *
	 * Resolved like the guard, but its absence is logged as a warning: without
	 * it app imports cannot be removed by job, which somebody should hear about.
	 *
	 * @param ImportHandlerBuildRun $run The build.
	 *
	 * @return AppImportJobRecorder|null The recorder, or null when it is not there.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function resolveImportJobRecorder(ImportHandlerBuildRun $run): ?AppImportJobRecorder {
		try {
			return $run->container->get(AppImportJobRecorder::class);
		} catch (\Throwable $e) {
			$run->logger->warning('[Application] AppImportJobRecorder unavailable for ImportHandler: ' . $e->getMessage());
		}

		return null;
	}//end resolveImportJobRecorder()

	/**
	 * Resolve the installer of a schema's flows and notification webhooks when an
	 * import changed nothing and so fired no SchemaUpdatedEvent.
	 *
	 * Resolved like the guard: without it an unchanged re-import restores nothing
	 * it declares, which is logged as a warning.
	 *
	 * @param ImportHandlerBuildRun $run The build.
	 *
	 * @return SchemaImportInstaller|null The installer, or null when it is not there.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function resolveSchemaInstaller(ImportHandlerBuildRun $run): ?SchemaImportInstaller {
		try {
			return $run->container->get(SchemaImportInstaller::class);
		} catch (\Throwable $e) {
			$run->logger->warning('[Application] SchemaImportInstaller unavailable for ImportHandler: ' . $e->getMessage());
		}

		return null;
	}//end resolveSchemaInstaller()

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
		foreach (self::OPTIONAL_IMPORT_SERVICES as $setter => $service) {
			[$id, $label] = $service;

			try {
				$importHandler->{$setter}($container->get($id));
			} catch (\Throwable $e) {
				$logger->debug('[Application] ' . $label . ' unavailable for ImportHandler: ' . $e->getMessage());
			}
		}
	}//end attachOptionalImportServices()


	/**
	 * Build the configuration ExportHandler with its workflow dependencies.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ConfigurationExportHandler The handler.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildExportHandler(ContainerInterface $container): ConfigurationExportHandler {
		$exportHandler = new ConfigurationExportHandler(
			schemaMapper: $container->get(SchemaMapperInterface::class),
			registerMapper: $container->get(RegisterMapperInterface::class),
			objectEntityMapper: $container->get(MagicMapperInterface::class),
			configurationMapper: $container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
			mappingMapper: $container->get(MappingMapper::class),
			logger: $container->get('Psr\Log\LoggerInterface')
		);

		return $exportHandler;
	}//end buildExportHandler()

	/**
	 * Build the ConfigurationService from its platform, git hosts and transfer handlers.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ConfigurationService The service.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildConfigurationService(ContainerInterface $container): ConfigurationService {
		return new ConfigurationService(
			configurationMapper: $container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
			platform: self::buildConfigurationPlatform(container: $container),
			gitHosts: new ConfigurationGitHosts(
				githubHandler: $container->get(GitHubHandler::class),
				gitlabHandler: $container->get(GitLabHandler::class)
			),
			// NOTE: ImportHandler is lazy-loaded in ConfigurationService to prevent circular dependency.
			transfer: self::buildTransferHandlers(container: $container)
		);
	}//end buildConfigurationService()

	/**
	 * Build the platform the ConfigurationService runs on.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ConfigurationServicePlatform The platform.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildConfigurationPlatform(ContainerInterface $container): ConfigurationServicePlatform {
		return new ConfigurationServicePlatform(
			appManager: $container->get('OCP\App\IAppManager'),
			container: $container,
			appConfig: $container->get('OCP\IAppConfig'),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end buildConfigurationPlatform()

	/**
	 * Build the cache, preview, export and upload handlers the ConfigurationService transfers with.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ConfigurationTransferHandlers The handlers.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildTransferHandlers(ContainerInterface $container): ConfigurationTransferHandlers {
		return new ConfigurationTransferHandlers(
			cacheHandler: $container->get(ConfigurationCacheHandler::class),
			previewHandler: $container->get(PreviewHandler::class),
			exportHandler: $container->get(ConfigurationExportHandler::class),
			uploadHandler: $container->get(ConfigurationUploadHandler::class)
		);
	}//end buildTransferHandlers()

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
		$context->registerService(ValidationOperationsHandler::class, self::buildValidationOperationsHandler(...));
		$context->registerService(SettingsService::class, self::buildSettingsService(...));

		// Register TenantKeyService for audit-trail HMAC key management.
		$context->registerService(TenantKeyService::class, self::buildTenantKeyService(...));
	}//end registerSettingsServices()

	/**
	 * Build the ValidationOperationsHandler.
	 *
	 * @param IContainer $container The container; the ValidationOperationsHandler keeps it.
	 *
	 * @return ValidationOperationsHandler The handler.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildValidationOperationsHandler(IContainer $container): ValidationOperationsHandler {
		return new ValidationOperationsHandler(
			validateHandler: null,
			schemaMapper: $container->get(SchemaMapperInterface::class),
			logger: $container->get('Psr\Log\LoggerInterface'),
			container: $container
		);
	}//end buildValidationOperationsHandler()

	/**
	 * Build the SettingsService from its settings handlers.
	 *
	 * @param IContainer $container The container; the SettingsService keeps it.
	 *
	 * @return SettingsService The service.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildSettingsService(IContainer $container): SettingsService {
		return new SettingsService(
			config: $container->get('OCP\IConfig'),
			logger: $container->get('Psr\Log\LoggerInterface'),
			db: $container->get('OCP\IDBConnection'),
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
	}//end buildSettingsService()

	/**
	 * Build the TenantKeyService.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return TenantKeyService The service.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function buildTenantKeyService(ContainerInterface $container): TenantKeyService {
		return new TenantKeyService(
			db: $container->get('OCP\IDBConnection'),
			crypto: $container->get('OCP\Security\ICrypto'),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end buildTenantKeyService()

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
		$context->registerService(ShippedBaselineStore::class, self::buildShippedBaselineStore(...));

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

		$context->registerService(ShippedConfigurationGuard::class, self::buildShippedConfigurationGuard(...));
		$context->registerService(ConfigurationValueStore::class, self::buildConfigurationValueStore(...));
		$context->registerService(ConfigurationDraftService::class, self::buildConfigurationDraftService(...));
		$context->registerService(DeploymentPreviewService::class, self::buildDeploymentPreviewService(...));
		$context->registerService(DeploymentService::class, self::buildDeploymentService(...));
		$context->registerService(ConfigurationExplainer::class, self::buildConfigurationExplainer(...));
	}//end registerConfigurationDeploymentServices()

	/**
	 * Build the ShippedBaselineStore on the deployment value store.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ShippedBaselineStore The store.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function buildShippedBaselineStore(ContainerInterface $container): ShippedBaselineStore {
		return new ShippedBaselineStore(
			values: $container->get(ConfigurationValueStore::class),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end buildShippedBaselineStore()

	/**
	 * Build the ShippedConfigurationGuard; its merge and itself share one comparator.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ShippedConfigurationGuard The guard.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function buildShippedConfigurationGuard(ContainerInterface $container): ShippedConfigurationGuard {
		$parts      = new DescriptorParts();
		$comparator = new DivergenceComparator(parts: $parts);

		return new ShippedConfigurationGuard(
			baselines: $container->get(ShippedBaselineStore::class),
			merge: new GuardedDescriptorMerge(
				parts: $parts,
				comparator: $comparator,
				decision: $container->get(ShippedAuthorizationDecision::class)
			),
			comparator: $comparator,
			audit: $container->get(AuditTrailMapperInterface::class),
			session: $container->get('OCP\IUserSession'),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end buildShippedConfigurationGuard()

	/**
	 * Build the ConfigurationValueStore.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ConfigurationValueStore The store.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function buildConfigurationValueStore(ContainerInterface $container): ConfigurationValueStore {
		return new ConfigurationValueStore(
			values: $container->get(ConfigurationValueMapper::class),
			appConfig: $container->get('OCP\IAppConfig'),
			appName: 'openregister'
		);
	}//end buildConfigurationValueStore()

	/**
	 * Build the ConfigurationDraftService.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ConfigurationDraftService The service.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function buildConfigurationDraftService(ContainerInterface $container): ConfigurationDraftService {
		return new ConfigurationDraftService(
			sets: $container->get(ConfigurationDraftSetMapperInterface::class),
			drafts: $container->get(ConfigurationDraftMapperInterface::class),
			store: $container->get(ConfigurationValueStore::class),
			registry: $container->get(ConfigurationKeyRegistry::class),
			session: $container->get('OCP\IUserSession'),
			appConfig: $container->get('OCP\IAppConfig'),
			appName: 'openregister'
		);
	}//end buildConfigurationDraftService()

	/**
	 * Build the DeploymentPreviewService.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return DeploymentPreviewService The service.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function buildDeploymentPreviewService(ContainerInterface $container): DeploymentPreviewService {
		return new DeploymentPreviewService(
			drafts: $container->get(ConfigurationDraftService::class),
			store: $container->get(ConfigurationValueStore::class),
			registry: $container->get(ConfigurationKeyRegistry::class)
		);
	}//end buildDeploymentPreviewService()

	/**
	 * Build the DeploymentService.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return DeploymentService The service.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function buildDeploymentService(ContainerInterface $container): DeploymentService {
		return new DeploymentService(
			values: new DeploymentValues(
				drafts: $container->get(ConfigurationDraftService::class),
				previews: $container->get(DeploymentPreviewService::class),
				store: $container->get(ConfigurationValueStore::class)
			),
			records: new DeploymentRecords(
				deployments: $container->get(ConfigurationDeploymentMapperInterface::class),
				sets: $container->get(ConfigurationDraftSetMapperInterface::class),
				db: $container->get('OCP\IDBConnection')
			),
			session: $container->get('OCP\IUserSession'),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end buildDeploymentService()

	/**
	 * Build the ConfigurationExplainer.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ConfigurationExplainer The explainer.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function buildConfigurationExplainer(ContainerInterface $container): ConfigurationExplainer {
		return new ConfigurationExplainer(
			store: $container->get(ConfigurationValueStore::class),
			deployments: $container->get(ConfigurationDeploymentMapperInterface::class)
		);
	}//end buildConfigurationExplainer()
}//end class
