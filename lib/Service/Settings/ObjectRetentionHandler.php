<?php

/**
 * OpenRegister Object and Retention Settings Handler
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
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-64
 */

namespace OCA\OpenRegister\Service\Settings;

use Exception;
use RuntimeException;
use OCP\IAppConfig;

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
class ObjectRetentionHandler
{

    /**
     * Nextcloud configuration instance
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Application name identifier
     *
     * @var string
     */
    private string $appName;

    /**
     * Constructor for ObjectRetentionHandler.
     *
     * @param IAppConfig $appConfig Configuration service.
     * @param string     $appName   Application name (default: 'openregister').
     */
    public function __construct(IAppConfig $appConfig, string $appName="openregister")
    {
        $this->appConfig = $appConfig;
        $this->appName   = $appName;
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-64
     */
    public function getObjectSettingsOnly(): array
    {
        try {
            $objectConfig = $this->appConfig->getValueString($this->appName, 'objectManagement', '');

            if (empty($objectConfig) === true) {
                return [
                    'vectorizationEnabled' => false,
                    'vectorizeOnCreate'    => true,
                    'vectorizeOnUpdate'    => false,
                    'vectorizeAllViews'    => true,
                    'enabledViews'         => [],
                    'includeMetadata'      => true,
                    'includeRelations'     => true,
                    'maxNestingDepth'      => 10,
                    'batchSize'            => 25,
                    'autoRetry'            => true,
                ];
            }

            $objectData = json_decode($objectConfig, true);
            return [
                'vectorizationEnabled' => $objectData['vectorizationEnabled'] ?? false,
                'vectorizeOnCreate'    => $objectData['vectorizeOnCreate'] ?? true,
                'vectorizeOnUpdate'    => $objectData['vectorizeOnUpdate'] ?? false,
                'vectorizeAllViews'    => $objectData['vectorizeAllViews'] ?? ($objectData['vectorizeAllSchemas'] ?? true),
                'enabledViews'         => $objectData['enabledViews'] ?? ($objectData['enabledSchemas'] ?? []),
                'includeMetadata'      => $objectData['includeMetadata'] ?? true,
                'includeRelations'     => $objectData['includeRelations'] ?? true,
                'maxNestingDepth'      => $objectData['maxNestingDepth'] ?? 10,
                'batchSize'            => $objectData['batchSize'] ?? 25,
                'autoRetry'            => $objectData['autoRetry'] ?? true,
            ];
        } catch (Exception $e) {
            throw new RuntimeException('Failed to get Object Management settings: '.$e->getMessage());
        }//end try
    }//end getObjectSettingsOnly()

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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-64
     */
    public function updateObjectSettingsOnly(array $objectData): array
    {
        try {
            $objectConfig = [
                'vectorizationEnabled' => $objectData['vectorizationEnabled'] ?? false,
                'vectorizeOnCreate'    => $objectData['vectorizeOnCreate'] ?? true,
                'vectorizeOnUpdate'    => $objectData['vectorizeOnUpdate'] ?? false,
                'vectorizeAllViews'    => $objectData['vectorizeAllViews'] ?? true,
                'enabledViews'         => $objectData['enabledViews'] ?? [],
                'includeMetadata'      => $objectData['includeMetadata'] ?? true,
                'includeRelations'     => $objectData['includeRelations'] ?? true,
                'maxNestingDepth'      => $objectData['maxNestingDepth'] ?? 10,
                'batchSize'            => $objectData['batchSize'] ?? 25,
                'autoRetry'            => $objectData['autoRetry'] ?? true,
            ];

            $this->appConfig->setValueString($this->appName, 'objectManagement', json_encode($objectConfig));
            return $objectConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update Object Management settings: '.$e->getMessage());
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-64
     */
    public function getRetentionSettingsOnly(): array
    {
        try {
            $retentionConfig = $this->appConfig->getValueString($this->appName, 'retention', '');

            if (empty($retentionConfig) === true) {
                return [
                    'objectArchiveRetention' => 31536000000,
                // 1 year default
                    'objectDeleteRetention'  => 63072000000,
                // 2 years default
                    'searchTrailRetention'   => 2592000000,
                // 1 month default
                    'createLogRetention'     => 2592000000,
                // 1 month default
                    'readLogRetention'       => 86400000,
                // 24 hours default
                    'updateLogRetention'     => 604800000,
                // 1 week default
                    'deleteLogRetention'     => 2592000000,
                // 1 month default
                    'auditTrailsEnabled'     => true,
                // Audit trails enabled by default.
                    'searchTrailsEnabled'    => true,
                // Search trails enabled by default.
                ];
            }//end if

            $retentionData = json_decode($retentionConfig, true);
            return [
                'objectArchiveRetention' => $retentionData['objectArchiveRetention'] ?? 31536000000,
                'objectDeleteRetention'  => $retentionData['objectDeleteRetention'] ?? 63072000000,
                'searchTrailRetention'   => $retentionData['searchTrailRetention'] ?? 2592000000,
                'createLogRetention'     => $retentionData['createLogRetention'] ?? 2592000000,
                'readLogRetention'       => $retentionData['readLogRetention'] ?? 86400000,
                'updateLogRetention'     => $retentionData['updateLogRetention'] ?? 604800000,
                'deleteLogRetention'     => $retentionData['deleteLogRetention'] ?? 2592000000,
                'auditTrailsEnabled'     => $this->convertToBoolean(value: $retentionData['auditTrailsEnabled'] ?? true),
                'searchTrailsEnabled'    => $this->convertToBoolean(value: $retentionData['searchTrailsEnabled'] ?? true),
            ];
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve Retention settings: '.$e->getMessage());
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-64
     */
    public function updateRetentionSettingsOnly(array $retentionData): array
    {
        try {
            $retentionConfig = [
                'objectArchiveRetention' => $retentionData['objectArchiveRetention'] ?? 31536000000,
                'objectDeleteRetention'  => $retentionData['objectDeleteRetention'] ?? 63072000000,
                'searchTrailRetention'   => $retentionData['searchTrailRetention'] ?? 2592000000,
                'createLogRetention'     => $retentionData['createLogRetention'] ?? 2592000000,
                'readLogRetention'       => $retentionData['readLogRetention'] ?? 86400000,
                'updateLogRetention'     => $retentionData['updateLogRetention'] ?? 604800000,
                'deleteLogRetention'     => $retentionData['deleteLogRetention'] ?? 2592000000,
                'auditTrailsEnabled'     => $retentionData['auditTrailsEnabled'] ?? true,
                'searchTrailsEnabled'    => $retentionData['searchTrailsEnabled'] ?? true,
            ];

            $this->appConfig->setValueString($this->appName, 'retention', json_encode($retentionConfig));
            return $retentionConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update Retention settings: '.$e->getMessage());
        }
    }//end updateRetentionSettingsOnly()

    /**
     * Get archival settings (destruction scheduling, selectielijst config, etc.)
     *
     * @return array Archival configuration settings
     *
     * @throws \RuntimeException If archival settings retrieval fails
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-64
     */
    public function getArchivalSettingsOnly(): array
    {
        try {
            $archivalConfig = $this->appConfig->getValueString($this->appName, 'archival', '');

            if (empty($archivalConfig) === true) {
                return $this->getArchivalDefaults();
            }

            $archivalData = json_decode($archivalConfig, true);
            return [
                'destructionCheckInterval' => $archivalData['destructionCheckInterval'] ?? 86400,
                'notificationLeadDays'     => $archivalData['notificationLeadDays'] ?? 30,
                'defaultExtensionPeriod'   => $archivalData['defaultExtensionPeriod'] ?? 'P1Y',
                'destructionBatchSize'     => $archivalData['destructionBatchSize'] ?? 50,
                'selectielijstRegister'    => $archivalData['selectielijstRegister'] ?? null,
                'selectielijstSchema'      => $archivalData['selectielijstSchema'] ?? null,
                'destructionListRegister'  => $archivalData['destructionListRegister'] ?? null,
                'destructionListSchema'    => $archivalData['destructionListSchema'] ?? null,
                'archivalRegister'         => $archivalData['archivalRegister'] ?? null,
            ];
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve archival settings: '.$e->getMessage());
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-64
     */
    public function updateArchivalSettingsOnly(array $archivalData): array
    {
        try {
            $archivalConfig = [
                'destructionCheckInterval' => $archivalData['destructionCheckInterval'] ?? 86400,
                'notificationLeadDays'     => $archivalData['notificationLeadDays'] ?? 30,
                'defaultExtensionPeriod'   => $archivalData['defaultExtensionPeriod'] ?? 'P1Y',
                'destructionBatchSize'     => $archivalData['destructionBatchSize'] ?? 50,
                'selectielijstRegister'    => $archivalData['selectielijstRegister'] ?? null,
                'selectielijstSchema'      => $archivalData['selectielijstSchema'] ?? null,
                'destructionListRegister'  => $archivalData['destructionListRegister'] ?? null,
                'destructionListSchema'    => $archivalData['destructionListSchema'] ?? null,
                'archivalRegister'         => $archivalData['archivalRegister'] ?? null,
            ];

            $this->appConfig->setValueString($this->appName, 'archival', json_encode($archivalConfig));
            return $archivalConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update archival settings: '.$e->getMessage());
        }
    }//end updateArchivalSettingsOnly()

    /**
     * Get default archival settings
     *
     * @return array Default archival configuration
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-64
     */
    private function getArchivalDefaults(): array
    {
        return [
            'destructionCheckInterval' => 86400,
            'notificationLeadDays'     => 30,
            'defaultExtensionPeriod'   => 'P1Y',
            'destructionBatchSize'     => 50,
            'selectielijstRegister'    => null,
            'selectielijstSchema'      => null,
            'destructionListRegister'  => null,
            'destructionListSchema'    => null,
            'archivalRegister'         => null,
        ];
    }//end getArchivalDefaults()

    /**
     * Get version information only
     *
     * @return string[] Version information
     *
     * @throws \RuntimeException If version information retrieval fails
     *
     * @psalm-return array{appName: 'Open Register', appVersion: '0.2.3'}
     */
    public function getVersionInfoOnly(): array
    {
        try {
            return [
                'appName'    => 'Open Register',
                'appVersion' => '0.2.3',
            ];
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve version information: '.$e->getMessage());
        }
    }//end getVersionInfoOnly()

    /**
     * Convert various representations to boolean
     *
     * @param mixed $value The value to convert to boolean
     *
     * @return bool The boolean representation
     */
    private function convertToBoolean($value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_string($value) === true) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        if (is_numeric($value) === true) {
            return (int) $value !== 0;
        }

        return (bool) $value;
    }//end convertToBoolean()
}//end class
