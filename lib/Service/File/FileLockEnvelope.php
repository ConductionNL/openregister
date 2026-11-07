<?php

/**
 * The `lock` envelope a file listing shows an authenticated caller.
 *
 * Reads the first Nextcloud lock on a file and maps it to stable string
 * aliases, so the API contract does not leak Nextcloud's numeric constants.
 * Only {@see FileFormattingHandler} asks, and only for an authenticated caller:
 * anonymous requests never reach `ILockManager` (design.md Decision 6).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use Psr\Log\LoggerInterface;

/**
 * Builds the lock envelope of one file.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileLockEnvelope {
	/**
	 * Constructor.
	 *
	 * @param ILockManager    $lockManager Reads the file lock state.
	 * @param LoggerInterface $logger      Records a failed lock lookup.
	 */
	public function __construct(
		private readonly ILockManager $lockManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The lock envelope of a file, or null when no lock provider is available,
	 * the lookup fails, or the file carries no lock.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return array{type: string, scope: string, owner: string, createdAt: string, expiresAt: ?string}|null
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function forFile(int $fileId): ?array {
		$locks = $this->locksOf(fileId: $fileId);
		if ($locks === null || count($locks) === 0) {
			return null;
		}

		return $this->envelope(lock: $locks[0]);
	}//end forFile()

	/**
	 * The locks on a file, or null when they cannot be read.
	 *
	 * A failure to query the lock provider must not break the listing; it is
	 * logged and treated as "no lock info available".
	 *
	 * @param int $fileId The file id.
	 *
	 * @return list<ILock>|null The locks.
	 */
	private function locksOf(int $fileId): ?array {
		if ($this->lockManager->isLockProviderAvailable() === false) {
			return null;
		}

		try {
			return $this->lockManager->getLocks(fileId: $fileId);
		} catch (Exception $lockLookupException) {
			$this->logger->warning(
				message: "[FileFormattingHandler] formatLock: lock lookup failed for fileId {$fileId}: " . $lockLookupException->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId]
			);

			return null;
		}
	}//end locksOf()

	/**
	 * The public fields of one lock.
	 *
	 * @param ILock $lock The lock.
	 *
	 * @return array{type: string, scope: string, owner: string, createdAt: string, expiresAt: ?string}
	 */
	private function envelope(ILock $lock): array {
		$createdAt = $lock->getCreatedAt();

		return [
			'type' => self::typeAlias(type: $lock->getType()),
			'scope' => self::scopeAlias(scope: $lock->getScope()),
			'owner' => $lock->getOwner(),
			'createdAt' => (new DateTime())->setTimestamp($createdAt)->format('c'),
			'expiresAt' => self::expiresAt(createdAt: $createdAt, timeout: $lock->getTimeout()),
		];
	}//end envelope()

	/**
	 * When a lock expires, or null for a lock without timeout.
	 *
	 * @param int $createdAt The creation timestamp.
	 * @param int $timeout   The timeout in seconds; 0 or less for none.
	 *
	 * @return string|null The ISO 8601 expiry.
	 */
	private static function expiresAt(int $createdAt, int $timeout): ?string {
		if ($timeout <= 0) {
			return null;
		}

		return (new DateTime())->setTimestamp($createdAt + $timeout)->format('c');
	}//end expiresAt()

	/**
	 * Map an `ILock::TYPE_*` constant to its public alias.
	 *
	 * @param int $type The lock type.
	 *
	 * @return string `user`, `app`, `token` or `unknown`.
	 */
	private static function typeAlias(int $type): string {
		return match ($type) {
			ILock::TYPE_USER => 'user',
			ILock::TYPE_APP => 'app',
			ILock::TYPE_TOKEN => 'token',
			default => 'unknown',
		};
	}//end typeAlias()

	/**
	 * Map an `ILock::LOCK_*` scope constant to its public alias.
	 *
	 * @param int $scope The lock scope.
	 *
	 * @return string `exclusive`, `shared` or `unknown`.
	 */
	private static function scopeAlias(int $scope): string {
		return match ($scope) {
			ILock::LOCK_EXCLUSIVE => 'exclusive',
			ILock::LOCK_SHARED => 'shared',
			default => 'unknown',
		};
	}//end scopeAlias()
}//end class
