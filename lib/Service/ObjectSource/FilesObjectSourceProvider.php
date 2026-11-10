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

use OCA\OpenRegister\Service\LinkSessionRuntime;
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

	use CountsThroughFindAll;

	/**
	 * Hard cap on nodes traversed per read, so a huge home folder never runs away.
	 *
	 * @var int
	 */
	private const SCAN_CAP = 10000;

	/**
	 * The acting-user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Logger for read failures.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRootFolder        $rootFolder Nextcloud root folder (per-user home resolution).
	 * @param LinkSessionRuntime $session    The acting-user session and the logger for read failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		LinkSessionRuntime $session,
	) {
		$this->userSession = $session->userSession;
		$this->logger = $session->logger;
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
		return $this->findFile(binding: new SourcedObjectBinding(register: $register, schema: $schema), id: $id);
	}//end find()

	/**
	 * Read one object for the bound register and schema.
	 *
	 * @param SourcedObjectBinding $binding The register and schema the objects belong to.
	 * @param string               $id      The object id.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 */
	private function findFile(SourcedObjectBinding $binding, string $id): ?ObjectEntity {
		$userFolder = $this->userFolder();
		if ($userFolder === null || is_numeric($id) === false) {
			return null;
		}

		$file = $this->firstFile(nodes: $this->nodesById(userFolder: $userFolder, id: (int)$id));
		if ($file === null) {
			return null;
		}

		return $this->fileEntity(binding: $binding, data: $this->fileData(file: $file, userFolder: $userFolder));
	}//end findFile()

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
		return $this->listFiles(binding: new SourcedObjectBinding(register: $register, schema: $schema), query: $query);
	}//end findAll()

	/**
	 * Read one page of objects for the bound register and schema.
	 *
	 * @param SourcedObjectBinding $binding The register and schema the objects belong to.
	 * @param array<string, mixed> $query   Query (filters/search/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 */
	private function listFiles(SourcedObjectBinding $binding, array $query): array {
		$limit = (int)($query['limit'] ?? 200);
		$offset = (int)($query['offset'] ?? 0);

		$userFolder = $this->userFolder();
		if ($userFolder === null) {
			return [];
		}

		return array_map(
			fn (File $file): ObjectEntity => $this->fileEntity(binding: $binding, data: $this->fileData(file: $file, userFolder: $userFolder)),
			array_slice($this->collectFiles(root: $userFolder), $offset, $limit)
		);
	}//end listFiles()

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
		$scan = new FilesScan(root: $root, cap: self::SCAN_CAP);
		for ($folder = $scan->next(); $folder !== null; $folder = $scan->next()) {
			$scan->visit(children: $this->listChildren(folder: $folder));
		}

		return $scan->files();
	}//end collectFiles()

	/**
	 * A folder's children, failing closed to none.
	 *
	 * @param Folder $folder The folder.
	 *
	 * @return mixed The children (whatever getDirectoryListing() returns), or [].
	 */
	private function listChildren(Folder $folder): mixed {
		try {
			return $folder->getDirectoryListing();
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:files-source] could not list folder: ' . $e->getMessage());
			return [];
		}
	}//end listChildren()

	/**
	 * The nodes with a file id in the user's folder, failing closed to none.
	 *
	 * @param Folder $userFolder The acting user's home folder.
	 * @param int $id The file id.
	 *
	 * @return mixed The nodes (whatever getById() returns), or [].
	 */
	private function nodesById(Folder $userFolder, int $id): mixed {
		try {
			return $userFolder->getById($id);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:files-source] could not read file: ' . $e->getMessage());
			return [];
		}
	}//end nodesById()

	/**
	 * The first file among some nodes.
	 *
	 * @param mixed $nodes The nodes.
	 *
	 * @return File|null The file, or null when none is a file.
	 */
	private function firstFile(mixed $nodes): ?File {
		foreach ($nodes as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}//end firstFile()

	/**
	 * A virtual object keyed by its file id.
	 *
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param array<string, mixed> $data The file's object data.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 */
	private function fileEntity(SourcedObjectBinding $binding, array $data): ObjectEntity {
		return $binding->toEntity(uuid: $data['id'], data: $data);
	}//end fileEntity()

	/**
	 * The object data of a file, with its path relative to the user's folder
	 * (or its absolute path when it has none).
	 *
	 * @param File $file The file node.
	 * @param Folder $userFolder The acting user's home folder.
	 *
	 * @return array<string, mixed> The object data; `id` is the file id.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function fileData(File $file, Folder $userFolder): array {
		$id = (string)$file->getId();
		$path = ($userFolder->getRelativePath($file->getPath()) ?? $file->getPath());

		return [
			'id' => $id,
			'name' => $file->getName(),
			'path' => $path,
			'mimetype' => $file->getMimetype(),
			'size' => (int)$file->getSize(),
			'mtime' => $file->getMTime(),
		];
	}//end fileData()
}//end class
