<?php

/**
 * FileVersioningHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/content-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;

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
class FileVersioningHandler {
	/**
	 * Constructor for FileVersioningHandler.
	 *
	 * @param IRootFolder $rootFolder Root folder for file access.
	 * @param IAppManager $appManager App manager to check if files_versions is enabled.
	 * @param IUserSession $userSession User session for current user context.
	 * @param LoggerInterface $logger Logger for logging operations.
	 * @param ContainerInterface $container App container the Files_Versions manager is resolved from.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Check if the files_versions app is enabled.
	 *
	 * @return bool True if files_versions is enabled.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function isVersioningEnabled(): bool {
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
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function listVersions(File $file): array {
		if ($this->isVersioningEnabled() === false) {
			return $this->disabledListing();
		}

		try {
			return ['versions' => $this->collectVersions(file: $file)];
		} catch (Exception $e) {
			return $this->failedListing(e: $e);
		}
	}//end listVersions()

	/**
	 * Restore a specific version of a file.
	 *
	 * @param File $file The file to restore a version for.
	 * @param string $versionId The version identifier (e.g., "v-1710892800").
	 *
	 * @return bool True if the version was restored.
	 *
	 * @throws Exception If versioning is not enabled or version not found.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function restoreVersion(File $file, string $versionId): bool {
		if ($this->isVersioningEnabled() === false) {
			throw new Exception('File versioning is not enabled on this instance');
		}

		$timestamp = $this->versionTimestamp(versionId: $versionId);
		try {
			$this->rollbackTo(file: $file, timestamp: $timestamp);
			$this->logRestored(file: $file, versionId: $versionId);
			return true;
		} catch (Exception $e) {
			$this->logRestoreFailure(e: $e);
			throw $e;
		}
	}//end restoreVersion()

	/**
	 * The listing returned when files_versions is disabled, logged at info.
	 *
	 * @return array{versions: array, warning: string}
	 */
	private function disabledListing(): array {
		$this->logger->info(
			message: '[FileVersioningHandler] files_versions app is not enabled',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return ['versions' => [], 'warning' => 'File versioning is not enabled on this instance'];
	}//end disabledListing()

	/**
	 * The listing returned when reading versions failed, logged as a warning.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return array{versions: array, warning: string}
	 */
	private function failedListing(Exception $e): array {
		$this->logger->warning(
			message: '[FileVersioningHandler] Failed to list versions: ' . $e->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return ['versions' => [], 'warning' => 'Failed to retrieve file versions: ' . $e->getMessage()];
	}//end failedListing()

	/**
	 * Log a successful restore at info.
	 *
	 * @param File   $file      The file.
	 * @param string $versionId The version identifier as requested.
	 *
	 * @return void
	 */
	private function logRestored(File $file, string $versionId): void {
		$this->logger->info(
			message: "[FileVersioningHandler] Restored version {$versionId} for file {$file->getName()}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logRestored()

	/**
	 * Log a failed restore as an error.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return void
	 */
	private function logRestoreFailure(Exception $e): void {
		$this->logger->error(
			message: '[FileVersioningHandler] Failed to restore version: ' . $e->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logRestoreFailure()

	/**
	 * The current version first, then the versions the files_versions backend holds.
	 *
	 * @param File $file The file.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function collectVersions(File $file): array {
		$versions = [$this->currentVersionEntry(file: $file)];
		$backend = $this->backendVersions(file: $file);
		foreach (($backend['versions'] ?? []) as $version) {
			$versions[] = $this->versionEntry(version: $version);
		}

		return $versions;
	}//end collectVersions()

	/**
	 * The listing entry for the file as it is now.
	 *
	 * @param File $file The file.
	 *
	 * @return array<string, mixed>
	 */
	private function currentVersionEntry(File $file): array {
		return [
			'versionId' => 'current',
			'timestamp' => $this->isoTime(timestamp: $file->getMTime()),
			'size' => $file->getSize(),
			'author' => $this->getCurrentUserId(),
			'authorDisplayName' => $this->getCurrentUserId(),
			'label' => null,
			'isCurrent' => true,
		];
	}//end currentVersionEntry()

	/**
	 * The listing entry for one stored version.
	 *
	 * @param object $version A files_versions version.
	 *
	 * @return array<string, mixed>
	 */
	private function versionEntry(object $version): array {
		$label = null;
		if (method_exists($version, 'getLabel') === true) {
			$label = $version->getLabel();
		}

		return [
			'versionId' => 'v-' . $version->getTimestamp(),
			'timestamp' => $this->isoTime(timestamp: $version->getTimestamp()),
			'size' => $version->getSize(),
			'author' => $version->getSourceFileName(),
			'authorDisplayName' => $version->getSourceFileName(),
			'label' => $label,
			'isCurrent' => false,
		];
	}//end versionEntry()

	/**
	 * A Unix timestamp as an ISO 8601 date in the default timezone.
	 *
	 * @param int $timestamp The timestamp.
	 *
	 * @return string
	 */
	private function isoTime(int $timestamp): string {
		return (new DateTime())->setTimestamp($timestamp)->format('c');
	}//end isoTime()

	/**
	 * The timestamp a version id names.
	 *
	 * @param string $versionId The version identifier (e.g., "v-1710892800").
	 *
	 * @return int The timestamp.
	 *
	 * @throws Exception When the id does not name a positive timestamp.
	 */
	private function versionTimestamp(string $versionId): int {
		$timestamp = (int)str_replace('v-', '', $versionId);
		if ($timestamp <= 0) {
			throw new Exception('Invalid version ID format');
		}

		return $timestamp;
	}//end versionTimestamp()

	/**
	 * Roll the file back to the first stored version with the given timestamp.
	 *
	 * @param File $file      The file.
	 * @param int  $timestamp The version timestamp.
	 *
	 * @return void
	 *
	 * @throws Exception When no such version is found, or the backend fails.
	 */
	private function rollbackTo(File $file, int $timestamp): void {
		$backend = $this->backendVersions(file: $file);
		$version = $this->versionAt(versions: ($backend['versions'] ?? []), timestamp: $timestamp);
		if ($backend === null || $version === null) {
			throw new Exception('Version not found');
		}

		$backend['manager']->rollback($version);
	}//end rollbackTo()

	/**
	 * The first version with the given timestamp, or null.
	 *
	 * @param iterable<object> $versions  The stored versions.
	 * @param int              $timestamp The timestamp.
	 *
	 * @return object|null
	 */
	private function versionAt(iterable $versions, int $timestamp): ?object {
		foreach ($versions as $version) {
			if ($version->getTimestamp() === $timestamp) {
				return $version;
			}
		}

		return null;
	}//end versionAt()

	/**
	 * The files_versions manager and the file's stored versions, or null when
	 * the manager or a user to ask as is unavailable.
	 *
	 * Nextcloud's IVersionManager is in the OCA\Files_Versions namespace.
	 *
	 * @param File $file The file.
	 *
	 * @return array{manager: object, versions: iterable<object>}|null
	 */
	private function backendVersions(File $file): ?array {
		if (class_exists('OCA\Files_Versions\Versions\IVersionManager') === false) {
			return null;
		}

		$versionManager = $this->container->get('OCA\Files_Versions\Versions\IVersionManager');
		$user = $this->versionUser(file: $file);
		if ($versionManager === null || $user === null) {
			return null;
		}

		return ['manager' => $versionManager, 'versions' => $versionManager->getVersionsForFile($user, $file)];
	}//end backendVersions()

	/**
	 * The account to ask Nextcloud for a file's versions as.
	 *
	 * @param File $file The file.
	 *
	 * @return IUser|null The openregister account for an object's file, else the session user.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	private function versionUser(File $file): ?IUser {
		try {
			$path = $file->getPath();
		} catch (Exception $e) {
			return $this->userSession->getUser();
		}

		// An object's file is owned by the openregister account and sits in
		// its home, where the caller has no mount. The caller has already
		// passed the object rule, so the versions are read as that account.
		if (preg_match('#^/openregister/files/Open Registers/#', $path) === 1) {
			try {
				return $this->container->get(FileOwnershipHandler::class)->getUser();
			} catch (\Throwable $e) {
				return null;
			}
		}

		return $this->userSession->getUser();
	}//end versionUser()

	/**
	 * Get the current user ID.
	 *
	 * @return string The current user ID or 'system'.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function getCurrentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		return 'system';
	}//end getCurrentUserId()
}//end class
