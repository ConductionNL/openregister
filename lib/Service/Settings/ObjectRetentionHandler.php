<?php

/**
 * OpenRegister Object and Retention Settings Handler
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
 *
 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
 */

namespace OCA\OpenRegister\Service\Settings;

use Exception;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Handler for object and retention settings operations.
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
class ObjectRetentionHandler implements ArchivalSettingsReaderInterface, RetentionSettingsReaderInterface {
	use SettingsSectionDefaults;

	/**
	 * The object management settings returned when none are stored, and the default of every key on update.
	 *
	 * @var array<string, mixed>
	 */
	private const OBJECT_DEFAULTS = [
		'vectorizationEnabled' => false,
		'vectorizeOnCreate' => true,
		'vectorizeOnUpdate' => false,
		'vectorizeAllViews' => true,
		'enabledViews' => [],
		'includeMetadata' => true,
		'includeRelations' => true,
		'maxNestingDepth' => 10,
		'batchSize' => 25,
		'autoRetry' => true,
	];

	/**
	 * The retention settings returned when none are stored, and the default of every key on update.
	 *
	 * The shared retention defaults plus searchTrailRecordingMode, which says which
	 * searches to record (all, _search or none); the default records text
	 * searches only.
	 *
	 * @var array<string, mixed>
	 */
	private const RETENTION_DEFAULTS = self::RETENTION_PERIOD_DEFAULTS + ['searchTrailRecordingMode' => '_search'];

	/**
	 * The archival settings returned when none are stored, and the default of every key on update.
	 *
	 * @var array<string, mixed>
	 */
	private const ARCHIVAL_DEFAULTS = [
		'destructionCheckInterval' => 86400,
		'notificationLeadDays' => 30,
		'reviewReminderFrequency' => 'P7D',
		'defaultExtensionPeriod' => 'P1Y',
		'destructionBatchSize' => 50,
		'selectielijstRegister' => null,
		'selectielijstSchema' => null,
		'selectielijstVersion' => null,
		'destructionListRegister' => null,
		'destructionListSchema' => null,
		'archivalRegister' => null,
	];

	/**
	 * Nextcloud configuration instance
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;


	/**
	 * Constructor for ObjectRetentionHandler.
	 *
	 * @param IAppConfig $appConfig Configuration service.
	 * @param OwnSettingsChangeRecorder|null $changeRecorder Records every save on the audit trail, and announces it.
	 */
	public function __construct(
		IAppConfig $appConfig,
		private readonly ?OwnSettingsChangeRecorder $changeRecorder = null,
	) {
		$this->appConfig = $appConfig;
	}//end __construct()

	/**
	 * Get focused Object settings only (vectorization config)
	 *
	 * @return (array|bool|int|mixed)[] Object vectorization configuration
	 *
	 * @throws \RuntimeException If Object settings retrieval fails
	 *
	 * @psalm-return array{vectorizationEnabled: false|mixed,
	 *     vectorizeOnCreate: mixed|true, vectorizeOnUpdate: false|mixed,
	 *     vectorizeAllViews: mixed|true, enabledViews: array<never, never>|mixed,
	 *     includeMetadata: mixed|true, includeRelations: mixed|true,
	 *     maxNestingDepth: 10|mixed, batchSize: 25|mixed, autoRetry: mixed|true}
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
	 */
	public function getObjectSettingsOnly(): array {
		try {
			$objectConfig = $this->appConfig->getValueString(AppIdentity::APP_ID, 'objectManagement', '');
			if (empty($objectConfig) === true) {
				return self::OBJECT_DEFAULTS;
			}

			$objectData = $this->withViewKeys(objectData: json_decode($objectConfig, true));
			return $this->overDefaults(defaults: self::OBJECT_DEFAULTS, values: $objectData);
		} catch (Exception $e) {
			throw new RuntimeException('Failed to get Object Management settings: ' . $e->getMessage());
		}//end try
	}//end getObjectSettingsOnly()

