<?php

/**
 * OpenRegister Configuration Settings Handler
 *
 * This file contains the handler class for managing general configuration settings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Settings;

use Exception;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\Audit\SecuritySettingAnnouncer;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Handler for configuration settings operations.
 *
 * This handler is responsible for managing RBAC, multitenancy,
 * retention, organisation, and object settings.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Configuration management requires comprehensive settings methods
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex configuration validation and transformation logic
 */
class ConfigurationSettingsHandler {
	use SettingsSectionDefaults;

	/**
	 * The JSON settings sections and the default of every key in them.
	 *
	 * Multitenancy is enabled by default, for proper data isolation.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const SECTION_DEFAULTS = [
		'rbac' => [
			'enabled' => true,
			'anonymousGroup' => 'public',
			'defaultNewUserGroup' => 'viewer',
			'defaultObjectOwner' => '',
			'adminOverride' => true,
		],
		'multitenancy' => [
			'enabled' => true,
			'defaultUserTenant' => '',
			'defaultObjectTenant' => '',
			'publishedObjectsBypassMultiTenancy' => false,
			'adminOverride' => true,
		],
		'retention' => self::RETENTION_PERIOD_DEFAULTS,
		'organisation' => [
			'default_organisation' => null,
			'auto_create_default_organisation' => true,
		],
	];

	/**
	 * The flow engine switches: settings field => [app config key, default].
	 *
	 * These are administrator settings, deliberately: whether every hop is
	 * audit-trailed is instance policy, not a per-user preference. Auditing is
	 * write volume (one entry per node per run) and the step rows already
	 * carry the operational history, so it is opt-in.
	 *
	 * @var array<string, array{0: string, 1: bool}>
	 */
	private const FLOW_SWITCHES = [
		'auditEnabled' => ['flow_audit_enabled', false],
		'killSwitch' => ['flow_kill_switch', false],
	];

	/**
	 * Configuration service
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Group manager
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * User manager
	 *
	 * @var IUserManager
	 */
	private IUserManager $userManager;

	/**
	 * Organisation mapper
	 *
	 * @var OrganisationMapperInterface
	 */
	private OrganisationMapperInterface $organisationMapper;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;


	/**
	 * App manager, read for the app's own version info.
	 *
	 * @var IAppManager
	 */
	private readonly IAppManager $appManager;

	/**
	 * Tells the administrators when a marked setting moves.
	 *
	 * @var SecuritySettingAnnouncer|null
	 */
	private readonly ?SecuritySettingAnnouncer $announcer;

	/**
	 * Records every save on the audit trail, and announces it.
	 *
	 * @var OwnSettingsChangeRecorder|null
	 */
	private readonly ?OwnSettingsChangeRecorder $changeRecorder;

	/**
	 * Constructor for ConfigurationSettingsHandler
	 *
	 * @param IAppConfig                   $appConfig Configuration service.
	 * @param SettingsDirectory            $directory The group manager, user manager and organisation mapper.
	 * @param ConfigurationSettingsSupport $support   The logger, app manager, announcer and change recorder.
	 *
	 * @return void
	 */
	public function __construct(
		IAppConfig $appConfig,
		SettingsDirectory $directory,
		ConfigurationSettingsSupport $support,
	) {
		$this->appConfig = $appConfig;
		$this->groupManager = $directory->groupManager;
		$this->userManager = $directory->userManager;
		$this->organisationMapper = $directory->organisationMapper;
		$this->logger = $support->logger;
		$this->appManager = $support->appManager;
		$this->announcer = $support->announcer;
		$this->changeRecorder = $support->changeRecorder;
	}//end __construct()

