<?php

/**
 * FilesObjectSourceProvider — serves the `nc-file` virtual schema's objects live
 * from the acting user's Nextcloud files (read-only).
 *
 * The authoritative record is the file stored by the Files backend; this provider
 * projects each {@see \OCP\Files\File} in the acting user's home folder as a
 * virtual ObjectEntity (uuid = fileid; object = {id, name, path, mimetype, size,
 * mtime}) and never writes back. Reads are scoped to the acting user's own
 * user-folder via {@see \OCP\Files\IRootFolder::getUserFolder()}, so another
 * user's files are simply absent (denied == not-found, no enumeration oracle).
 * Files is a Nextcloud core app, so the provider is always enabled — it still
 * lives on the `files` register for consistency with the other app-gated
 * projections.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the acting user's files.
 */
class FilesObjectSourceProvider implements ObjectSourceProvider {

	/**
	 * Hard cap on nodes traversed per read, so a huge home folder never runs away.
	 *
	 * @var int
	 */
	private const SCAN_CAP = 10000;

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Nextcloud root folder (per-user home resolution).
	 * @param IUserSession $userSession Acting-user session for read-scoping.
	 * @param LoggerInterface $logger Logger for read failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function getId(): string {
		return 'files-source';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * Files is a Nextcloud core app, so this provider is always available.
	 *
	 * @return bool Always true.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * {@inheritDoc}
	 *
	 * MUST return null when the file is absent OR outside the acting user's home,
	 * so the two cases are indistinguishable (no enumeration oracle).
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param string $id The file id (fileid).
	 * @param array<string, mixed> $config The object-source config block (unused).
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
		$userFolder = $this->userFolder();
		if ($userFolder === null || is_numeric($id) === false) {
			return null;
		}

		try {
			$nodes = $userFolder->getById((int)$id);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:files-source] could not read file: ' . $e->getMessage());
			return null;
		}

		foreach ($nodes as $node) {
			if ($node instanceof File) {
				return $this->toObjectEntity(register: $register, schema: $schema, file: $node, userFolder: $userFolder);
			}
		}

		return null;
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * Honours `limit` and `offset`. Walks the acting user's home folder depth-first
	 * (bounded by {@see self::SCAN_CAP}) and projects each file it encounters.
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param array<string, mixed> $query Query (limit/offset).
	 * @param array<string, mixed> $config The object-source config block (unused).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
		$limit = (int)($query['limit'] ?? 200);
		$offset = (int)($query['offset'] ?? 0);

		$userFolder = $this->userFolder();
		if ($userFolder === null) {
			return [];
		}

		$files = array_slice($this->collectFiles(root: $userFolder), $offset, $limit);
		$objects = [];
		foreach ($files as $file) {
			$objects[] = $this->toObjectEntity(register: $register, schema: $schema, file: $file, userFolder: $userFolder);
		}

		return $objects;
	}//end findAll()

	/**
	 * {@inheritDoc}
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param array<string, mixed> $query Query (filters).
	 * @param array<string, mixed> $config The object-source config block (unused).
	 *
	 * @return int The number of matching virtual objects.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
		return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
	}//end count()

	/**
	 * Resolve the acting user's home folder, or null when there is no session user.
	 *
	 * @return Folder|null The user's home folder, or null.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function userFolder(): ?Folder {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		try {
			return $this->rootFolder->getUserFolder($user->getUID());
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:files-source] could not resolve user folder: ' . $e->getMessage());
			return null;
		}
	}//end userFolder()

	/**
	 * Depth-first collect the File nodes under a folder, bounded by the scan cap.
	 *
	 * @param Folder $root The folder to walk.
	 *
	 * @return array<int, File> The collected files.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function collectFiles(Folder $root): array {
		$files = [];
		$stack = [$root];
		$scanned = 0;

		while ($stack !== [] && $scanned < self::SCAN_CAP) {
			$folder = array_pop($stack);

			try {
				$children = $folder->getDirectoryListing();
			} catch (Throwable $e) {
				$this->logger->warning('[ObjectSource:files-source] could not list folder: ' . $e->getMessage());
				continue;
			}

			foreach ($children as $child) {
				$scanned++;
				if ($child instanceof File) {
					$files[] = $child;
					continue;
				}

				if ($child instanceof Folder) {
					$stack[] = $child;
				}
			}
		}//end while

		return $files;
	}//end collectFiles()

	/**
	 * Map a Nextcloud file onto a non-persisted virtual ObjectEntity.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The sourced schema.
	 * @param File $file The Nextcloud file.
	 * @param Folder $userFolder The acting user's home folder (for relative paths).
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function toObjectEntity(Register $register, Schema $schema, File $file, Folder $userFolder): ObjectEntity {
		$id = (string)$file->getId();
		$path = ($userFolder->getRelativePath($file->getPath()) ?? $file->getPath());

		$data = [
			'id' => $id,
			'name' => $file->getName(),
			'path' => $path,
			'mimetype' => $file->getMimetype(),
			'size' => (int)$file->getSize(),
			'mtime' => $file->getMTime(),
		];

		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setRegister((string)$register->getId());
		$entity->setSchema((string)$schema->getId());
		$entity->setObject($data);

		return $entity;
	}//end toObjectEntity()
}//end class
