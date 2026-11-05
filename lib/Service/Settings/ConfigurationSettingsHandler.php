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
	/**
	 * Default Fireworks API URL
	 *
	 * @var string
	 */
	private const FIREWORKS_API_URL = 'https://api.fireworks.ai/inference/v1';

	/**
	 * The JSON settings sections and the default of every key in them.
	 *
	 * Multitenancy is enabled by default, for proper data isolation. Retention
	 * periods are in milliseconds: one year to archive, two years to delete,
	 * a month of search trails and of create and delete logs, 24 hours of read
	 * logs and a week of update logs.
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
		'retention' => [
			'objectArchiveRetention' => 31536000000,
			'objectDeleteRetention' => 63072000000,
			'searchTrailRetention' => 2592000000,
			'createLogRetention' => 2592000000,
			'readLogRetention' => 86400000,
			'updateLogRetention' => 604800000,
			'deleteLogRetention' => 2592000000,
			'auditTrailsEnabled' => true,
			'searchTrailsEnabled' => true,
		],
		'organisation' => [
			'default_organisation' => null,
			'auto_create_default_organisation' => true,
		],
	];

	/**
	 * The flow engine switches: settings field => [app config key, default].
	 *
	 * These are administrator settings, deliberately: whether every hop is
	 * audit-trailed and whether the oversight gate runs are instance policy,
	 * not a per-user preference. The two have opposite defaults on purpose.
	 * Auditing is write volume (one entry per node per run) and the step rows
	 * already carry the operational history, so it is opt-in. Oversight is
	 * what stops a running flow, and a safety rail defaulting to off protects
	 * only the flows somebody remembered to configure.
	 *
	 * @var array<string, array{0: string, 1: bool}>
	 */
	private const FLOW_SWITCHES = [
		'auditEnabled' => ['flow_audit_enabled', false],
		'oversightEnabled' => ['flow_oversight_enabled', true],
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
	 * Application name
	 *
	 * @var string
	 */
	private string $appName;

	/**
	 * Constructor for ConfigurationSettingsHandler
	 *
	 * @param IAppConfig $appConfig Configuration service.
	 * @param IGroupManager $groupManager Group manager.
	 * @param IUserManager $userManager User manager.
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper.
	 * @param LoggerInterface $logger Logger.
	 * @param IAppManager $appManager App manager, read for the app's own version info.
	 * @param string $appName Application name.
	 * @param SecuritySettingAnnouncer|null $announcer Tells the administrators when a marked setting moves.
	 * @param OwnSettingsChangeRecorder|null $changeRecorder Records every save on the audit trail, and announces it.
	 *
	 * @return void
	 */
	public function __construct(
		IAppConfig $appConfig,
		IGroupManager $groupManager,
		IUserManager $userManager,
		OrganisationMapperInterface $organisationMapper,
		LoggerInterface $logger,
		private readonly IAppManager $appManager,
		string $appName = 'openregister',
		private readonly ?SecuritySettingAnnouncer $announcer = null,
		private readonly ?OwnSettingsChangeRecorder $changeRecorder = null,
	) {
		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->organisationMapper = $organisationMapper;
		$this->logger = $logger;
		$this->appName = $appName;
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
		$multitenancyConfig = $this->appConfig->getValueString($this->appName, 'multitenancy', '');
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
					$this->appName,
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
			'processed' => (int)$this->appConfig->getValueString($this->appName, 'blob_migration_processed', '0'),
			'remaining' => (int)$this->appConfig->getValueString($this->appName, 'blob_migration_remaining', '0'),
			'complete' => $this->appConfig->getValueString($this->appName, 'blob_migration_complete', 'false') === 'true',
			'lastRun' => $this->appConfig->getValueString($this->appName, 'blob_migration_last_run', ''),
		];
	}//end blobMigrationStatus()

	/**
	 * The flow engine settings: run history retention and the switches in FLOW_SWITCHES.
	 *
	 * @return array<string, int|bool>
	 */
	private function flowSettings(): array {
		$flow = [
			'retentionDays' => (int)$this->appConfig->getValueString($this->appName, 'flow_run_retention_days', '31'),
		];
		foreach (self::FLOW_SWITCHES as $field => [$key, $default]) {
			$flow[$field] = $this->appConfig->getValueBool($this->appName, $key, $default);
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
		$stored = $this->appConfig->getValueString($this->appName, $key, '');
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
	private function storeSection(string $key, mixed $values): array {
		$section = $this->overDefaults(defaults: self::SECTION_DEFAULTS[$key], values: $values);
		$this->appConfig->setValueString($this->appName, $key, json_encode($section));

		return $section;
	}//end storeSection()

	/**
	 * Every default key with the value set for it in $values, or the default.
	 *
	 * @param array<string, mixed> $defaults The keys and their defaults.
	 * @param mixed                $values   The values; anything but an array sets none.
	 *
	 * @return array<string, mixed>
	 */
	private function overDefaults(array $defaults, mixed $values): array {
		if (is_array($values) === false) {
			$values = [];
		}

		$section = [];
		foreach ($defaults as $key => $default) {
			$section[$key] = $values[$key] ?? $default;
		}

		return $section;
	}//end overDefaults()

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
	private function storePartyCap(mixed $party): void {
		if (is_array($party) === false) {
			return;
		}

		$cap = (int)($party['queryCap'] ?? 0);
		if ($cap > 0) {
			$this->appConfig->setValueInt($this->appName, PartySearchService::CAP_KEY, $cap);
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
	private function storeFlowSettings(mixed $flow): void {
		if ($flow === null) {
			return;
		}

		if (is_array($flow) === false) {
			$flow = [];
		}

		$days = (int)($flow['retentionDays'] ?? 31);
		if ($days > 0) {
			$this->appConfig->setValueString($this->appName, 'flow_run_retention_days', (string)$days);
		}

		foreach (self::FLOW_SWITCHES as $field => [$key, $default]) {
			$this->appConfig->setValueBool($this->appName, $key, (bool)($flow[$field] ?? $default));
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
	 * Get LLM settings only
	 *
	 * @return array LLM configuration
	 *
	 * @throws \RuntimeException If LLM settings retrieval fails
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *     Backward compatibility requires multiple field existence checks
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *     Default configuration structure requires comprehensive initialization
	 *     Nested else branches handle optional vector config backward compatibility
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getLLMSettingsOnly(): array {
		try {
			$llmConfig = $this->appConfig->getValueString($this->appName, 'llm', '');

			if (empty($llmConfig) === true) {
				// Return default configuration.
				return [
					'enabled' => false,
					'embeddingProvider' => null,
					'chatProvider' => null,
					'openaiConfig' => [
						'apiKey' => '',
						'model' => null,
						'chatModel' => null,
						'organizationId' => '',
					],
					'ollamaConfig' => [
						'url' => 'http://localhost:11434',
						'model' => null,
						'chatModel' => null,
					],
					'fireworksConfig' => [
						'apiKey' => '',
						'embeddingModel' => null,
						'chatModel' => null,
						'baseUrl' => 'https://api.fireworks.ai/inference/v1',
					],
					'vectorConfig' => [
						'backend' => 'php',
					],
				];
			}//end if

			$decoded = json_decode($llmConfig, true);

			// Ensure enabled field exists (for backward compatibility).
			if (isset($decoded['enabled']) === false) {
				$decoded['enabled'] = false;
			}

			// Ensure vector config exists (for backward compatibility).
			if (isset($decoded['vectorConfig']) === false) {
				$decoded['vectorConfig'] = [
					'backend' => 'php',
				];
			}

			if (isset($decoded['vectorConfig']) === true) {
				// Ensure all vector config fields exist.
				if (isset($decoded['vectorConfig']['backend']) === false) {
					$decoded['vectorConfig']['backend'] = 'php';
				}

				// Drop the retired Solr vector keys.
				unset($decoded['vectorConfig']['solrField'], $decoded['vectorConfig']['solrCollection']);
			}

			return $decoded;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve LLM settings: ' . $e->getMessage());
		}//end try
	}//end getLLMSettingsOnly()

	/**
	 * Update LLM settings only
	 *
	 * @param array $llmData LLM configuration data
	 *
	 * @throws \RuntimeException If LLM settings update fails
	 *
	 * @return array Updated LLM config with providers and their configurations.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) PATCH behavior requires merging multiple nested configuration structures
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function updateLLMSettingsOnly(array $llmData): array {
		try {
			// Get existing config for PATCH support.
			$existingConfig = $this->getLLMSettingsOnly();

			// Merge with existing config (PATCH behavior).
			$newConfig = $llmData;
			$oldConfig = $existingConfig;
			$newOpenai = $newConfig['openaiConfig'] ?? [];
			$oldOpenai = $oldConfig['openaiConfig'] ?? [];
			$newOllama = $newConfig['ollamaConfig'] ?? [];
			$oldOllama = $oldConfig['ollamaConfig'] ?? [];
			$newFireworks = $newConfig['fireworksConfig'] ?? [];
			$oldFireworks = $oldConfig['fireworksConfig'] ?? [];
			$newVector = $newConfig['vectorConfig'] ?? [];
			$oldVector = $oldConfig['vectorConfig'] ?? [];

			$llmConfig = [
				'enabled' => $newConfig['enabled'] ?? $oldConfig['enabled'] ?? false,
				'embeddingProvider' => $newConfig['embeddingProvider'] ?? $oldConfig['embeddingProvider'] ?? null,
				'chatProvider' => $newConfig['chatProvider'] ?? $oldConfig['chatProvider'] ?? null,
				'openaiConfig' => [
					'apiKey' => $newOpenai['apiKey'] ?? $oldOpenai['apiKey'] ?? '',
					'model' => $newOpenai['model'] ?? $oldOpenai['model'] ?? null,
					'chatModel' => $newOpenai['chatModel'] ?? $oldOpenai['chatModel'] ?? null,
					'organizationId' => $newOpenai['organizationId'] ?? $oldOpenai['organizationId'] ?? '',
				],
				'ollamaConfig' => [
					'url' => $newOllama['url'] ?? $oldOllama['url'] ?? 'http://localhost:11434',
					'model' => $newOllama['model'] ?? $oldOllama['model'] ?? null,
					'chatModel' => $newOllama['chatModel'] ?? $oldOllama['chatModel'] ?? null,
				],
				'fireworksConfig' => [
					'apiKey' => $newFireworks['apiKey'] ?? $oldFireworks['apiKey'] ?? '',
					'embeddingModel' => $newFireworks['embeddingModel'] ?? $oldFireworks['embeddingModel'] ?? null,
					'chatModel' => $newFireworks['chatModel'] ?? $oldFireworks['chatModel'] ?? null,
					'baseUrl' => $newFireworks['baseUrl'] ?? $oldFireworks['baseUrl'] ?? self::FIREWORKS_API_URL,
				],
				'vectorConfig' => [
					'backend' => $newVector['backend'] ?? $oldVector['backend'] ?? 'php',
				],
			];

			$this->appConfig->setValueString($this->appName, 'llm', json_encode($llmConfig));
			return $llmConfig;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update LLM settings: ' . $e->getMessage());
		}//end try
	}//end updateLLMSettingsOnly()

	/**
	 * Get File Management settings only
	 *
	 * @return array File management configuration
	 *
	 * @throws \RuntimeException If File Management settings retrieval fails
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive file settings require many default configuration values
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function getFileSettingsOnly(): array {
		try {
			$fileConfig = $this->appConfig->getValueString($this->appName, 'fileManagement', '');

			if (empty($fileConfig) === true) {
				// Return default configuration.
				return [
					'vectorizationEnabled' => false,
					'provider' => null,
					'chunkingStrategy' => 'RECURSIVE_CHARACTER',
					'chunkSize' => 1000,
					'chunkOverlap' => 200,
					// LLPhant-friendly defaults: native PHP support + common library-based formats.
					'enabledFileTypes' => [
						'txt',
						'md',
						'html',
						'json',
						'xml',
						'csv',
						'pdf',
						'docx',
						'doc',
						'xlsx',
						'xls',
					],
					'ocrEnabled' => false,
					'maxFileSizeMB' => 100,
					// Text extraction settings (for FileConfiguration component).
					'extractionScope' => 'objects',
					// None, all, folders, objects.
					'textExtractor' => 'llphant',
					// Llphant, dolphin.
					'extractionMode' => 'background',
					// Background, immediate, manual.
					'maxFileSize' => 100,
					'batchSize' => 10,
					'dolphinApiEndpoint' => '',
					'dolphinApiKey' => '',
					// Presidio entity recognition settings.
					'presidioApiEndpoint' => '',
					'entityRecognitionEnabled' => false,
					'entityRecognitionMethod' => 'hybrid',
					// Regex, presidio, llm, hybrid.
				];
			}//end if

			return json_decode($fileConfig, true);
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve File Management settings: ' . $e->getMessage());
		}//end try
	}//end getFileSettingsOnly()

	/**
	 * Update File Management settings only
	 *
	 * @param array $fileData File management configuration data
	 *
	 * @return (false|int|mixed|null|string[])[] Updated file management configuration
	 *
	 * @throws \RuntimeException
	 *
	 * @psalm-return array{vectorizationEnabled: false|mixed, provider: mixed|null,
	 *     chunkingStrategy: 'RECURSIVE_CHARACTER'|mixed, chunkSize: 1000|mixed,
	 *     chunkOverlap: 200|mixed,
	 *     enabledFileTypes: list{'txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf',
	 *     'docx', 'doc', 'xlsx', 'xls'}|mixed, ocrEnabled: false|mixed,
	 *     maxFileSizeMB: 100|mixed, extractionScope: 'objects'|mixed,
	 *     textExtractor: 'llphant'|mixed, extractionMode: 'background'|mixed,
	 *     maxFileSize: 100|mixed, batchSize: 10|mixed,
	 *     dolphinApiEndpoint: ''|mixed, dolphinApiKey: ''|mixed}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive file settings require many configuration fields
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 */
	public function updateFileSettingsOnly(array $fileData): array {
		try {
			$fileConfig = [
				'vectorizationEnabled' => $fileData['vectorizationEnabled'] ?? false,
				'provider' => $fileData['provider'] ?? null,
				'chunkingStrategy' => $fileData['chunkingStrategy'] ?? 'RECURSIVE_CHARACTER',
				'chunkSize' => $fileData['chunkSize'] ?? 1000,
				'chunkOverlap' => $fileData['chunkOverlap'] ?? 200,
				'enabledFileTypes' => $fileData['enabledFileTypes'] ?? [
					'txt',
					'md',
					'html',
					'json',
					'xml',
					'csv',
					'pdf',
					'docx',
					'doc',
					'xlsx',
					'xls',
				],
				'ocrEnabled' => $fileData['ocrEnabled'] ?? false,
				'maxFileSizeMB' => $fileData['maxFileSizeMB'] ?? 100,
				// Text extraction settings (from FileConfiguration component).
				'extractionScope' => $fileData['extractionScope'] ?? 'objects',
				// None, all, folders, objects.
				'textExtractor' => $fileData['textExtractor'] ?? 'llphant',
				// Llphant, dolphin.
				'extractionMode' => $fileData['extractionMode'] ?? 'background',
				// Background, immediate, manual.
				'maxFileSize' => $fileData['maxFileSize'] ?? 100,
				'batchSize' => $fileData['batchSize'] ?? 10,
				'dolphinApiEndpoint' => $fileData['dolphinApiEndpoint'] ?? '',
				'dolphinApiKey' => $fileData['dolphinApiKey'] ?? '',
				// Presidio entity recognition settings.
				'presidioApiEndpoint' => $fileData['presidioApiEndpoint'] ?? '',
				'entityRecognitionEnabled' => $fileData['entityRecognitionEnabled'] ?? false,
				'entityRecognitionMethod' => $fileData['entityRecognitionMethod'] ?? 'hybrid',
				// Regex, presidio, llm, hybrid.
			];

			$this->appConfig->setValueString($this->appName, 'fileManagement', json_encode($fileConfig));
			return $fileConfig;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update File Management settings: ' . $e->getMessage());
		}//end try
	}//end updateFileSettingsOnly()



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
			$appInfo = $this->appManager->getAppInfo($this->appName);

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
