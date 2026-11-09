<?php

/**
 * OpenRegister Example Set Files
 *
 * Files an example set attaches to an object it writes. A fragment names them
 * by a path relative to its own `lib/Settings/example-sets/` folder; a path
 * that leaves that folder is refused before anything is written. Files are
 * added with OpenRegister's FileService after the object is written, shared
 * when the fragment asks, and recorded so remove deletes them with the object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, attaches, lists and deletes the files of a set's objects.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
 */
class ExampleSetFiles {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out FileService on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Why the files of an object cannot be attached: each file that is not a
	 * readable file inside the fragment's folder.
	 *
	 * @param string               $dir    The fragment's folder.
	 * @param array<string, mixed> $object The declared object.
	 *
	 * @return array<int, string> Refusals; empty when every file is there.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
	 */
	public function refusals(string $dir, array $object): array {
		$refusals = [];
		foreach ((array) ($object['files'] ?? []) as $file) {
			if ($this->pathOf(dir: $dir, path: (string) $file['path']) === null) {
				$refusals[] = 'file "'.$file['path'].'" is not a readable file inside the fragment\'s folder';
			}
		}

		return $refusals;
	}//end refusals()

	/**
	 * Attach the files of an object to the written object.
	 *
	 * @param string               $dir      The fragment's folder.
	 * @param array<string, mixed> $object   The declared object.
	 * @param string               $objectId The written object's id.
	 *
	 * @return array{files: array<int, array{id: string, name: string, shared: bool}>, errors: array<int, string>}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
	 */
	public function attach(string $dir, array $object, string $objectId): array {
		return $this->attachAll(run: new FileAttachRun(dir: $dir, objectId: $objectId), files: (array) ($object['files'] ?? []));
	}//end attach()

	/**
	 * Attach each declared file of an object to the written object.
	 *
	 * @param FileAttachRun     $run   The fragment's folder and the written object's id.
	 * @param array<int, mixed> $files The object's declared files.
	 *
	 * @return array{files: array<int, array{id: string, name: string, shared: bool}>, errors: array<int, string>}
	 */
	private function attachAll(FileAttachRun $run, array $files): array {
		$result = ['files' => [], 'errors' => []];
		foreach ($files as $file) {
			$attached = $this->attachOne(run: $run, file: $file);
			if (isset($attached['error']) === true) {
				$result['errors'][] = $attached['error'];
				continue;
			}

			$result['files'][] = $attached;
		}

		return $result;
	}//end attachAll()

	/**
	 * Attach one declared file to the object: the file as the record keeps it, or why it was not attached.
	 *
	 * @param FileAttachRun        $run  The fragment's folder and the object's id.
	 * @param array<string, mixed> $file The declared file: `path`, and `share`.
	 *
	 * @return array{id: string, name: string, shared: bool}|array{error: string}
	 */
	private function attachOne(FileAttachRun $run, array $file): array {
		$path = $this->pathOf(dir: $run->dir, path: (string) $file['path']);
		if ($path === null) {
			return ['error' => 'file "'.$file['path'].'" is not there'];
		}

		try {
			return $this->add(run: $run, file: new LocalFile(path: $path, share: (($file['share'] ?? false) === true)));
		} catch (Throwable $e) {
			return ['error' => 'file "'.$file['path'].'": '.$e->getMessage()];
		}
	}//end attach()