	/**
	 * Check if multi-tenancy is enabled
	 *
	 * @return bool True if multi-tenancy is enabled, false otherwise
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-3
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function isMultiTenancyEnabled(): bool {
		$multitenancyConfig = $this->appConfig->getValueString(AppIdentity::APP_ID, 'multitenancy', '');
		if (empty($multitenancyConfig) === true) {
			return false;
		}

		$multitenancyData = json_decode($multitenancyConfig, true);
		return $multitenancyData['enabled'] ?? false;
	}//end isMultiTenancyEnabled()

	/**
	 * Retrieve the current settings including RBAC and Multitenancy.
	 *
	 * @return (bool|int|mixed|null|string)[][]
	 *
	 * @throws \RuntimeException If settings retrieval fails.
	 *
	 * @psalm-return array{
	 *     version: array{appName: 'Open Register', appVersion: '0.2.3'},
	 *     rbac?: array{
	 *         enabled: mixed|true,
	 *         anonymousGroup: 'public'|mixed,
	 *         defaultNewUserGroup: 'viewer'|mixed,
	 *         defaultObjectOwner: ''|mixed,
	 *         adminOverride: mixed|true
	 *     },
	 *     multitenancy?: array{
	 *         enabled: mixed|true,
	 *         defaultUserTenant: ''|mixed,
	 *         defaultObjectTenant: ''|mixed,
	 *         adminOverride: mixed|true
	 *     },
	 *     availableGroups: array<string, string>,
	 *     availableTenants: array<string, null|string>,
	 *     availableUsers: array<string, string>,
	 *     retention?: array{
	 *         objectArchiveRetention: 31536000000|mixed,
	 *         objectDeleteRetention: 63072000000|mixed,
	 *         searchTrailRetention: 2592000000|mixed,
	 *         createLogRetention: 2592000000|mixed,
	 *         readLogRetention: 86400000|mixed,
	 *         updateLogRetention: 604800000|mixed,
	 *         deleteLogRetention: 2592000000|mixed,
	 *         auditTrailsEnabled: mixed|true,
	 *         searchTrailsEnabled: mixed|true
	 *     }
	 * }
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getSettings(): array {
		try {
			return $this->accessSettings() + $this->operationalSettings();
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve settings: ' . $e->getMessage());
		}
	}//end getSettings()

	/**
	 * The version, RBAC and multitenancy sections with the groups, tenants and users to pick from.
	 *
	 * @return array<string, mixed>
	 */
	private function accessSettings(): array {
		return [
			'version' => [
				'appName' => 'Open Register',
				'appVersion' => '0.2.3',
			],
			'rbac' => $this->storedSection(key: 'rbac'),
			'multitenancy' => $this->storedSection(key: 'multitenancy'),
			'availableGroups' => $this->getAvailableGroups(),
			'availableTenants' => $this->getAvailableOrganisations(),
			'availableUsers' => $this->getAvailableUsers(),
		];
	}//end accessSettings()

	/**
	 * The retention, blob migration, flow engine and party query cap sections.
	 *
	 * The party query cap is the administered maximum a party search may return
	 * before it is refused: a refusal, never a truncation.
	 *
	 * @return array<string, mixed>
	 */
	private function operationalSettings(): array {
		return [
			'retention' => $this->storedSection(key: 'retention'),
			'blobMigration' => $this->blobMigrationStatus(),
			'flow' => $this->flowSettings(),
			'party' => [
				'queryCap' => $this->appConfig->getValueInt(
					AppIdentity::APP_ID,
					PartySearchService::CAP_KEY,
					PartySearchService::DEFAULT_CAP
				),
			],
		];
	}//end operationalSettings()

