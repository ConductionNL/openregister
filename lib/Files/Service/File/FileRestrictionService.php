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
	 * Constructor.
	 *
	 * @param FileRestrictionRecords $records     The rows and the guard that forgets its memo after a write.
	 * @param IUserSession           $userSession Who sets it.
	 * @param LoggerInterface        $logger      Where every change is noted.
	 * @param FileMapperInterface    $fileMapper  Removes the file's public link shares.
	 */
	public function __construct(
		private readonly FileRestrictionRecords $records,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly FileMapperInterface $fileMapper,
	) {
	}//end __construct()

	/**
	 * Restrict one file to named users and groups, replacing a restriction it had.
	 *
	 * The principals are trimmed and deduplicated and the reason trimmed; every
	 * refusal happens before anything is stored.
	 *
	 * @param FileRestrictionRequest $request The file, the users and groups who may read it, and why.
	 *
	 * @return array<string, mixed> The stored restriction.
	 *
	 * @throws InvalidArgumentException When the file id, the principals or the reason is missing.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
	 */
	public function restrict(FileRestrictionRequest $request): array {
		$restriction = $this->validRestriction(request: $request);
		$this->applyRestriction(fileId: $request->fileId, restriction: $restriction);

		return ($this->records->store->find(fileId: $request->fileId) ?? []);
	}//end restrict()

	/**
	 * The cleaned restriction a request asks for, signed by the caller; refused before anything is stored.
	 *
	 * @param FileRestrictionRequest $request The file, the users and groups who may read it, and why.
	 *
	 * @return FileRestriction
	 *
	 * @throws InvalidArgumentException When the file id, the principals or the reason is missing.
	 */
	private function validRestriction(FileRestrictionRequest $request): FileRestriction {
		$users = $this->cleanList(values: $request->users);
		$groups = $this->cleanList(values: $request->groups);
		$reason = trim($request->reason);

		if ($request->fileId <= 0) {
			throw new InvalidArgumentException('A restriction needs a file');
		}

		if ($users === [] && $groups === []) {
			throw new InvalidArgumentException('A restriction needs at least one user or group');
		}

		if ($reason === '') {
			throw new InvalidArgumentException('A restriction needs a reason');
		}

		return new FileRestriction(users: $users, groups: $groups, reason: $reason, setBy: $this->callerUid());
	}//end validRestriction()

	/**
	 * Store the restriction, make the guard forget its verdicts and revoke the file's public links.
	 *
	 * @param int             $fileId      The Nextcloud file id.
	 * @param FileRestriction $restriction The restriction to store.
	 *
	 * @return void
	 */
	private function applyRestriction(int $fileId, FileRestriction $restriction): void {
		$this->records->store->save(
			fileId: $fileId,
			restriction: $restriction,
			setAt: new DateTimeImmutable()
		);
		$this->records->guard->forget();

		$this->revokePublicLinks(fileId: $fileId, restriction: $restriction);
	}//end applyRestriction()

	/**
	 * Revoke the file's public links, and log the restriction with how many went.
	 *
	 * A public link (`/s/<token>`) is served by Nextcloud itself and never asks the guard, so it goes.
	 *
	 * @param int             $fileId      The Nextcloud file id.
	 * @param FileRestriction $restriction The stored restriction.
	 *
	 * @return void
	 */
	private function revokePublicLinks(int $fileId, FileRestriction $restriction): void {
		$revoked = $this->fileMapper->depublishFile(fileId: $fileId);

		$this->logger->info(
			message: '[FileRestrictionService] Restricted a file',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'fileId' => $fileId,
				'setBy' => $restriction->setBy,
				'publicLinksRevoked' => ($revoked['deleted_shares'] ?? 0),
			]
		);
	}//end revokePublicLinks()

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
		$previous = $this->records->store->find(fileId: $fileId);
		if ($previous === null) {
			return null;
		}

		$this->records->store->delete(fileId: $fileId);
		$this->records->guard->forget();

		$this->logLifted(fileId: $fileId);

		return $previous;
	}//end lift()

	/**
	 * Log that a file's restriction was lifted, and by whom.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return void
	 */
	private function logLifted(int $fileId): void {
		$this->logger->info(
			message: '[FileRestrictionService] Lifted the restriction of a file',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'fileId' => $fileId,
				'by' => $this->callerUid(),
			]
		);
	}//end logLifted()

	/**
	 * The signed-in caller's uid; empty when anonymous.
	 *
	 * @return string
	 */
	private function callerUid(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end callerUid()

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
		return $this->records->store->find(fileId: $fileId);
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
