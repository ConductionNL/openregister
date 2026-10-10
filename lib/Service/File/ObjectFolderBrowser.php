<?php

/**
 * Browse and change one object's folder, its subfolders included.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-listing-an-objects-folder-and-its-subfolders-follows-the-objects-read-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectFolderConflictException;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

/**
 * The object folder as a small tree the files browser can walk.
 *
 * This class never decides who may do what: the caller has already checked the
 * object (read for a listing, update for a change) through
 * {@see ObjectFileAccess}. What it does guarantee is that nothing it touches
 * lies outside the object's folder. A path is relative to that folder and is
 * refused when a segment is empty, `.` or `..`; a node id is looked up inside
 * that folder only; and the folder itself is never renamed or deleted here.
 *
 * Files keep going through FileService where it already has the rule (an
 * upload's executable block and object tag, a file rename's or delete's lock
 * check), so a file in a subfolder is treated exactly like one at the top.
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-listing-an-objects-folder-and-its-subfolders-follows-the-objects-read-rule
 */
class ObjectFolderBrowser {

	/**
	 * Characters a single name may not hold: the set FileService::renameFile()
	 * refuses, so a name accepted here is never refused one call later.
	 *
	 * @var list<string>
	 */
	private const FORBIDDEN_IN_NAME = ['/', '\\', ':', '*', '?', '"', '<', '>', '|', "\0"];

	/**
	 * Code of the InvalidArgumentException for a path that could leave the object folder.
	 */
	public const BAD_PATH = 1;

	/**
	 * Code of the InvalidArgumentException for a name that cannot be used.
	 */
	public const BAD_NAME = 2;

	/**
	 * Code of the ObjectFolderConflictException for a name already taken.
	 */
	public const TAKEN = 1;

	/**
	 * Code of the ObjectFolderConflictException for a file below a folder locked by someone else.
	 */
	public const LOCKED = 2;

	/**
	 * Constructor.
	 *
	 * @param FileService $fileService Resolves the object folder and runs the file pipeline.
	 */
	public function __construct(
		private readonly FileService $fileService,
	) {
	}//end __construct()

	/**
	 * List one folder of the object: its root, or a subfolder by relative path.
	 *
	 * @param ObjectEntity $object The object, already known to be readable.
	 * @param string       $path   The folder, relative to the object folder; empty for its root.
	 *
	 * @return array{path: string, folderId: int, entries: list<array<string, mixed>>} The listing.
	 *
	 * @throws InvalidArgumentException When the path could leave the object folder.
	 * @throws NotFoundException        When the object has no folder or the path is not a folder of it.
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-listing-an-objects-folder-and-its-subfolders-follows-the-objects-read-rule
	 */
	public function listFolder(ObjectEntity $object, string $path = ''): array {
		$root = $this->objectFolder(object: $object);
		$folder = $this->folderAt(root: $root, path: $path);

		$entries = [];
		foreach ($folder->getDirectoryListing() as $node) {
			$entries[] = $this->describe(root: $root, node: $node);
		}

		return [
			'path' => $this->relativePath(root: $root, node: $folder),
			'folderId' => (int)$folder->getId(),
			'entries' => $entries,
		];
	}//end listFolder()

