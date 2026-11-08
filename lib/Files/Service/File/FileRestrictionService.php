<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sets, changes and lifts the read restriction of one file, for an app that has decided the caller may.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use DateTimeImmutable;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use InvalidArgumentException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The write side of per-file restrictions. Who may restrict a file is the calling app's rule.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */
class FileRestrictionService {

	/**
	 * The rows.
	 *
	 * @var FileRestrictionStore
	 */
	private readonly FileRestrictionStore $store;

	/**
	 * Forgets its memo after a write.
	 *
	 * @var FileRestrictionGuard
	 */
	private readonly FileRestrictionGuard $guard;

	/**
	 * Who sets it.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Where every change is noted.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Removes the file's public link shares.
	 *
	 * @var FileMapperInterface
	 */
	private readonly FileMapperInterface $fileMapper;

	/**
	 * Constructor.
	 *
	 * @param FileRestrictionRecords $records     The rows and the guard that forgets its memo after a write.
	 * @param IUserSession           $userSession Who sets it.
	 * @param LoggerInterface        $logger      Where every change is noted.
	 * @param FileMapperInterface    $fileMapper  Removes the file's public link shares.
	 */
	public function __construct(
		FileRestrictionRecords $records,
		IUserSession $userSession,
		LoggerInterface $logger,
		FileMapperInterface $fileMapper,
	) {
		$this->store = $records->store;
		$this->guard = $records->guard;
		$this->userSession = $userSession;
		$this->logger = $logger;
		$this->fileMapper = $fileMapper;
	}//end __construct()

	/**
	 * Restrict one file to named users and groups, replacing a restriction it had.
	 *
	 * @param int          $fileId The Nextcloud file id.
	 * @param list<string> $users  The users who may read it.
	 * @param list<string> $groups The groups whose members may read it.
	 * @param string       $reason Why.
	 *
	 * @return array<string, mixed> The stored restriction.
	 *
	 * @throws InvalidArgumentException When the file id, the principals or the reason is missing.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
	 */
	public function restrict(int $fileId, array $users, array $groups, string $reason): array {
		$users = $this->cleanList(values: $users);
		$groups = $this->cleanList(values: $groups);
		$reason = trim($reason);

		if ($fileId <= 0) {
			throw new InvalidArgumentException('A restriction needs a file');
		}

		if ($users === [] && $groups === []) {
			throw new InvalidArgumentException('A restriction needs at least one user or group');
		}

		if ($reason === '') {
			throw new InvalidArgumentException('A restriction needs a reason');
		}

		$setBy = (string)($this->userSession->getUser()?->getUID() ?? '');
		$this->store->save(
			fileId: $fileId,
			restriction: new FileRestriction(users: $users, groups: $groups, reason: $reason, setBy: $setBy),
			setAt: new DateTimeImmutable()
		);
		$this->guard->forget();

		// A public link (`/s/<token>`) is served by Nextcloud itself and never asks the guard, so it goes.
		$revoked = $this->fileMapper->depublishFile(fileId: $fileId);

		$this->logger->info(
			message: '[FileRestrictionService] Restricted a file',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'fileId' => $fileId,
				'setBy' => $setBy,
				'publicLinksRevoked' => ($revoked['deleted_shares'] ?? 0),
			]
		);

		return ($this->store->find(fileId: $fileId) ?? []);
	}//end restrict()

	/**
	 * Lift the restriction of one file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return array<string, mixed>|null The restriction it had, or null when it had none.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
	 */
	public function lift(int $fileId): ?array {
		$previous = $this->store->find(fileId: $fileId);
		if ($previous === null) {
			return null;
		}

		$this->store->delete(fileId: $fileId);
		$this->guard->forget();

		$this->logger->info(
			message: '[FileRestrictionService] Lifted the restriction of a file',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'fileId' => $fileId,
				'by' => (string)($this->userSession->getUser()?->getUID() ?? ''),
			]
		);

		return $previous;
	}//end lift()

	/**
	 * The restriction of one file, or null.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return array<string, mixed>|null The stored restriction.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
	 */
	public function restrictionOf(int $fileId): ?array {
		return $this->store->find(fileId: $fileId);
	}//end restrictionOf()

	/**
	 * A list of principal ids, trimmed, without empties and duplicates.
	 *
	 * @param array<mixed> $values The ids.
	 *
	 * @return list<string> The clean list.
	 */
	private function cleanList(array $values): array {
		$clean = [];
		foreach ($values as $value) {
			if (is_string($value) === false) {
				continue;
			}

			$value = trim($value);
			if ($value !== '' && in_array($value, $clean, true) === false) {
				$clean[] = $value;
			}
		}

		return $clean;
	}//end cleanList()
}//end class
