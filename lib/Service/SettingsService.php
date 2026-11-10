<?php

/**
 * OpenRegister Settings Service
 *
 * This file contains the service class for handling settings in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCA\OpenRegister\Service\Settings\ValidationOperationsHandler;
use OCP\AppFramework\IAppContainer;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for handling settings-related operations.
 *
 * This service is responsible ONLY for storing and retrieving application settings.
 * It does NOT contain business logic for testing or using the configured services.
 *
 * RESPONSIBILITIES:
 * - Store and retrieve settings from Nextcloud's IAppConfig
 * - Provide default values for unconfigured settings
 * - Manage settings for: RBAC, Multitenancy, Retention, LLM, Files, Objects
 * - Get available options (groups, users, tenants) for settings UI
 * - Rebase operations (apply default owners/tenants to existing objects)
 * - Cache management statistics and operations
 *
 * WHAT THIS SERVICE DOES NOT DO:
 * - Test LLM connections (use VectorEmbeddingService or ChatService)
 * - Generate embeddings (use VectorEmbeddingService)
 * - Execute chat operations (use ChatService)
 * - Perform searches (use appropriate search services)
 *
 * SETTINGS CATEGORIES:
 * - Version: Application name and version information
 * - RBAC: Role-based access control configuration
 * - Multitenancy: Tenant isolation and default tenant settings
 * - Retention: Data retention policies for objects, logs, and trails
 * - LLM: Language model provider configuration (OpenAI, Fireworks, Ollama)
 * - Files: File processing and vectorization settings
 * - Objects: Object vectorization and metadata settings
 * - Organisation: Default organisation and auto-creation settings
 *
 * ARCHITECTURE PATTERN:
 * - Controllers validate input and delegate to this service for storage
 * - Business logic services (ChatService, VectorEmbeddingService) read from this service
 * - Testing logic is delegated to the appropriate business logic service
 * - This service only handles persistence, not business logic
 *
 * INTEGRATION POINTS:
 * - IAppConfig: Nextcloud's app configuration storage
 * - IConfig: Nextcloud's system configuration
 * - ChatService: Reads LLM settings for chat operations
 * - VectorEmbeddingService: Reads LLM settings for embeddings
 * - Controllers: Delegate settings CRUD operations to this service
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Settings facade requires comprehensive configuration methods
 * @SuppressWarnings(PHPMD.TooManyMethods)           Many methods required for all setting categories
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Public API facade requires many public entry points
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex settings coordination across multiple handlers
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Requires coordination with many specialized settings handlers
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)     Public API facade requires many public entry points
 * @SuppressWarnings(PHPMD.TooManyFields)            Settings service coordinates many specialized handlers
 * @SuppressWarnings(PHPMD.LongVariable)             Descriptive variable names improve code readability
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SettingsService implements SettingsServiceInterface, AppSettingsInterface {

	/**
	 * Configuration service
	 *
	 * @var IConfig
	 */
	private IConfig $config;



	/**
	 * Database connection (lazy-loaded when needed)
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $db = null;



	/**
	 * Validation operations handler
	 *
	 * @var ValidationOperationsHandler|null
	 */
	private ?ValidationOperationsHandler $validationOperationsHandler = null;

	/**
	 * Search backend handler
	 *
	 * @var SearchBackendHandler
	 */
	private SearchBackendHandler $searchBackendHandler;

	/**
	 * LLM settings handler
	 *
	 * @var LlmSettingsHandler
	 */
	private LlmSettingsHandler $llmSettingsHandler;

	/**
	 * File settings handler
	 *
	 * @var FileSettingsHandler
	 */
	private FileSettingsHandler $fileSettingsHandler;

	/**
	 * Object and retention settings handler
	 *
	 * @var ObjectRetentionHandler|null
	 */
	private ?ObjectRetentionHandler $objectRetentionHandler = null;

	/**
	 * Cache settings handler
	 *
	 * @var CacheSettingsHandler
	 */
	private CacheSettingsHandler $cacheSettingsHandler;

	/**
	 * Configuration settings handler
	 *
	 * @var ConfigurationSettingsHandler|null
	 */
	private ?ConfigurationSettingsHandler $configurationSettingsHandler = null;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * REMOVED: Object mapper (unused, caused circular dependency)
	 *
	 * @var MagicMapperInterface|null
	 */
	// Private ?MagicMapper $objectMapper;.





	/**
	 * This property holds the name of the application, which is used for identification and configuration purposes.
	 *
	 * @var string $appName The name of the app.
	 */
	private string $appName;

	/**
	 * Unique identifier for the OpenRegister application.
	 *
	 * Used to check its installation and status.
	 *
	 * @var string $openRegisterAppId The ID of the OpenRegister app.
	 */
	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * Minimum required version of the OpenRegister application.
	 *
	 * Required for compatibility and functionality.
	 *
	 * @var string $minOpenRegisterVersion Minimum required version of OpenRegister.
	 */
	private const MIN_OPENREGISTER_VERSION = '0.1.7';

	/**
	 * Tables counted in the database statistics, by result column.
	 *
	 * @var array<string, string>
	 */
	private const DATABASE_STATS_COUNTED_TABLES = [
		'total_audit_trails' => 'openregister_audit_trails',
		'total_search_trails' => 'openregister_search_trails',
		'total_configurations' => 'openregister_configurations',
		'total_organisations' => 'openregister_organisations',
		'total_registers' => 'openregister_registers',
		'total_schemas' => 'openregister_schemas',
	];

	/**
	 * Warning keys in the database statistics and the result column each reads.
	 *
	 * @var array<string, string>
	 */
	private const DATABASE_STATS_WARNINGS = [
		'auditTrailsWithoutExpiry' => 'audit_trails_without_expiry',
		'searchTrailsWithoutExpiry' => 'search_trails_without_expiry',
		'expiredAuditTrails' => 'expired_audit_trails',
		'expiredSearchTrails' => 'expired_search_trails',
	];

	/**
	 * Total keys in the database statistics (after the object totals) and the result column each reads.
	 *
	 * @var array<string, string>
	 */
	private const DATABASE_STATS_TOTALS = [
		'totalAuditTrails' => 'total_audit_trails',
		'totalSearchTrails' => 'total_search_trails',
		'totalConfigurations' => 'total_configurations',
		'totalOrganisations' => 'total_organisations',
		'totalRegisters' => 'total_registers',
		'totalSchemas' => 'total_schemas',
		'totalSources' => 'total_sources',
		'totalWebhookLogs' => 'total_webhook_logs',
	];

	/**
	 * Container for lazy loading services to break circular dependencies
	 *
	 * @var IAppContainer|null
	 */
	private ?IAppContainer $container = null;

	/**
	 * Constructor for SettingsService
	 *
	 * @param IConfig $config Configuration service
	 * @param LoggerInterface $logger Logger
	 * @param IDBConnection $db Database connection
	 * @param IAppContainer|null $container Container for lazy loading (optional)
	 * @param string $appName Application name
	 * @param ValidationOperationsHandler|null $validOpsHandler Validation operations handler
	 * @param SearchBackendHandler|null $searchBackendHandler Search backend handler
	 * @param LlmSettingsHandler|null $llmSettingsHandler LLM settings handler
	 * @param FileSettingsHandler|null $fileSettingsHandler File settings handler
	 * @param ObjectRetentionHandler|null $objRetentionHandler Object retention handler
	 * @param CacheSettingsHandler|null $cacheSettingsHandler Cache settings handler
	 * @param ConfigurationSettingsHandler|null $cfgSettingsHandler Configuration settings handler
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
	 */
	public function __construct(
		IConfig $config,
		LoggerInterface $logger,
		IDBConnection $db,
		?IAppContainer $container = null,
		string $appName = 'openregister',
		?ValidationOperationsHandler $validOpsHandler = null,
		?SearchBackendHandler $searchBackendHandler = null,
		?LlmSettingsHandler $llmSettingsHandler = null,
		?FileSettingsHandler $fileSettingsHandler = null,
		?ObjectRetentionHandler $objRetentionHandler = null,
		?CacheSettingsHandler $cacheSettingsHandler = null,
		?ConfigurationSettingsHandler $cfgSettingsHandler = null,
	) {
		$this->config = $config;
		$this->logger = $logger;
		// REMOVED: objectEntityMapper assignment (unused, caused circular dependency).
		$this->db = $db;
		$this->container = $container;
		$this->appName = $appName;

		// Initialize handlers (lazy-load if not provided).
		$this->validationOperationsHandler = $validOpsHandler;
		$this->searchBackendHandler = $searchBackendHandler;
		$this->llmSettingsHandler = $llmSettingsHandler;
		$this->fileSettingsHandler = $fileSettingsHandler;
		$this->objectRetentionHandler = $objRetentionHandler;
		$this->cacheSettingsHandler = $cacheSettingsHandler;
		$this->configurationSettingsHandler = $cfgSettingsHandler;
	}//end __construct()

	// ============================================
	// DELEGATION METHODS TO HANDLERS
	// ============================================
	// SearchBackendHandler methods (1)

	/**
	 * Get search backend configuration
	 *
	 * @return array Search backend configuration
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getSearchBackendConfig(): array {
		return $this->searchBackendHandler->getSearchBackendConfig();
	}//end getSearchBackendConfig()

	// LlmSettingsHandler methods (2).

	/**
	 * Get LLM settings only
	 *
	 * @return array LLM settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getLLMSettingsOnly(): array {
		return $this->llmSettingsHandler->getLLMSettingsOnly();
	}//end getLLMSettingsOnly()

	/**
	 * Update LLM settings only
	 *
	 * @param array $data LLM settings data
	 *
	 * @return array Updated LLM settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateLLMSettingsOnly(array $data): array {
		return $this->llmSettingsHandler->updateLLMSettingsOnly($data);
	}//end updateLLMSettingsOnly()

	// FileSettingsHandler methods (3).

	/**
	 * Get file settings only
	 *
	 * @return array File settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getFileSettingsOnly(): array {
		return $this->fileSettingsHandler->getFileSettingsOnly();
	}//end getFileSettingsOnly()

	/**
	 * Update file settings only
	 *
	 * @param array $data File settings data
	 *
	 * @return array Updated file settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateFileSettingsOnly(array $data): array {
		return $this->fileSettingsHandler->updateFileSettingsOnly($data);
	}//end updateFileSettingsOnly()

	/**
	 * The stored OpenAnonymiser password, for the outgoing request only.
	 *
	 * @return string The password, or an empty string when none is set.
	 *
	 * @spec exclude credential plumbing for the external OpenAnonymiser transport
	 */
	public function getOpenAnonymiserPassword(): string {
		return $this->fileSettingsHandler->getOpenAnonymiserPassword();
	}//end getOpenAnonymiserPassword()

	// ObjectRetentionHandler methods (4).

	/**
	 * Get object settings only
	 *
	 * @return array Object settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getObjectSettingsOnly(): array {
		return $this->objectRetentionHandler->getObjectSettingsOnly();
	}//end getObjectSettingsOnly()

	/**
	 * Update object settings only
	 *
	 * @param array $data Object settings data
	 *
	 * @return array Updated object settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateObjectSettingsOnly(array $data): array {
		return $this->objectRetentionHandler->updateObjectSettingsOnly($data);
	}//end updateObjectSettingsOnly()

	/**
	 * Get retention settings only
	 *
	 * @return array Retention settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getRetentionSettingsOnly(): array {
		return $this->objectRetentionHandler->getRetentionSettingsOnly();
	}//end getRetentionSettingsOnly()

	/**
	 * Update retention settings only
	 *
	 * @param array $data Retention settings data
	 *
	 * @return array Updated retention settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateRetentionSettingsOnly(array $data): array {
		return $this->objectRetentionHandler->updateRetentionSettingsOnly($data);
	}//end updateRetentionSettingsOnly()

	/**
	 * Get archival settings only
	 *
	 * @return array Archival settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getArchivalSettingsOnly(): array {
		return $this->objectRetentionHandler->getArchivalSettingsOnly();
	}//end getArchivalSettingsOnly()

	/**
	 * Update archival settings only
	 *
	 * @param array $data Archival settings data
	 *
	 * @return array Updated archival settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateArchivalSettingsOnly(array $data): array {
		return $this->objectRetentionHandler->updateArchivalSettingsOnly($data);
	}//end updateArchivalSettingsOnly()

	// CacheSettingsHandler methods (3 main ones).

	/**
	 * Get cache statistics
	 *
	 * @return array Cache statistics
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-3
	 */
	public function getCacheStats(): array {
		return $this->cacheSettingsHandler->getCacheStats();
	}//end getCacheStats()

	/**
	 * Clear cache
	 *
	 * @param string|null $cacheType Type of cache to clear
	 *
	 * @return array Clear cache result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-3
	 */
	public function clearCache(?string $cacheType = null): array {
		return $this->cacheSettingsHandler->clearCache($cacheType ?? 'all');
	}//end clearCache()

	/**
	 * Warmup names cache
	 *
	 * @return array Warmup result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-3
	 */
	public function warmupNamesCache(): array {
		return $this->cacheSettingsHandler->warmupNamesCache();
	}//end warmupNamesCache()

	// ConfigurationSettingsHandler methods (15 main ones).

	/**
	 * Get settings
	 *
	 * @return array Settings configuration
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getSettings(): array {
		return $this->configurationSettingsHandler->getSettings();
	}//end getSettings()

	/**
	 * Update settings
	 *
	 * @param array $data Settings data
	 *
	 * @return array Updated settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateSettings(array $data): array {
		return $this->configurationSettingsHandler->updateSettings($data);
	}//end updateSettings()

	/**
	 * Check if multi-tenancy is enabled
	 *
	 * @return bool True if enabled
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function isMultiTenancyEnabled(): bool {
		return $this->configurationSettingsHandler->isMultiTenancyEnabled();
	}//end isMultiTenancyEnabled()

	/**
	 * Get RBAC settings only
	 *
	 * @return array RBAC settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getRbacSettingsOnly(): array {
		return $this->configurationSettingsHandler->getRbacSettingsOnly();
	}//end getRbacSettingsOnly()

	/**
	 * Update RBAC settings only
	 *
	 * @param array $data RBAC settings data
	 *
	 * @return array Updated RBAC settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateRbacSettingsOnly(array $data): array {
		return $this->configurationSettingsHandler->updateRbacSettingsOnly($data);
	}//end updateRbacSettingsOnly()

	/**
	 * Get organisation settings only
	 *
	 * @return (mixed|null|true)[][] Organisation settings
	 *
	 * @psalm-return array{organisation: array{default_organisation: mixed|null,
	 *               auto_create_default_organisation: mixed|true}}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getOrganisationSettingsOnly(): array {
		return $this->configurationSettingsHandler->getOrganisationSettingsOnly();
	}//end getOrganisationSettingsOnly()

	/**
	 * Update organisation settings only
	 *
	 * @param array $data Organisation settings data
	 *
	 * @return (mixed|null|true)[][] Updated organisation settings
	 *
	 * @psalm-return array{organisation: array{default_organisation: mixed|null,
	 *               auto_create_default_organisation: mixed|true}}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateOrganisationSettingsOnly(array $data): array {
		return $this->configurationSettingsHandler->updateOrganisationSettingsOnly($data);
	}//end updateOrganisationSettingsOnly()

	/**
	 * Get default organisation UUID
	 *
	 * @return string|null Organisation UUID
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getDefaultOrganisationUuid(): ?string {
		return $this->configurationSettingsHandler->getDefaultOrganisationUuid();
	}//end getDefaultOrganisationUuid()

	/**
	 * Set default organisation UUID
	 *
	 * @param string|null $uuid Organisation UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function setDefaultOrganisationUuid(?string $uuid): void {
		$this->configurationSettingsHandler->setDefaultOrganisationUuid($uuid);
	}//end setDefaultOrganisationUuid()

	/**
	 * Get tenant ID
	 *
	 * @return string|null Tenant ID
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getTenantId(): ?string {
		return $this->configurationSettingsHandler->getTenantId();
	}//end getTenantId()

	/**
	 * Get organisation ID
	 *
	 * @return string|null Organisation ID
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getOrganisationId(): ?string {
		return $this->configurationSettingsHandler->getOrganisationId();
	}//end getOrganisationId()

	/**
	 * Get multitenancy settings only
	 *
	 * @return array[] Multitenancy settings
	 *
	 * @psalm-return array{multitenancy: array{enabled: false|mixed,
	 *     defaultUserTenant: ''|mixed, defaultObjectTenant: ''|mixed,
	 *     adminOverride: mixed|true}, availableTenants: array}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getMultitenancySettingsOnly(): array {
		return $this->configurationSettingsHandler->getMultitenancySettingsOnly();
	}//end getMultitenancySettingsOnly()

	/**
	 * Update multitenancy settings only
	 *
	 * @param array $data Multitenancy settings data
	 *
	 * @return array Updated multitenancy settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateMultitenancySettingsOnly(array $data): array {
		return $this->configurationSettingsHandler->updateMultitenancySettingsOnly($data);
	}//end updateMultitenancySettingsOnly()

	/**
	 * Get version info only
	 *
	 * @return array Version information
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-5
	 */
	public function getVersionInfoOnly(): array {
		return $this->configurationSettingsHandler->getVersionInfoOnly();
	}//end getVersionInfoOnly()

	/**
	 * Get cached database information
	 *
	 * Returns the cached database info including type, version, and extensions.
	 * Returns null if no cached data is available.
	 *
	 * @return array|null Database information or null if not cached
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-5
	 */
	public function getDatabaseInfo(): ?array {
		$cachedInfo = $this->config->getAppValue('openregister', 'databaseInfo', '');
		if (empty($cachedInfo) === true) {
			return null;
		}

		$data = json_decode($cachedInfo, true);
		if ($data === null || isset($data['database']) === false) {
			return null;
		}

		return $data['database'];
	}//end getDatabaseInfo()

	/**
	 * Check if a specific PostgreSQL extension is installed
	 *
	 * @param string $extensionName The name of the extension to check (e.g., 'vector', 'pg_trgm')
	 *
	 * @return bool True if the extension is installed, false otherwise
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-5
	 */
	public function hasPostgresExtension(string $extensionName): bool {
		$dbInfo = $this->getDatabaseInfo();
		if ($dbInfo === null || ($dbInfo['type'] ?? '') !== 'PostgreSQL') {
			return false;
		}

		$extensions = $dbInfo['extensions'] ?? [];
		foreach ($extensions as $ext) {
			if (($ext['name'] ?? '') === $extensionName) {
				return true;
			}
		}

		return false;
	}//end hasPostgresExtension()

	/**
	 * Get list of installed PostgreSQL extensions
	 *
	 * @return array List of extension names, empty array if not PostgreSQL or no extensions
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-5
	 */
	public function getPostgresExtensions(): array {
		$dbInfo = $this->getDatabaseInfo();
		if ($dbInfo === null || ($dbInfo['type'] ?? '') !== 'PostgreSQL') {
			return [];
		}

		return $dbInfo['extensions'] ?? [];
	}//end getPostgresExtensions()

	/**
	 * Validate all objects in the system
	 *
	 * Triggers validation logic for all objects without re-saving them.
	 * This is a lighter-weight operation compared to massValidateObjects.
	 *
	 * @return array Validation results
	 *
	 * @throws Exception If validation operation fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
	 */
	public function validateAllObjects(): array {
		return $this->validationOperationsHandler->validateAllObjects();
	}//end validateAllObjects()

	/**
	 * Mass validate objects by re-saving them to trigger business logic
	 *
	 * Re-saves all objects in the system to ensure all business logic
	 * is triggered and objects are properly processed according to current rules.
	 *
	 * @param int $maxObjects Maximum number of objects to process (0 = all).
	 * @param int $batchSize Batch size for processing (default: 1000).
	 * @param string $mode Processing mode: 'serial' or 'parallel'.
	 * @param bool $collectErrors Whether to collect all errors or stop on first.
	 *
	 * @return array Mass validation results
	 *
	 * @throws Exception If mass validation operation fails.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flag needed for error collection behavior
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
	 */
	public function massValidateObjects(int $maxObjects = 0, int $batchSize = 1000, string $mode = 'serial', bool $collectErrors = false): array {
		$request = new MassValidationRequest(maxObjects: $maxObjects, batchSize: $batchSize, mode: $mode, collectErrors: $collectErrors);
		return $this->massValidate(request: $request);
	}//end massValidateObjects()

	/**
	 * Mass validate the stored objects, as {@see massValidateObjects()} describes.
	 *
	 * @param MassValidationRequest $request How many objects, the batch size, the mode and whether errors are collected.
	 *
	 * @return array Mass validation results
	 *
	 * @throws Exception If mass validation operation fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
	 */
	public function massValidate(MassValidationRequest $request): array {
		$mode      = $request->mode;
		$batchSize = $request->batchSize;
		if (in_array($mode, ['serial', 'parallel'], true) === false) {
			throw new InvalidArgumentException('Invalid mode parameter. Must be "serial" or "parallel"');
		}

		if ($batchSize < 1 || $batchSize > 5000) {
			throw new InvalidArgumentException('Invalid batch size. Must be between 1 and 5000');
		}

		$run = new MassValidationRun(
			objectMapper: $this->container->get(MagicMapperInterface::class),
			settings: $this,
			logger: $this->logger,
			container: $this->container
		);
		return $run->run(
			config: [
				'mode' => $mode,
				'max_objects' => $request->maxObjects,
				'batch_size' => $batchSize,
				'collect_errors' => $request->collectErrors,
			]
		);
	}//end massValidate()

	/**
	 * Format bytes into human readable format
	 *
	 * @param int $bytes Number of bytes.
	 * @param int $precision Decimal precision.
	 *
	 * @return string Formatted string.
	 *
	 * @spec exclude Pure byte-to-human-readable formatting helper; no orchestration or persisted state.
	 */
	public function formatBytes(int $bytes, int $precision = 2): string {
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$unitCount = count($units);

		$unitIndex = 0;
		for (; $bytes > 1024 && $unitIndex < $unitCount - 1; $unitIndex++) {
			$bytes /= 1024;
		}

		// Ensure $unitIndex is within bounds (0-4) for the $units array.
		$unitIndex = min($unitIndex, $unitCount - 1);

		return round($bytes, $precision) . ' ' . $units[$unitIndex];
	}//end formatBytes()

	/**
	 * Convert memory limit string to bytes.
	 *
	 * @param string $memoryLimit Memory limit string (e.g., '128M', '1G').
	 *
	 * @return int Memory limit in bytes.
	 *
	 * @spec exclude Pure memory-limit-string-to-bytes parsing helper; no orchestration or persisted state.
	 */
	public function convertToBytes(string $memoryLimit): int {
		$memoryLimit = trim($memoryLimit);
		$last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
		$value = (int)$memoryLimit;

		// Multipliers are spelled out per case rather than reached by
		// fall-through: php-cs-fixer's no_break_comment fixer requires the
		// marker comment to read exactly `no break`, while PHPCS's
		// Squiz.Commenting.InlineComment.NotCapital requires a capital. No
		// spelling of the comment satisfies both, so the fall-through goes.
		switch ($last) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return $value;
	}//end convertToBytes()

	/**
	 * Mask sensitive token for display.
	 *
	 * Shows first 4 and last 4 characters, masks the middle.
	 *
	 * @param string $token The token to mask.
	 *
	 * @return string The masked token.
	 *
	 * @spec exclude Pure display helper masking the middle of a token; no orchestration or persisted state.
	 */
	public function maskToken(string $token): string {
		if (strlen($token) <= 8) {
			return str_repeat('*', strlen($token));
		}

		$start = substr($token, 0, 4);
		$end = substr($token, -4);
		$middle = str_repeat('*', min(20, strlen($token) - 8));

		return $start . $middle . $end;
	}//end maskToken()

	/**
	 * Get comprehensive statistics.
	 *
	 * Returns combined statistics from various components.
	 *
	 * @return array Comprehensive statistics
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Statistics aggregation requires comprehensive data collection
	 */

	/**
	 * Get statistics for the settings dashboard.
	 *
	 * This method provides counts and warnings for objects, logs, and other entities
	 * in the OpenRegister system. It uses optimized SQL queries to fetch all counts
	 * in a single database roundtrip for better performance.
	 *
	 * @return array<string, mixed> Statistics array with warnings and totals
	 *
	 * @psalm-return array{
	 *     timestamp: int,
	 *     date: string,
	 *     warnings: array{
	 *         objectsWithoutOwner: int,
	 *         objectsWithoutOrganisation: int,
	 *         auditTrailsWithoutExpiry: int,
	 *         searchTrailsWithoutExpiry: int,
	 *         expiredAuditTrails: int,
	 *         expiredSearchTrails: int,
	 *         expiredObjects: int
	 *     },
	 *     totals: array{
	 *         totalObjects: int,
	 *         totalAuditTrails: int,
	 *         totalSearchTrails: int,
	 *         totalConfigurations: int,
	 *         totalOrganisations: int,
	 *         totalRegisters: int,
	 *         totalSchemas: int,
	 *         totalSources: int,
	 *         totalWebhookLogs: int,
	 *         deletedObjects: int
	 *     },
	 *     cache?: array<string, mixed>,
	 *     system: array{
	 *         php_version: string,
	 *         memory_limit: string,
	 *         max_execution_time: string
	 *     }
	 * }
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-5
	 */
	public function getStats(): array {
		try {
			$stats = [
				'timestamp' => time(),
				'date' => date('Y-m-d H:i:s'),
			];

			// Get database statistics using optimized queries.
			try {
				$dbStats = $this->getDatabaseStats();
				$stats['warnings'] = $dbStats['warnings'];
				$stats['totals'] = $dbStats['totals'];
			} catch (\Exception $e) {
				$this->logger->error(
					message: '[SettingsService] Failed to load database statistics',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
				// Provide default empty stats if DB query fails.
				$stats['warnings'] = [
					'objectsWithoutOwner' => 0,
					'objectsWithoutOrganisation' => 0,
					'auditTrailsWithoutExpiry' => 0,
					'searchTrailsWithoutExpiry' => 0,
					'expiredAuditTrails' => 0,
					'expiredSearchTrails' => 0,
					'expiredObjects' => 0,
				];
				$stats['totals'] = [
					'totalObjects' => 0,
					'totalBlobObjects' => 0,
					'totalMagicObjects' => 0,
					'totalSize' => 0,
					'totalBlobSize' => 0,
					'totalMagicSize' => 0,
					'totalAuditTrails' => 0,
					'totalSearchTrails' => 0,
					'totalConfigurations' => 0,
					'totalOrganisations' => 0,
					'totalRegisters' => 0,
					'totalSchemas' => 0,
					'totalSources' => 0,
					'totalWebhookLogs' => 0,
					'deletedObjects' => 0,
				];
			}//end try

			// Get cache stats.
			try {
				$stats['cache'] = $this->getCacheStats();
			} catch (\Exception $e) {
				$stats['cache'] = ['error' => $e->getMessage()];
			}

			// Get system info.
			$stats['system'] = [
				'php_version' => PHP_VERSION,
				'memory_limit' => ini_get('memory_limit'),
				'max_execution_time' => ini_get('max_execution_time'),
			];

			return $stats;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[SettingsService] Failed to retrieve stats',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [
				'error' => 'Failed to retrieve stats',
				'message' => $e->getMessage(),
			];
		}//end try
	}//end getStats()

	/**
	 * Get database statistics using optimized SQL queries.
	 *
	 * This method executes a single optimized query to fetch all counts and warnings
	 * from the database in one roundtrip for better performance.
	 *
	 * @return array<string, array<string, int>> Database statistics with warnings and totals
	 */
	private function getDatabaseStats(): array {
		$qb = $this->db->getQueryBuilder();

		$this->logger->info(
			message: '[SettingsService] getDatabaseStats() called',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$magicTotals = $this->sumMagicTables();
		$row = $this->db->executeQuery($this->databaseCountsQuery(qb: $qb))->fetch();
		if ($row === false) {
			throw new RuntimeException('Failed to fetch database statistics');
		}

		$warnings = [];
		foreach (self::DATABASE_STATS_WARNINGS as $key => $column) {
			$warnings[$key] = (int)($row[$column] ?? 0);
		}

		$totals = ['totalObjects' => $magicTotals['count'], 'totalSize' => $magicTotals['size']];
		foreach (self::DATABASE_STATS_TOTALS as $key => $column) {
			$totals[$key] = (int)($row[$column] ?? 0);
		}

		return ['warnings' => $warnings, 'totals' => $totals];
	}//end getDatabaseStats()

	/**
	 * Count the live objects and sum their sizes over all magic tables (openregister_table_*).
	 *
	 * A table that cannot be queried is skipped; when the tables cannot be listed the totals stay 0.
	 *
	 * @return array{count: int, size: int} Object count and total size.
	 */
	private function sumMagicTables(): array {
		$totals = ['count' => 0, 'size' => 0];
		try {
			foreach ($this->listMagicTables() as $fullTableName) {
				$tableTotals = $this->sumMagicTable(fullTableName: $fullTableName);
				$totals['count'] += $tableTotals['count'];
				$totals['size'] += $tableTotals['size'];
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[SettingsService] Failed to count magic mapper objects',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return $totals;
	}//end sumMagicTables()

	/**
	 * The names of all magic tables, read from the platform's catalogue.
	 *
	 * @return array<string> Full table names.
	 */
	private function listMagicTables(): array {
		$platform = $this->db->getDatabasePlatform();
		$tablesQuery = "SELECT table_name as tablename FROM information_schema.tables
                   WHERE table_schema = DATABASE()
                   AND table_name LIKE '*PREFIX*openregister_table_%'";
		if (stripos($platform::class, 'PostgreSQL') !== false) {
			$tablesQuery = "SELECT tablename FROM pg_tables
                   WHERE schemaname = 'public'
                   AND tablename LIKE '*PREFIX*openregister_table_%'";
		}

		return $this->db->executeQuery($tablesQuery)->fetchAll(\PDO::FETCH_COLUMN);
	}//end listMagicTables()

	/**
	 * Count the live objects in one magic table and sum their sizes; 0 when the table fails.
	 *
	 * @param string $fullTableName The table name, prefix included.
	 *
	 * @return array{count: int, size: int} Object count and total size.
	 */
	private function sumMagicTable(string $fullTableName): array {
		try {
			// _size is VARCHAR, so cast it to BIGINT for aggregation (PostgreSQL compatible).
			$result = $this->db->executeQuery(
				"SELECT COUNT(*) as cnt, 
                        COALESCE(SUM(
                            CASE 
                                WHEN _size IS NOT NULL AND _size != '' 
                                THEN CAST(_size AS BIGINT) 
                                ELSE 0 
                            END
                        ), 0) as total_size 
                 FROM {$fullTableName} 
                 WHERE _deleted IS NULL"
			)->fetch();

			return ['count' => (int)$result['cnt'], 'size' => (int)$result['total_size']];
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[SettingsService] Failed to query magic mapper table',
				context: ['file' => __FILE__, 'line' => __LINE__, 'table' => $fullTableName, 'error' => $e->getMessage()]
			);
			return ['count' => 0, 'size' => 0];
		}
	}//end sumMagicTable()

	/**
	 * One query with a subquery per count and warning in the database statistics.
	 *
	 * The sources count is 0 when the openconnector_sources table does not exist.
	 *
	 * @param IQueryBuilder $qb Query builder used for table names.
	 *
	 * @return string The SQL.
	 */
	private function databaseCountsQuery(IQueryBuilder $qb): string {
		$auditTable = $qb->getTableName('openregister_audit_trails');
		$searchTable = $qb->getTableName('openregister_search_trails');

		$counts = [];
		foreach (self::DATABASE_STATS_COUNTED_TABLES as $alias => $table) {
			$counts[$alias] = "(SELECT COUNT(*) FROM {$qb->getTableName($table)})";
		}

		$counts['total_sources'] = '0';
		if ($this->sourcesTableExists(qb: $qb) === true) {
			$counts['total_sources'] = "(SELECT COUNT(*) FROM {$qb->getTableName('openconnector_sources')})";
		}

		$counts['total_webhook_logs'] = "(SELECT COUNT(*) FROM {$qb->getTableName('openregister_webhook_logs')})";
		$counts['audit_trails_without_expiry'] = "(SELECT COUNT(*) FROM {$auditTable} WHERE expires IS NULL)";
		$counts['search_trails_without_expiry'] = "(SELECT COUNT(*) FROM {$searchTable} WHERE expires IS NULL)";
		$counts['expired_audit_trails'] = "(SELECT COUNT(*) FROM {$auditTable} WHERE expires IS NOT NULL AND expires < NOW())";
		$counts['expired_search_trails'] = "(SELECT COUNT(*) FROM {$searchTable} WHERE expires IS NOT NULL AND expires < NOW())";

		$columns = [];
		foreach ($counts as $alias => $subquery) {
			$columns[] = "{$subquery} as {$alias}";
		}

		return "SELECT\n            " . implode(",\n            ", $columns);
	}//end databaseCountsQuery()

	/**
	 * Whether the openconnector_sources table exists; it does only with OpenConnector installed.
	 *
	 * @param IQueryBuilder $qb Query builder used for the table name.
	 *
	 * @return bool True when the table answers a query.
	 */
	private function sourcesTableExists(IQueryBuilder $qb): bool {
		try {
			$this->db->executeQuery("SELECT 1 FROM {$qb->getTableName('openconnector_sources')} LIMIT 1");
			return true;
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[SettingsService] openconnector_sources table does not exist - OpenConnector app not installed',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return false;
		}
	}//end sourcesTableExists()

	/**
	 * Rebase configuration from source.
	 *
	 * Resets configuration to default or imports from source.
	 * This is typically used for configuration management.
	 *
	 * @param array $options Rebase options
	 *
	 * @return ((string|true)[][]|bool|int|string)[] Rebase result
	 *
	 * @psalm-return array{success: bool, error?: 'Rebase failed', message: string,
	 *     rebased?: array{cache?: array{success: true,
	 *     message: 'Cache cleared and ready for rebuild'}},
	 *     timestamp?: int<1, max>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-5
	 */
	public function rebase(array $options = []): array {
		try {
			$this->logger->info(
				message: '[SettingsService] Rebase requested',
				context: ['file' => __FILE__, 'line' => __LINE__, 'options' => $options]
			);

			// Get current settings (currently unused but kept for potential future use).
			// $currentSettings = $this->getSettings();
			// Determine what to rebase.
			$components = $options['components'] ?? ['all'];
			$rebased = [];

			if (in_array('all', $components, true) === true || in_array('cache', $components, true) === true) {
				// Clear and rebuild cache.
				$this->clearCache();
				$rebased['cache'] = [
					'success' => true,
					'message' => 'Cache cleared and ready for rebuild',
				];
			}

			return [
				'success' => true,
				'message' => 'Configuration rebase completed',
				'rebased' => $rebased,
				'timestamp' => time(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[SettingsService] Rebase failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);

			return [
				'success' => false,
				'error' => 'Rebase failed',
				'message' => $e->getMessage(),
			];
		}//end try
	}//end rebase()

	// ============================================
	// INTEGRATION DEFAULTS (Deck — schema-level sticky board+stack).
	// ============================================
	// Persists the {boardId, stackId} pair the first time a user creates
	// a Deck card for a given schema slug, so subsequent create-card
	// affordances on objects of that schema pre-select the same target.
	// See openspec/changes/integration-deck/design.md AD-1.

	/**
	 * Build the IAppConfig storage key for the sticky Deck default.
	 *
	 * @param string $schemaSlug Schema slug or numeric id (caller's choice — only used as opaque key suffix).
	 *
	 * @return string The config key, e.g. `integration.deck.default.case`.
	 */
	private function buildDeckDefaultKey(string $schemaSlug): string {
		return 'integration.deck.default.' . $schemaSlug;
	}//end buildDeckDefaultKey()

	/**
	 * Get the persisted Deck default board+stack for a schema (or null).
	 *
	 * @param string $schemaSlug Schema slug or numeric id used to scope the default.
	 *
	 * @return array{boardId:int,stackId:int}|null Default pair, or null when none has been recorded yet.
	 *
	 * @spec openspec/specs/integration-deck/spec.md
	 */
	public function getDeckDefault(string $schemaSlug): ?array {
		$raw = $this->config->getAppValue($this->appName, $this->buildDeckDefaultKey(schemaSlug: $schemaSlug), '');
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return null;
		}

		if (isset($decoded['boardId']) === false || isset($decoded['stackId']) === false) {
			return null;
		}

		return [
			'boardId' => (int)$decoded['boardId'],
			'stackId' => (int)$decoded['stackId'],
		];
	}//end getDeckDefault()

	/**
	 * Persist the Deck default board+stack for a schema. Overwrites any prior value.
	 *
	 * @param string $schemaSlug Schema slug or numeric id used to scope the default.
	 * @param int $boardId NC Deck board id.
	 * @param int $stackId NC Deck stack id on that board.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-deck/spec.md
	 */
	public function setDeckDefault(string $schemaSlug, int $boardId, int $stackId): void {
		$payload = json_encode(['boardId' => $boardId, 'stackId' => $stackId]);
		if ($payload === false) {
			return;
		}

		$this->config->setAppValue($this->appName, $this->buildDeckDefaultKey(schemaSlug: $schemaSlug), $payload);
	}//end setDeckDefault()

	/*
	 * `clearDeckDefault()` was removed. Its docblock claimed it was
	 * "exercised only by uninstall paths"; there is no such path — no repair
	 * step, no occ command, no route and no UI ever called it, and Nextcloud
	 * drops the whole `openregister` appconfig namespace on uninstall anyway,
	 * so the case it named was already covered by the platform.
	 *
	 * The gap it left behind is real and is NOT closed here: `deckLinks#
	 * setDefault` refuses boardId/stackId of 0, so once a sticky Deck default
	 * is set for a schema there is no API or UI to unset it. Closing that
	 * needs a new route plus an authorization decision on a schema-scoped
	 * setting, which is a different change from this one.
	 */

}//end class
