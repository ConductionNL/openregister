<?php
/**
 * OpenRegister File Settings Handler
 *
 * This file contains the handler class for managing file management configuration.
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
 * Handler for file management settings operations.
 *
 * This handler is responsible for managing file processing, vectorization,
 * and text extraction configuration.
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
class FileSettingsHandler
{

    /**
     * Configuration service
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Application name
     *
     * @var string
     */
    private string $appName;

    /**
     * Constructor for FileSettingsHandler
     *
     * @param IConfig $config  Configuration service.
     * @param string  $appName Application name.
     *
     * @return void
     */
    public function __construct(
        IConfig $config,
        string $appName='openregister'
    ) {
        $this->config  = $config;
        $this->appName = $appName;

    }//end __construct()

    /**
     * Get File Management settings only.
     *
     * @return array File management configuration.
     *
     * @throws \RuntimeException If File Management settings retrieval fails.
     */
    public function getFileSettingsOnly(): array
    {
        try {
            $fileConfig = $this->config->getAppValue($this->appName, 'fileManagement', '');

            if (empty($fileConfig) === true) {
                // Return default configuration.
                return [
                    'vectorizationEnabled' => false,
                    'provider'             => null,
                    'chunkingStrategy'     => 'RECURSIVE_CHARACTER',
                    'chunkSize'            => 1000,
                    'chunkOverlap'         => 200,
                // LLPhant-friendly defaults: native PHP support + common library-based formats.
                    'enabledFileTypes'     => ['txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf', 'docx', 'doc', 'xlsx', 'xls'],
                    'ocrEnabled'           => false,
                    'maxFileSizeMB'        => 100,
                // Text extraction settings (for FileConfiguration component).
                    'extractionScope'      => 'objects',
                // None, all, folders, objects.
                    'textExtractor'        => 'llphant',
                // Llphant, dolphin.
                    'extractionMode'       => 'background',
                // Background, immediate, manual.
                    'maxFileSize'          => 100,
                    'batchSize'            => 10,
                    'dolphinApiEndpoint'   => '',
                    'dolphinApiKey'        => '',
                ];
            }//end if

            return json_decode($fileConfig, true);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve File Management settings: '.$e->getMessage());
        }//end try

    }//end getFileSettingsOnly()

    /**
     * Update File Management settings only.
     *
     * @param array $fileData File management configuration data.
     *
     * @return (false|int|mixed|null|string[])[] Updated file management configuration.
     *
     * @throws \RuntimeException If File Management settings update fails.
     *
     * @psalm-return array{vectorizationEnabled: false|mixed, provider: mixed|null, chunkingStrategy: 'RECURSIVE_CHARACTER'|mixed, chunkSize: 1000|mixed, chunkOverlap: 200|mixed, enabledFileTypes: list{'txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf', 'docx', 'doc', 'xlsx', 'xls'}|mixed, ocrEnabled: false|mixed, maxFileSizeMB: 100|mixed, extractionScope: 'objects'|mixed, textExtractor: 'llphant'|mixed, extractionMode: 'background'|mixed, maxFileSize: 100|mixed, batchSize: 10|mixed, dolphinApiEndpoint: ''|mixed, dolphinApiKey: ''|mixed}
     */
    public function updateFileSettingsOnly(array $fileData): array
    {
        try {
            $fileConfig = [
                'vectorizationEnabled' => $fileData['vectorizationEnabled'] ?? false,
                'provider'             => $fileData['provider'] ?? null,
                'chunkingStrategy'     => $fileData['chunkingStrategy'] ?? 'RECURSIVE_CHARACTER',
                'chunkSize'            => $fileData['chunkSize'] ?? 1000,
                'chunkOverlap'         => $fileData['chunkOverlap'] ?? 200,
                'enabledFileTypes'     => $fileData['enabledFileTypes'] ?? ['txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf', 'docx', 'doc', 'xlsx', 'xls'],
                'ocrEnabled'           => $fileData['ocrEnabled'] ?? false,
                'maxFileSizeMB'        => $fileData['maxFileSizeMB'] ?? 100,
            // Text extraction settings (from FileConfiguration component).
                'extractionScope'      => $fileData['extractionScope'] ?? 'objects',
            // None, all, folders, objects.
                'textExtractor'        => $fileData['textExtractor'] ?? 'llphant',
            // Llphant, dolphin.
                'extractionMode'       => $fileData['extractionMode'] ?? 'background',
            // Background, immediate, manual.
                'maxFileSize'          => $fileData['maxFileSize'] ?? 100,
                'batchSize'            => $fileData['batchSize'] ?? 10,
                'dolphinApiEndpoint'   => $fileData['dolphinApiEndpoint'] ?? '',
                'dolphinApiKey'        => $fileData['dolphinApiKey'] ?? '',
            ];

            $this->config->setAppValue($this->appName, 'fileManagement', json_encode($fileConfig));
            return $fileConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update File Management settings: '.$e->getMessage());
        }//end try

    }//end updateFileSettingsOnly()
}//end class