	/**
	 * Stored object settings with the view keys read from their former schema names when unset.
	 *
	 * @param mixed $objectData The decoded stored settings.
	 *
	 * @return mixed
	 */
	private function withViewKeys($objectData) {
		if (is_array($objectData) === false) {
			return $objectData;
		}

		$objectData['vectorizeAllViews'] = $objectData['vectorizeAllViews'] ?? ($objectData['vectorizeAllSchemas'] ?? null);
		$objectData['enabledViews'] = $objectData['enabledViews'] ?? ($objectData['enabledSchemas'] ?? null);

		return $objectData;
	}//end withViewKeys()

	/**
	 * Update object management settings
	 *
	 * @param array $objectData Object data containing settings to update
	 *
	 * @return (array|bool|int|mixed)[] Updated object configuration
	 *
	 * @throws \RuntimeException If update fails
	 *
	 * @psalm-return array{vectorizationEnabled: false|mixed,
	 *     vectorizeOnCreate: mixed|true, vectorizeOnUpdate: false|mixed,
	 *     vectorizeAllViews: mixed|true, enabledViews: array<never, never>|mixed,
	 *     includeMetadata: mixed|true, includeRelations: mixed|true,
	 *     maxNestingDepth: 10|mixed, batchSize: 25|mixed, autoRetry: mixed|true}
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
	 */
	public function updateObjectSettingsOnly(array $objectData): array {
		$beforeChange = $this->changeRecorder?->snapshot(keys: ['objectManagement']);
		try {
			$objectConfig = $this->overDefaults(defaults: self::OBJECT_DEFAULTS, values: $objectData);
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'objectManagement', json_encode($objectConfig));
			$this->recordSettingsChange(before: $beforeChange, keys: ['objectManagement']);
			return $objectConfig;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update Object Management settings: ' . $e->getMessage());
		}
	}//end updateObjectSettingsOnly()

	/**
	 * Get focused Retention settings only
	 *
	 * @return (bool|int|mixed)[] Retention configuration
	 *
	 * @throws \RuntimeException If Retention settings retrieval fails
	 *
	 * @psalm-return array{objectArchiveRetention: 31536000000|mixed,
	 *     objectDeleteRetention: 63072000000|mixed,
	 *     searchTrailRetention: 2592000000|mixed,
	 *     createLogRetention: 2592000000|mixed, readLogRetention: 86400000|mixed,
	 *     updateLogRetention: 604800000|mixed,
	 *     deleteLogRetention: 2592000000|mixed, auditTrailsEnabled: bool,
	 *     searchTrailsEnabled: bool}
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
	 */
	public function getRetentionSettingsOnly(): array {
		try {
			$retentionConfig = $this->appConfig->getValueString(AppIdentity::APP_ID, 'retention', '');
			if (empty($retentionConfig) === true) {
				return self::RETENTION_DEFAULTS;
			}

			$retention = $this->overDefaults(defaults: self::RETENTION_DEFAULTS, values: json_decode($retentionConfig, true));
			$retention['auditTrailsEnabled'] = $this->convertToBoolean(value: $retention['auditTrailsEnabled']);
			$retention['searchTrailsEnabled'] = $this->convertToBoolean(value: $retention['searchTrailsEnabled']);
			$retention['searchTrailRecordingMode'] = $this->normaliseRecordingMode(value: $retention['searchTrailRecordingMode']);
			return $retention;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve Retention settings: ' . $e->getMessage());
		}//end try
	}//end getRetentionSettingsOnly()

	/**
	 * Update Retention settings only
	 *
	 * @param array $retentionData Retention configuration data
	 *
	 * @return (int|mixed|true)[] Updated Retention configuration
	 *
	 * @throws \RuntimeException If Retention settings update fails
	 *
	 * @psalm-return array{objectArchiveRetention: 31536000000|mixed,
	 *     objectDeleteRetention: 63072000000|mixed,
	 *     searchTrailRetention: 2592000000|mixed,
	 *     createLogRetention: 2592000000|mixed, readLogRetention: 86400000|mixed,
	 *     updateLogRetention: 604800000|mixed,
	 *     deleteLogRetention: 2592000000|mixed, auditTrailsEnabled: mixed|true,
	 *     searchTrailsEnabled: mixed|true}
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
	 */
	public function updateRetentionSettingsOnly(array $retentionData): array {
		$beforeChange = $this->changeRecorder?->snapshot(keys: ['retention']);
		try {
			$retentionConfig = $this->overDefaults(defaults: self::RETENTION_DEFAULTS, values: $retentionData);
			$retentionConfig['searchTrailRecordingMode'] = $this->normaliseRecordingMode(value: $retentionConfig['searchTrailRecordingMode']);

			$this->appConfig->setValueString(AppIdentity::APP_ID, 'retention', json_encode($retentionConfig));
			$this->recordSettingsChange(before: $beforeChange, keys: ['retention']);
			return $retentionConfig;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update Retention settings: ' . $e->getMessage());
		}
	}//end updateRetentionSettingsOnly()

	/**
	 * Get archival settings (destruction scheduling, selectielijst config, etc.)
	 *
	 * @return array Archival configuration settings
	 *
	 * @throws \RuntimeException If archival settings retrieval fails
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
	 */
	public function getArchivalSettingsOnly(): array {
		try {
			$archivalConfig = $this->appConfig->getValueString(AppIdentity::APP_ID, 'archival', '');
			if (empty($archivalConfig) === true) {
				return self::ARCHIVAL_DEFAULTS;
			}

			return $this->overDefaults(defaults: self::ARCHIVAL_DEFAULTS, values: json_decode($archivalConfig, true));
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve archival settings: ' . $e->getMessage());
		}//end try
	}//end getArchivalSettingsOnly()

	/**
	 * Update archival settings
	 *
	 * @param array $archivalData Archival configuration data
	 *
	 * @return array Updated archival configuration
	 *
	 * @throws \RuntimeException If archival settings update fails
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
	 */
	public function updateArchivalSettingsOnly(array $archivalData): array {
		$beforeChange = $this->changeRecorder?->snapshot(keys: ['archival']);
		try {
			$archivalConfig = $this->overDefaults(defaults: self::ARCHIVAL_DEFAULTS, values: $archivalData);
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'archival', json_encode($archivalConfig));
			$this->recordSettingsChange(before: $beforeChange, keys: ['archival']);
			return $archivalConfig;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update archival settings: ' . $e->getMessage());
		}
	}//end updateArchivalSettingsOnly()

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
	 * Get version information only
	 *
	 * @return string[] Version information
	 *
	 * @throws \RuntimeException If version information retrieval fails
	 *
	 * @psalm-return array{appName: 'Open Register', appVersion: '0.2.3'}
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function getVersionInfoOnly(): array {
		try {
			return [
				'appName' => 'Open Register',
				'appVersion' => '0.2.3',
			];
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve version information: ' . $e->getMessage());
		}
	}//end getVersionInfoOnly()

	/**
	 * Convert various representations to boolean
	 *
	 * @param mixed $value The value to convert to boolean
	 *
	 * @return bool The boolean representation
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function convertToBoolean($value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_string($value) === true) {
			return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
		}

		if (is_numeric($value) === true) {
			return (int)$value !== 0;
		}

		return (bool)$value;
	}//end convertToBoolean()

	/**
	 * Normalise the search-trail recording mode to an allowed value.
	 *
	 * Accepts only 'all', '_search', or 'none'; any other input falls back
	 * to the safe default '_search' (record text searches only).
	 *
	 * @param mixed $value The raw recording-mode value.
	 *
	 * @return string One of 'all', '_search', 'none'.
	 *
	 * @spec openspec/specs/search-trail-recording/spec.md
	 */
	private function normaliseRecordingMode($value): string {
		$mode = '';
		if (is_string($value) === true) {
			$mode = strtolower($value);
		}

		if (in_array($mode, ['all', '_search', 'none'], true) === true) {
			return $mode;
		}

		return '_search';
	}//end normaliseRecordingMode()
}//end class