	/**
	 * Create a folder inside the object folder.
	 *
	 * @param ObjectEntity $object The object, already known to be changeable.
	 * @param string       $path   Where, relative to the object folder.
	 * @param string       $name   The new folder's name: one segment.
	 *
	 * @return array<string, mixed> The new folder, described like a listing entry.
	 *
	 * @throws InvalidArgumentException      When the path or the name is not acceptable.
	 * @throws NotFoundException             When the path is not a folder of the object.
	 * @throws ObjectFolderConflictException When the name is taken.
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	public function createFolder(ObjectEntity $object, string $path, string $name): array {
		$root = $this->objectFolder(object: $object);
		$parent = $this->folderAt(root: $root, path: $path);
		$name = $this->validName(name: $name);
		$this->assertFree(folder: $parent, name: $name);

		return $this->describe(root: $root, node: $parent->newFolder($name));
	}//end createFolder()

	/**
	 * Store an uploaded file in a folder of the object, through the upload pipeline.
	 *
	 * @param ObjectEntity  $object  The object, already known to be changeable.
	 * @param string        $path    The target folder, relative to the object folder.
	 * @param string        $name    The file's name: one segment.
	 * @param resource|string $content The file's bytes, or a readable stream.
	 *
	 * @return array<string, mixed> The stored file, described like a listing entry.
	 *
	 * @throws InvalidArgumentException      When the path or the name is not acceptable.
	 * @throws NotFoundException             When the path is not a folder of the object.
	 * @throws ObjectFolderConflictException When the name is taken.
	 * @throws Exception                     When the pipeline refuses the file (an executable, a write failure).
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	public function upload(ObjectEntity $object, string $path, string $name, mixed $content): array {
		$root = $this->objectFolder(object: $object);
		$target = $this->folderAt(root: $root, path: $path);
		$name = $this->validName(name: $name);
		$this->assertFree(folder: $target, name: $name);

		// The pipeline writes relative to the object folder, so a file in a
		// subfolder is named by its path from there.
		$relative = $this->relativePath(root: $root, node: $target);
		$fileName = $name;
		if ($relative !== '') {
			$fileName = $relative . '/' . $name;
		}

		$file = $this->fileService->addFile(objectEntity: $object, fileName: $fileName, content: $content);

		return $this->describe(root: $root, node: $file);
	}//end upload()

	/**
	 * Rename a file or folder inside the object folder, in place.
	 *
	 * @param ObjectEntity $object The object, already known to be changeable.
	 * @param int          $nodeId The node.
	 * @param string       $name   The new name: one segment.
	 *
	 * @return array<string, mixed> The renamed node, described like a listing entry.
	 *
	 * @throws InvalidArgumentException      When the name is not acceptable.
	 * @throws NotFoundException             When the node is not inside the object folder.
	 * @throws ObjectFolderConflictException When the name is taken or a file below a folder is locked by someone else.
	 * @throws Exception                     When the rename fails.
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	public function rename(ObjectEntity $object, int $nodeId, string $name): array {
		$root = $this->objectFolder(object: $object);
		$node = $this->nodeIn(root: $root, nodeId: $nodeId);
		$name = $this->validName(name: $name);
		if ($name === $node->getName()) {
			return $this->describe(root: $root, node: $node);
		}

		$this->assertFree(folder: $node->getParent(), name: $name);

		if ($node instanceof File === true) {
			// A file keeps FileService's rename: its lock check and name rules.
			$renamed = $this->fileService->renameFile(object: $object, fileId: (int)$node->getId(), newName: $name);

			return $this->describe(root: $root, node: $renamed);
		}

		$this->assertNothingLockedBelow(node: $node);
		$moved = $node->move($node->getParent()->getPath() . '/' . $name);

		return $this->describe(root: $root, node: $moved);
	}//end rename()

	/**
	 * Delete a file or folder inside the object folder.
	 *
	 * @param ObjectEntity $object The object, already known to be changeable.
	 * @param int          $nodeId The node.
	 *
	 * @return void
	 *
	 * @throws NotFoundException             When the node is not inside the object folder.
	 * @throws ObjectFolderConflictException When a file below a folder is locked by someone else.
	 * @throws Exception                     When the delete fails.
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	public function delete(ObjectEntity $object, int $nodeId): void {
		$root = $this->objectFolder(object: $object);
		$node = $this->nodeIn(root: $root, nodeId: $nodeId);

		if ($node instanceof File === true) {
			// A file keeps FileService's delete: its lock and permission checks.
			if ($this->fileService->deleteFile(file: $node, object: $object) === false) {
				throw new Exception('The file could not be deleted');
			}

			return;
		}

		$this->assertNothingLockedBelow(node: $node);
		$node->delete();
	}//end delete()

	/**
	 * The object's folder.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Folder The folder.
	 *
	 * @throws NotFoundException When the object has no folder.
	 */
	private function objectFolder(ObjectEntity $object): Folder {
		try {
			$folder = $this->fileService->getObjectFolder(objectEntity: $object);
		} catch (Exception $e) {
			throw new NotFoundException('The object has no folder');
		}

		if ($folder instanceof Folder === false) {
			throw new NotFoundException('The object has no folder');
		}

		return $folder;
	}//end objectFolder()

	/**
	 * The folder at a path relative to the object folder.
	 *
	 * @param Folder $root The object folder.
	 * @param string $path The relative path; empty for the root.
	 *
	 * @return Folder The folder.
	 *
	 * @throws InvalidArgumentException When a segment could leave the object folder.
	 * @throws NotFoundException        When the path is not a folder.
	 */
	private function folderAt(Folder $root, string $path): Folder {
		$segments = $this->segments(path: $path);
		if ($segments === []) {
			return $root;
		}

		$node = $root->get(implode('/', $segments));
		if ($node instanceof Folder === false) {
			throw new NotFoundException('Not a folder');
		}

		return $node;
	}//end folderAt()