	/**
	 * The names of the files an object has.
	 *
	 * @param string $objectId The object's id.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function names(string $objectId): array {
		try {
			$files = $this->fileService()->getFiles(object: $objectId);
		} catch (Throwable) {
			return [];
		}

		$names = [];
		foreach ($files as $file) {
			if (is_object($file) === true && method_exists($file, 'getName') === true) {
				$names[] = (string) $file->getName();
			}
		}

		return $names;
	}//end names()

	/**
	 * Whether a file the set attached is still on the instance.
	 *
	 * Found by its name on the object, or by its id anywhere: an app may move an
	 * object's file into another folder (dossiq homes a document into its case),
	 * and a moved file keeps its id.
	 *
	 * @param array<string, mixed> $file     The record's entry: `id` and `name`.
	 * @param array<int, string>   $names    The names the object's folder holds.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function present(array $file, array $names): bool {
		if (in_array((string) ($file['name'] ?? ''), $names, true) === true) {
			return true;
		}

		try {
			return $this->fileService()->getFileById(fileId: (int) ($file['id'] ?? 0)) !== null;
		} catch (Throwable) {
			return false;
		}
	}//end present()

	/**
	 * Delete one file the set attached; '' when it is gone, otherwise why not.
	 *
	 * The file is found by its id in OpenRegister's own account, where an
	 * object's files live, and that node is deleted. Asking FileService to
	 * delete an id without the object resolves it against an object folder
	 * that cannot be found without the object, which never worked from occ.
	 * A file that cannot be found counts as gone only when the object no
	 * longer has a file of that name.
	 *
	 * @param string $fileId   The file id, as the install record holds it.
	 * @param string $name     The file name, as the install record holds it.
	 * @param string $objectId The id of the object the file was attached to.
	 *
	 * @return string '' when the file is gone; otherwise the reason it stays.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function delete(string $fileId, string $name, string $objectId): string {
		return $this->deleteAttached(file: new AttachedFile(id: $fileId, name: $name), objectId: $objectId);
	}//end delete()

	/**
	 * Delete one file the set attached, found by its id; '' when it is gone, otherwise why not.
	 *
	 * @param AttachedFile $file     The file id and name, as the install record holds them.
	 * @param string       $objectId The id of the object the file was attached to.
	 *
	 * @return string '' when the file is gone; otherwise the reason it stays.
	 */
	private function deleteAttached(AttachedFile $file, string $objectId): string {
		try {
			$found = $this->fileService()->getFileById(fileId: (int) $file->id);
			if ($found === null) {
				return $this->missingRefusal(file: $file, objectId: $objectId);
			}

			if ($this->fileService()->deleteFile(file: $found) === false) {
				return 'OpenRegister did not delete it (see the Nextcloud log)';
			}
		} catch (Throwable $e) {
			return $e->getMessage();
		}

		return '';
	}//end deleteAttached()

	/**
	 * Why a file OpenRegister cannot find counts as not deleted: the object still has a file of that name; '' when
	 * it is gone.
	 *
	 * @param AttachedFile $file     The file id and name the record holds.
	 * @param string       $objectId The object's id.
	 *
	 * @return string
	 */
	private function missingRefusal(AttachedFile $file, string $objectId): string {
		if (in_array($file->name, $this->names(objectId: $objectId), true) === true) {
			return 'OpenRegister cannot find file id '.$file->id.' although the object still has '.$file->name;
		}

		return '';
	}//end missingRefusal()

	/**
	 * Attach one file to the object.
	 *
	 * @param FileAttachRun $run  The object's id.
	 * @param LocalFile     $file The file's real path and whether it is shared publicly.
	 *
	 * @return array{id: string, name: string, shared: bool} The file as the record keeps it.
	 */
	private function add(FileAttachRun $run, LocalFile $file): array {
		$added = $this->fileService()->addFile(
			objectEntity: $run->objectId,
			fileName: basename($file->path),
			content: (string) file_get_contents($file->path),
			share: $file->share
		);

		return ['id' => (string) $added->getId(), 'name' => basename($file->path), 'shared' => $file->share];
	}//end add()

	/**
	 * The real path of a file inside the fragment's folder, or null.
	 *
	 * @param string $dir  The fragment's folder.
	 * @param string $path The declared relative path.
	 *
	 * @return string|null
	 */
	private function pathOf(string $dir, string $path): ?string {
		$root = realpath($dir);
		$real = realpath($dir.'/'.$path);
		if ($root === false || $real === false || str_starts_with($real, $root.'/') === false) {
			return null;
		}

		return $this->readableFile(path: $real);
	}//end pathOf()

	/**
	 * The path when it names a file that can be read, else null.
	 *
	 * @param string $path The real path.
	 *
	 * @return string|null
	 */
	private function readableFile(string $path): ?string {
		if (is_file($path) === false || is_readable($path) === false) {
			return null;
		}

		return $path;
	}//end readableFile()

	/**
	 * OpenRegister's FileService.
	 *
	 * @return ObjectFilesInterface
	 */
	private function fileService(): ObjectFilesInterface {
		return $this->container->get(FileService::class);
	}//end fileService()
}//end class
