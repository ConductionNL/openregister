<?php

/**
 * FileVersioningHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles file versioning operations.
 *
 * This handler provides:
 * - Listing file versions via Nextcloud files_versions
 * - Restoring a specific version
 * - Graceful degradation when files_versions is disabled
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileVersioningHandler
{
    /**
     * Constructor for FileVersioningHandler.
     *
     * @param IRootFolder     $rootFolder  Root folder for file access.
     * @param IAppManager     $appManager  App manager to check if files_versions is enabled.
     * @param IUserSession    $userSession User session for current user context.
     * @param LoggerInterface $logger      Logger for logging operations.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Check if the files_versions app is enabled.
     *
     * @return bool True if files_versions is enabled.
     *
     * @spec openspec/changes/retrofit-content-versioning-2026-04-28/tasks.md#task-1
     */
    public function isVersioningEnabled(): bool
    {
        return $this->appManager->isEnabledForUser('files_versions');
    }//end isVersioningEnabled()

    /**
     * List versions for a file.
     *
     * Returns version metadata as an array. If files_versions is disabled,
     * returns an empty array with a warning.
     *
     * @param File $file The file to list versions for.
     *
     * @return array{versions: array, warning?: string} Version listing.
     *
     * @spec openspec/changes/retrofit-content-versioning-2026-04-28/tasks.md#task-1
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function listVersions(File $file): array
    {
        if ($this->isVersioningEnabled() === false) {
            $this->logger->info(
                message: '[FileVersioningHandler] files_versions app is not enabled',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [
                'versions' => [],
                'warning'  => 'File versioning is not enabled on this instance',
            ];
        }

        try {
            // Get the current version as the first entry.
            $versions   = [];
            $versions[] = [
                'versionId'         => 'current',
                'timestamp'         => (new DateTime())->setTimestamp($file->getMTime())->format('c'),
                'size'              => $file->getSize(),
                'author'            => $this->getCurrentUserId(),
                'authorDisplayName' => $this->getCurrentUserId(),
                'label'             => null,
                'isCurrent'         => true,
            ];

            // Attempt to load version backend if available.
            // Nextcloud's IVersionManager is in OCA\Files_Versions namespace.
            if (class_exists('OCA\Files_Versions\Versions\IVersionManager') === true) {
                $versionManager = \OCP\Server::get('OCA\Files_Versions\Versions\IVersionManager');
                $user           = $this->userSession->getUser();
                if ($versionManager !== null && $user !== null) {
                    $fileVersions = $versionManager->getVersionsForFile($user, $file);
                    foreach ($fileVersions as $version) {
                        $versions[] = [
                            'versionId'         => 'v-'.$version->getTimestamp(),
                            'timestamp'         => (new DateTime())->setTimestamp($version->getTimestamp())->format('c'),
                            'size'              => $version->getSize(),
                            'author'            => $version->getSourceFileName(),
                            'authorDisplayName' => $version->getSourceFileName(),
                            'label'             => method_exists($version, 'getLabel') === true ? $version->getLabel() : null,
                            'isCurrent'         => false,
                        ];
                    }
                }
            }

            return ['versions' => $versions];
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[FileVersioningHandler] Failed to list versions: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [
                'versions' => [],
                'warning'  => 'Failed to retrieve file versions: '.$e->getMessage(),
            ];
        }//end try
    }//end listVersions()

    /**
     * Restore a specific version of a file.
     *
     * @param File   $file      The file to restore a version for.
     * @param string $versionId The version identifier (e.g., "v-1710892800").
     *
     * @return bool True if the version was restored.
     *
     * @throws Exception If versioning is not enabled or version not found.
     *
     * @spec openspec/changes/retrofit-content-versioning-2026-04-28/tasks.md#task-1
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function restoreVersion(File $file, string $versionId): bool
    {
        if ($this->isVersioningEnabled() === false) {
            throw new Exception('File versioning is not enabled on this instance');
        }

        // Parse the timestamp from the version ID.
        $timestamp = (int) str_replace('v-', '', $versionId);
        if ($timestamp <= 0) {
            throw new Exception('Invalid version ID format');
        }

        try {
            if (class_exists('OCA\Files_Versions\Versions\IVersionManager') === true) {
                $versionManager = \OCP\Server::get('OCA\Files_Versions\Versions\IVersionManager');
                $user           = $this->userSession->getUser();
                if ($versionManager !== null && $user !== null) {
                    $fileVersions = $versionManager->getVersionsForFile($user, $file);
                    foreach ($fileVersions as $version) {
                        if ($version->getTimestamp() === $timestamp) {
                            $versionManager->rollback($version);
                            $this->logger->info(
                                message: "[FileVersioningHandler] Restored version {$versionId} for file {$file->getName()}",
                                context: ['file' => __FILE__, 'line' => __LINE__]
                            );
                            return true;
                        }
                    }
                }
            }

            throw new Exception('Version not found');
        } catch (Exception $e) {
            $this->logger->error(
                message: '[FileVersioningHandler] Failed to restore version: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            throw $e;
        }//end try
    }//end restoreVersion()

    /**
     * Get the current user ID.
     *
     * @return string The current user ID or 'system'.
     *
     * @spec openspec/changes/retrofit-content-versioning-2026-04-28/tasks.md#task-1
     */
    private function getCurrentUserId(): string
    {
        $user = $this->userSession->getUser();
        return $user !== null ? $user->getUID() : 'system';
    }//end getCurrentUserId()
}//end class