	/**
	 * Split a relative path into safe segments.
	 *
	 * Leading and trailing slashes are dropped; anything else that is not a
	 * plain name is refused, before any lookup.
	 *
	 * @param string $path The relative path.
	 *
	 * @return list<string> The segments, outermost first.
	 *
	 * @throws InvalidArgumentException When a segment is empty, `.`, `..`, or holds a backslash or NUL.
	 */
	private function segments(string $path): array {
		$trimmed = trim($path, '/');
		if ($trimmed === '') {
			return [];
		}

		$segments = explode('/', $trimmed);
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..'
				|| str_contains($segment, '\\') === true || str_contains($segment, "\0") === true
			) {
				throw new InvalidArgumentException(message: 'The path is not a folder of this object', code: self::BAD_PATH);
			}
		}

		return $segments;
	}//end segments()

	/**
	 * A single name, trimmed, or a refusal.
	 *
	 * @param string $name The name.
	 *
	 * @return string The trimmed name.
	 *
	 * @throws InvalidArgumentException When it is empty, `.`, `..`, or holds a character of FORBIDDEN_IN_NAME.
	 */
	private function validName(string $name): string {
		$name = trim($name);
		if ($name === '' || $name === '.' || $name === '..') {
			throw new InvalidArgumentException(message: 'A name is required', code: self::BAD_NAME);
		}

		foreach (self::FORBIDDEN_IN_NAME as $character) {
			if (str_contains($name, $character) === true) {
				throw new InvalidArgumentException(message: 'A name cannot contain / \\ : * ? " < > |', code: self::BAD_NAME);
			}
		}

		return $name;
	}//end validName()

	/**
	 * Refuse a name that is already taken in a folder.
	 *
	 * @param Folder $folder The folder.
	 * @param string $name   The name.
	 *
	 * @return void
	 *
	 * @throws ObjectFolderConflictException When it is taken.
	 */
	private function assertFree(Folder $folder, string $name): void {
		if ($folder->nodeExists($name) === true) {
			throw new ObjectFolderConflictException(
				message: 'A file or folder with that name is already here',
				code: self::TAKEN
			);
		}
	}//end assertFree()

	/**
	 * A node inside the object folder, never the folder itself.
	 *
	 * @param Folder $root   The object folder.
	 * @param int    $nodeId The node id.
	 *
	 * @return Node The node.
	 *
	 * @throws NotFoundException When the id is not inside the object folder, or is the folder.
	 */
	private function nodeIn(Folder $root, int $nodeId): Node {
		if ($nodeId <= 0 || $nodeId === (int)$root->getId()) {
			throw new NotFoundException('Not found in this object');
		}

		$node = $root->getFirstNodeById($nodeId);
		if ($node === null || str_starts_with($node->getPath(), rtrim($root->getPath(), '/') . '/') === false) {
			throw new NotFoundException('Not found in this object');
		}

		return $node;
	}//end nodeIn()

	/**
	 * Refuse a folder change when someone else holds a lock on a file below it.
	 *
	 * @param Node $node The folder (or, walking down, anything below it).
	 *
	 * @return void
	 *
	 * @throws ObjectFolderConflictException When a file below is locked by someone else.
	 */
	private function assertNothingLockedBelow(Node $node): void {
		if ($node instanceof Folder === true) {
			foreach ($node->getDirectoryListing() as $child) {
				$this->assertNothingLockedBelow(node: $child);
			}

			return;
		}

		try {
			$this->fileService->getLockHandler()->assertCanModify((int)$node->getId());
		} catch (Exception $e) {
			throw new ObjectFolderConflictException(
				message: 'A file in this folder is locked: ' . $node->getName(),
				code: self::LOCKED
			);
		}
	}//end assertNothingLockedBelow()

	/**
	 * A node's path relative to the object folder; empty for the folder itself.
	 *
	 * @param Folder $root The object folder.
	 * @param Node   $node The node.
	 *
	 * @return string The relative path.
	 */
	private function relativePath(Folder $root, Node $node): string {
		$rootPath = rtrim($root->getPath(), '/');
		$nodePath = $node->getPath();
		if ($nodePath === $rootPath) {
			return '';
		}

		return ltrim(substr($nodePath, strlen($rootPath)), '/');
	}//end relativePath()

	/**
	 * One listing entry.
	 *
	 * @param Folder $root The object folder.
	 * @param Node   $node The node.
	 *
	 * @return array{id: int, name: string, type: string, mimetype: string, size: int, mtime: int, path: string} The entry.
	 */
	private function describe(Folder $root, Node $node): array {
		$type = 'file';
		if ($node instanceof Folder === true) {
			$type = 'folder';
		}

		return [
			'id' => (int)$node->getId(),
			'name' => $node->getName(),
			'type' => $type,
			'mimetype' => (string)$node->getMimetype(),
			'size' => (int)$node->getSize(),
			'mtime' => (int)$node->getMTime(),
			'path' => $this->relativePath(root: $root, node: $node),
		];
	}//end describe()
}//end class