	/**
	 * The blob migration progress as stored by the migration job.
	 *
	 * @return array{processed: int, remaining: int, complete: bool, lastRun: string}
	 */
	private function blobMigrationStatus(): array {
		return [
			'processed' => (int)$this->appConfig->getValueString(AppIdentity::APP_ID, 'blob_migration_processed', '0'),
			'remaining' => (int)$this->appConfig->getValueString(AppIdentity::APP_ID, 'blob_migration_remaining', '0'),
			'complete' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'blob_migration_complete', 'false') === 'true',
			'lastRun' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'blob_migration_last_run', ''),
		];
	}//end blobMigrationStatus()

	/**
	 * The flow engine settings: run history retention and the switches in FLOW_SWITCHES.
	 *
	 * @return array<string, int|bool>
	 */
	private function flowSettings(): array {
		$flow = [
			'retentionDays' => (int)$this->appConfig->getValueString(AppIdentity::APP_ID, 'flow_run_retention_days', '31'),
		];
		foreach (self::FLOW_SWITCHES as $field => [$key, $default]) {
			$flow[$field] = $this->appConfig->getValueBool(AppIdentity::APP_ID, $key, $default);
		}

		return $flow;
	}//end flowSettings()

	/**
	 * A stored JSON section over its defaults, or the defaults when nothing is stored.
	 *
	 * @param string $key The app config key, one of SECTION_DEFAULTS.
	 *
	 * @return array<string, mixed>
	 */
	private function storedSection(string $key): array {
		$stored = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		if (empty($stored) === true) {
			return self::SECTION_DEFAULTS[$key];
		}

		return $this->overDefaults(defaults: self::SECTION_DEFAULTS[$key], values: json_decode($stored, true));
	}//end storedSection()

	/**
	 * Store a section as JSON, every default key taken from the given values when set there.
	 *
	 * @param string $key    The app config key, one of SECTION_DEFAULTS.
	 * @param mixed  $values The submitted section.
	 *
	 * @return array<string, mixed> The stored section.
	 */
	private function storeSection(string $key, $values): array {
		$section = $this->overDefaults(defaults: self::SECTION_DEFAULTS[$key], values: $values);
		$this->appConfig->setValueString(AppIdentity::APP_ID, $key, json_encode($section));

		return $section;
	}//end storeSection()

	/**
	 * Get available Nextcloud groups.
	 *
	 * @return string[]
	 *
	 * @psalm-return array<string, string>
	 */
	private function getAvailableGroups(): array {
		$groups = [];

		// Add special "public" group for anonymous users.
		$groups['public'] = 'Public (No restrictions)';

		// Get all Nextcloud groups.
		$nextcloudGroups = $this->groupManager->search('');
		foreach ($nextcloudGroups as $group) {
			$groups[$group->getGID()] = $group->getDisplayName();
		}

		return $groups;
	}//end getAvailableGroups()

	/**
	 * Get available organisations as tenants.
	 *
	 * @return (null|string)[] Array of organisation_uuid => organisation_name
	 *
	 * @psalm-return array<string, null|string>
	 */
	private function getAvailableOrganisations(): array {
		try {
			$organisations = $this->organisationMapper->findAllWithUserCount();
			$tenants = [];

			foreach ($organisations as $organisation) {
				$tenants[$organisation->getUuid()] = $organisation->getName();
			}

			return $tenants;
		} catch (Exception $e) {
			// Return empty array if organisations are not available.
			return [];
		}
	}//end getAvailableOrganisations()

	/**
	 * Get available users.
	 *
	 * @return string[] Array of user_id => user_display_name
	 *
	 * @psalm-return array<string, string>
	 */
	private function getAvailableUsers(): array {
		$users = [];

		// Get all Nextcloud users (limit to prevent performance issues).
		$nextcloudUsers = $this->userManager->search('', 100);
		foreach ($nextcloudUsers as $user) {
			$users[$user->getUID()] = $user->getUID();
			if (($user->getDisplayName() !== null) === true && ($user->getDisplayName() !== '') === true) {
				$users[$user->getUID()] = $user->getDisplayName();
			}
		}

		return $users;
	}//end getAvailableUsers()

	/**
	 * Update the settings configuration.
	 *
	 * @param array $data The settings data to update.
	 *
	 * @return (bool|int|mixed|null|string)[][]
	 *
	 * @throws \RuntimeException If settings update fails.
	 *
	 * @psalm-return array{
	 *     version: array{appName: 'Open Register', appVersion: '0.2.3'},
	 *     rbac?: array{
	 *         enabled: mixed|true,
	 *         anonymousGroup: 'public'|mixed,
	 *         defaultNewUserGroup: 'viewer'|mixed,
	 *         defaultObjectOwner: ''|mixed,
	 *         adminOverride: mixed|true
	 *     },
	 *     multitenancy?: array{
	 *         enabled: mixed|true,
	 *         defaultUserTenant: ''|mixed,
	 *         defaultObjectTenant: ''|mixed,
	 *         adminOverride: mixed|true
	 *     },
	 *     availableGroups: array<string, string>,
	 *     availableTenants: array<string, null|string>,
	 *     availableUsers: array<string, string>,
	 *     retention?: array{
	 *         objectArchiveRetention: 31536000000|mixed,
	 *         objectDeleteRetention: 63072000000|mixed,
	 *         searchTrailRetention: 2592000000|mixed,
	 *         createLogRetention: 2592000000|mixed,
	 *         readLogRetention: 86400000|mixed,
	 *         updateLogRetention: 604800000|mixed,
	 *         deleteLogRetention: 2592000000|mixed,
	 *         auditTrailsEnabled: mixed|true,
	 *         searchTrailsEnabled: mixed|true
	 *     }
	 * }
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function updateSettings(array $data): array {
		// The BEFORE half of the announcement (D-6). Taken here rather than
		// derived from $data, because $data is what the caller SENT and a
		// setting it omits keeps its stored value: comparing against the
		// request would announce changes nobody made and miss the ones they
		// did. Cheap by construction: the registry reads only the marked keys,
		// never getSettings(), which also lists every group and user.
		$beforeSecurity = $this->announcer?->snapshot();
		$beforeChange = $this->changeRecorder?->snapshot(keys: OwnSettingsChangeRecorder::FULL_SAVE_KEYS);

		try {
			$this->storeSubmittedSections(data: $data);

			// Record and announce after the writes and inside the try, so a
			// save that threw records nothing. The recorder writes the
			// `settings.updated` rows through the SettingsChangeAuditor and
			// announces the security-marked settings; both are fail-soft, so a
			// row or a notification that cannot be written does not turn a
			// successful save into an error. Without a recorder (older wiring)
			// the announcer still tells a person on its own.
			if ($beforeChange !== null) {
				$this->changeRecorder?->record(before: $beforeChange, keys: OwnSettingsChangeRecorder::FULL_SAVE_KEYS);
			} elseif ($this->announcer !== null && $beforeSecurity !== null) {
				$this->announcer->announce($beforeSecurity, $this->announcer->snapshot());
			}

			return $this->getSettings();
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update settings: ' . $e->getMessage());
		}//end try
	}//end updateSettings()

	/**
	 * Store every section the save carries; a section it leaves out or sends as null keeps its stored value.
	 *
	 * @param array<string, mixed> $data The submitted settings.
	 *
	 * @return void
	 */
	private function storeSubmittedSections(array $data): void {
		if (($data['rbac'] ?? null) !== null) {
			$this->storeSection(key: 'rbac', values: $data['rbac']);
		}

		$this->storePartyCap(party: $data['party'] ?? null);
		$this->storeFlowSettings(flow: $data['flow'] ?? null);
		foreach (['multitenancy', 'retention'] as $key) {
			if (($data[$key] ?? null) !== null) {
				$this->storeSection(key: $key, values: $data[$key]);
			}
		}
	}//end storeSubmittedSections()

	/**
	 * Store the party query cap. A cap of zero or below is refused rather than
	 * stored: it would refuse every party query on the instance, which is never
	 * what a mistyped field is asking for.
	 *
	 * @param mixed $party The submitted party section.
	 *
	 * @return void
	 */
	private function storePartyCap($party): void {
		if (is_array($party) === false) {
			return;
		}

		$cap = (int)($party['queryCap'] ?? 0);
		if ($cap > 0) {
			$this->appConfig->setValueInt(AppIdentity::APP_ID, PartySearchService::CAP_KEY, $cap);
		}
	}//end storePartyCap()

	/**
	 * Store the flow engine settings. A non-positive retention is refused rather
	 * than stored: zero would mean "delete all run history on the next sweep",
	 * which is never what a mistyped field is asking for.
	 *
	 * @param mixed $flow The submitted flow section; null stores nothing.
	 *
	 * @return void
	 */
	private function storeFlowSettings($flow): void {
		if ($flow === null) {
			return;
		}

		if (is_array($flow) === false) {
			$flow = [];
		}

		$days = (int)($flow['retentionDays'] ?? 31);
		if ($days > 0) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'flow_run_retention_days', (string)$days);
		}

		foreach (self::FLOW_SWITCHES as $field => [$key, $default]) {
			$this->appConfig->setValueBool(AppIdentity::APP_ID, $key, (bool)($flow[$field] ?? $default));
		}
	}//end storeFlowSettings()

	/**
	 * Get focused RBAC settings only
	 *
	 * @return (mixed|string|true)[][]
	 *
	 * @throws \RuntimeException If RBAC settings retrieval fails
	 *
	 * @psalm-return array{rbac: array{enabled: mixed|true,
	 *     anonymousGroup: 'public'|mixed, defaultNewUserGroup: 'viewer'|mixed,
	 *     defaultObjectOwner: ''|mixed, adminOverride: mixed|true},
	 *     availableGroups: array<string, string>,
	 *     availableUsers: array<string, string>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getRbacSettingsOnly(): array {
		try {
			return [
				'rbac' => $this->storedSection(key: 'rbac'),
				'availableGroups' => $this->getAvailableGroups(),
				'availableUsers' => $this->getAvailableUsers(),
			];
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve RBAC settings: ' . $e->getMessage());
		}//end try
	}//end getRbacSettingsOnly()

	/**
	 * Update RBAC settings only
	 *
	 * @param array $rbacData RBAC configuration data
	 *
	 * @return (mixed|string|true)[][]
	 *
	 * @throws \RuntimeException If RBAC settings update fails
	 *
	 * @psalm-return array{rbac: array{enabled: mixed|true,
	 *     anonymousGroup: 'public'|mixed, defaultNewUserGroup: 'viewer'|mixed,
	 *     defaultObjectOwner: ''|mixed, adminOverride: mixed|true},
	 *     availableGroups: array<string, string>,
	 *     availableUsers: array<string, string>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function updateRbacSettingsOnly(array $rbacData): array {
		$beforeChange = $this->changeRecorder?->snapshot(keys: ['rbac']);
		try {
			$rbacConfig = $this->storeSection(key: 'rbac', values: $rbacData);
			$this->recordSettingsChange(before: $beforeChange, keys: ['rbac']);

			return [
				'rbac' => $rbacConfig,
				'availableGroups' => $this->getAvailableGroups(),
				'availableUsers' => $this->getAvailableUsers(),
			];
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update RBAC settings: ' . $e->getMessage());
		}
	}//end updateRbacSettingsOnly()

	/**
	 * Get Organisation settings only
	 *
	 * @return (mixed|null|true)[][] Organisation configuration
	 *
	 * @throws \RuntimeException If Organisation settings retrieval fails
	 *
	 * @psalm-return array{organisation: array{
	 *     default_organisation: mixed|null,
	 *     auto_create_default_organisation: mixed|true
	 * }}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getOrganisationSettingsOnly(): array {
		try {
			return [
				'organisation' => $this->storedSection(key: 'organisation'),
			];
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve Organisation settings: ' . $e->getMessage());
		}//end try
	}//end getOrganisationSettingsOnly()

	/**
	 * Update Organisation settings only
	 *
	 * @param array $organisationData Organisation configuration data
	 *
	 * @return (mixed|null|true)[][] Updated Organisation configuration
	 *
	 * @throws \RuntimeException If Organisation settings update fails
	 *
	 * @psalm-return array{organisation: array{
	 *     default_organisation: mixed|null,
	 *     auto_create_default_organisation: mixed|true
	 * }}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function updateOrganisationSettingsOnly(array $organisationData): array {
		$beforeChange = $this->changeRecorder?->snapshot(keys: ['organisation']);
		try {
			$organisationConfig = $this->storeSection(key: 'organisation', values: $organisationData);
			$this->recordSettingsChange(before: $beforeChange, keys: ['organisation']);

			return [
				'organisation' => $organisationConfig,
			];
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update Organisation settings: ' . $e->getMessage());
		}
	}//end updateOrganisationSettingsOnly()

	/**
	 * Get default organisation UUID from settings
	 *
	 * @return string|null Default organisation UUID or null if not set
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-3
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getDefaultOrganisationUuid(): ?string {
		try {
			$settings = $this->getOrganisationSettingsOnly();
			return $settings['organisation']['default_organisation'] ?? null;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[ConfigurationSettingsHandler] Failed to get default organisation UUID: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end getDefaultOrganisationUuid()

	/**
	 * Get tenant ID from multitenancy settings
	 *
	 * @return string|null Tenant ID (default user tenant) or null if not set
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-3
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getTenantId(): ?string {
		try {
			$multitenancySettings = $this->getMultitenancySettingsOnly();
			return $multitenancySettings['multitenancy']['defaultUserTenant'] ?? null;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[ConfigurationSettingsHandler] Failed to get tenant ID: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end getTenantId()

	/**
	 * Get organisation ID (alias for getDefaultOrganisationUuid)
	 *
	 * @return string|null Organisation ID or null if not set
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getOrganisationId(): ?string {
		return $this->getDefaultOrganisationUuid();
	}//end getOrganisationId()

	/**
	 * Set default organisation UUID in settings
	 *
	 * @param string|null $uuid Default organisation UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-3
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function setDefaultOrganisationUuid(?string $uuid): void {
		try {
			$settings = $this->getOrganisationSettingsOnly();
			$settings['organisation']['default_organisation'] = $uuid;
			$this->updateOrganisationSettingsOnly(organisationData: $settings['organisation']);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ConfigurationSettingsHandler] Failed to set default organisation UUID: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end setDefaultOrganisationUuid()

	/**
	 * Get focused Multitenancy settings only
	 *
	 * @return array Multitenancy configuration with available tenants
	 * @throws \RuntimeException If Multitenancy settings retrieval fails
	 */

	/**
	 * Get multitenancy settings only (detailed implementation)
	 *
	 * @return array[] Multitenancy configuration settings
	 *
	 * @psalm-return array{multitenancy: array{enabled: false|mixed,
	 *     defaultUserTenant: ''|mixed, defaultObjectTenant: ''|mixed,
	 *     adminOverride: mixed|true}, availableTenants: array}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getMultitenancySettingsOnly(): array {
		try {
			return [
				'multitenancy' => $this->storedSection(key: 'multitenancy'),
				'availableTenants' => $this->getAvailableOrganisations(),
			];
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve Multitenancy settings: ' . $e->getMessage());
		}//end try
	}//end getMultitenancySettingsOnly()

	/**
	 * Update Multitenancy settings only
	 *
	 * @param array $multitenancyData Multitenancy configuration data
	 *
	 * @throws \RuntimeException If Multitenancy settings update fails
	 *
	 * @return array Updated multitenancy config with settings and available tenants.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function updateMultitenancySettingsOnly(array $multitenancyData): array {
		$beforeChange = $this->changeRecorder?->snapshot(keys: ['multitenancy']);
		try {
			$multitenancyConfig = $this->storeSection(key: 'multitenancy', values: $multitenancyData);
			$this->recordSettingsChange(before: $beforeChange, keys: ['multitenancy']);

			return [
				'multitenancy' => $multitenancyConfig,
				'availableTenants' => $this->getAvailableOrganisations(),
			];
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update Multitenancy settings: ' . $e->getMessage());
		}
	}//end updateMultitenancySettingsOnly()

	/**
	 * Hand a per-section save to the change recorder.
	 *
	 * @param array{settings: array<string, mixed>, security: array<string, mixed>}|null $before The snapshot, or null.
	 * @param array<int, string>                                                         $keys   The keys the save wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	private function recordSettingsChange(?array $before, array $keys): void {
		if ($before === null || $this->changeRecorder === null) {
			return;
		}

		$this->changeRecorder->record(before: $before, keys: $keys);
	}//end recordSettingsChange()



	/**
	 * Get only version information.
	 *
	 * Returns version and build information for the application.
	 *
	 * @return array Version info with name, version, description, author, licence, timestamp, and date.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-2
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getVersionInfoOnly(): array {
		try {
			$appInfo = $this->appManager->getAppInfo(AppIdentity::APP_ID);

			return [
				'version' => ($appInfo['version'] ?? null) ?? 'unknown',
				'name' => ($appInfo['name'] ?? null) ?? 'OpenRegister',
				'description' => ($appInfo['description'] ?? null) ?? '',
				'author' => ($appInfo['author'] ?? null) ?? 'Conduction',
				'licence' => ($appInfo['licence'] ?? null) ?? 'EUPL-1.2',
				'timestamp' => time(),
				'date' => date('Y-m-d H:i:s'),
			];
		} catch (\Throwable $e) {
			return [
				'version' => 'unknown',
				'error' => 'Failed to retrieve version info: ' . $e->getMessage(),
			];
		}
	}//end getVersionInfoOnly()
}//end class
