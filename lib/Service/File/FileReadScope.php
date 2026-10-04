<?php

/**
 * FileReadScope: which file-search hits the signed-in caller may read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\File
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Service\FileService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Narrows file-search hits to the files the caller can open in Nextcloud.
 *
 * The chunk store and the vector store hold the extracted TEXT of every indexed
 * file, keyed by the Nextcloud file id, with no notion of who may read it. A
 * search that returns chunk text is therefore a read of the file, and it is
 * answered by the question Nextcloud itself asks: does this file id resolve in
 * the caller's own file tree (owned, shared with them, or in a group folder
 * they are in)? A hit that does not resolve is left out (openregister#4097).
 *
 * Every unknown answers no: no caller, an id that is not a file id, a hit that
 * is not a file, and a lookup that throws all drop the hit. The file-search
 * endpoints serve files only, so an object hit that reaches them is dropped as
 * well rather than served without the object's own read check.
 *
 * @spec openspec/changes/hybrid-document-search/tasks.md
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */
class FileReadScope {

	/**
	 * Wire the file tree and the session.
	 *
	 * @param IRootFolder     $rootFolder  The Nextcloud file tree.
	 * @param IUserSession    $userSession The signed-in caller.
	 * @param LoggerInterface $logger      Logs a lookup that failed, at debug level.
	 * @param FileService|null      $fileService      Finds an object's file in the openregister account's home.
	 * @param ObjectFileAccess|null $objectFileAccess The object read rule for an object's file.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?FileService $fileService = null,
		private readonly ?ObjectFileAccess $objectFileAccess = null,
	) {
	}//end __construct()

	/**
	 * Keep only the file hits the caller may read, in their original order.
	 *
	 * @param array<int, array<string, mixed>> $results Hits carrying `entity_type` and `entity_id`.
	 *
	 * @return array<int, array<string, mixed>> The readable hits.
	 *
	 * @spec openspec/changes/hybrid-document-search/tasks.md
	 */
	public function readableResults(array $results): array {
		$user = $this->userSession->getUser();
		if ($user === null || $results === []) {
			return [];
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FileReadScope] No file tree for the caller, nothing is readable',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}

		$verdicts = [];
		$readable = [];
		foreach ($results as $result) {
			$fileId = $this->fileIdOf(result: $result);
			if ($fileId === null) {
				continue;
			}

			if (array_key_exists($fileId, $verdicts) === false) {
				$verdicts[$fileId] = $this->mayRead(userFolder: $userFolder, fileId: $fileId);
			}

			if ($verdicts[$fileId] === true) {
				$readable[] = $result;
			}
		}

		return $readable;
	}//end readableResults()

	/**
	 * The Nextcloud file id a hit points at, or null when it is not a file hit.
	 *
	 * @param array<string, mixed> $result One hit.
	 *
	 * @return int|null The file id.
	 */
	private function fileIdOf(array $result): ?int {
		if (($result['entity_type'] ?? null) !== 'file') {
			return null;
		}

		$entityId = ($result['entity_id'] ?? null);
		if (is_int($entityId) === true) {
			return $entityId;
		}

		if (is_string($entityId) === true && ctype_digit($entityId) === true) {
			return (int)$entityId;
		}

		return null;
	}//end fileIdOf()

	/**
	 * Whether the file resolves in the caller's own file tree.
	 *
	 * @param Folder $userFolder The caller's file tree.
	 * @param int    $fileId     The file id.
	 *
	 * @return bool True when the caller can open it.
	 */
	private function mayRead(Folder $userFolder, int $fileId): bool {
		try {
			// An object's file is answered by the object's read rule: it sits
			// in the openregister account's home, never in the caller's tree.
			$managed = $this->managedFileVerdict(fileId: $fileId);
			if ($managed !== null) {
				return $managed;
			}

			return $userFolder->getFirstNodeById($fileId) !== null;
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FileReadScope] File lookup failed, treating the file as unreadable',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end mayRead()

	/**
	 * The object rule's verdict on an object's file, or null for any other file.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return bool|null True or false for an object's file, null when the file is not one.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	private function managedFileVerdict(int $fileId): ?bool {
		if ($this->fileService === null || $this->objectFileAccess === null) {
			return null;
		}

		$file = $this->fileService->getFileById(fileId: $fileId);
		if ($file === null || $this->fileService->isManagedFile(node: $file) === false) {
			return null;
		}

		$object = $this->fileService->findObjectForFile(file: $file);
		if ($object === null) {
			return false;
		}

		return $this->objectFileAccess->mayRead(object: $object);
	}//end managedFileVerdict()
}//end class
