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
 */

namespace OCA\OpenRegister\Service\Settings;

use Exception;
use RuntimeException;
use OCP\IConfig;

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
    private IConfig $config;
    private string $appName;

    public function __construct(IConfig $config, string $appName = "openregister")
    {
        $this->config = $config;
        $this->appName = $appName;
    }

     * Update Object Management settings only
     *
     * @param  array $objectData Object management configuration data
     * @return array Updated object management configuration
     * @throws \RuntimeException
     */


    /**
     * Get focused Object settings only (vectorization config)
     *
     * @return (array|bool|int|mixed)[] Object vectorization configuration
     *
     * @throws \RuntimeException If Object settings retrieval fails
     *
     * @psalm-return array{vectorizationEnabled: false|mixed, vectorizeOnCreate: mixed|true, vectorizeOnUpdate: false|mixed, vectorizeAllViews: mixed|true, enabledViews: array<never, never>|mixed, includeMetadata: mixed|true, includeRelations: mixed|true, maxNestingDepth: 10|mixed, batchSize: 25|mixed, autoRetry: mixed|true}
     */
    public function getObjectSettingsOnly(): array
    {
        try {
            $objectConfig = $this->config->getAppValue($this->appName, 'objectManagement', '');

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
     * @psalm-return array{vectorizationEnabled: false|mixed, vectorizeOnCreate: mixed|true, vectorizeOnUpdate: false|mixed, vectorizeAllViews: mixed|true, enabledViews: array<never, never>|mixed, includeMetadata: mixed|true, includeRelations: mixed|true, maxNestingDepth: 10|mixed, batchSize: 25|mixed, autoRetry: mixed|true}
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

            $this->config->setAppValue($this->appName, 'objectManagement', json_encode($objectConfig));
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
     * @psalm-return array{objectArchiveRetention: 31536000000|mixed, objectDeleteRetention: 63072000000|mixed, searchTrailRetention: 2592000000|mixed, createLogRetention: 2592000000|mixed, readLogRetention: 86400000|mixed, updateLogRetention: 604800000|mixed, deleteLogRetention: 2592000000|mixed, auditTrailsEnabled: bool, searchTrailsEnabled: bool}
     */
    public function getRetentionSettingsOnly(): array
    {
        try {
            $retentionConfig = $this->config->getAppValue($this->appName, 'retention', '');

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
                'auditTrailsEnabled'     => $this->convertToBoolean($retentionData['auditTrailsEnabled'] ?? true),
                'searchTrailsEnabled'    => $this->convertToBoolean($retentionData['searchTrailsEnabled'] ?? true),
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
     * @psalm-return array{objectArchiveRetention: 31536000000|mixed, objectDeleteRetention: 63072000000|mixed, searchTrailRetention: 2592000000|mixed, createLogRetention: 2592000000|mixed, readLogRetention: 86400000|mixed, updateLogRetention: 604800000|mixed, deleteLogRetention: 2592000000|mixed, auditTrailsEnabled: mixed|true, searchTrailsEnabled: mixed|true}
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

            $this->config->setAppValue($this->appName, 'retention', json_encode($retentionConfig));
            return $retentionConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update Retention settings: '.$e->getMessage());
        }
    }//end updateRetentionSettingsOnly()


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
