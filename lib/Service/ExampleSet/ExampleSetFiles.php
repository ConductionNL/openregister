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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Service\FileService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, attaches, lists and deletes the files of a set's objects.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
	 */
	public function attach(string $dir, array $object, string $objectId): array {
		$result = ['files' => [], 'errors' => []];
		foreach ((array) ($object['files'] ?? []) as $file) {
			$path  = $this->pathOf(dir: $dir, path: (string) $file['path']);
			$share = (($file['share'] ?? false) === true);
			if ($path === null) {
				$result['errors'][] = 'file "'.$file['path'].'" is not there';
				continue;
			}

			try {
				$added = $this->fileService()->addFile(
					objectEntity: $objectId,
					fileName: basename($path),
					content: (string) file_get_contents($path),
					share: $share
				);
			} catch (Throwable $e) {
				$result['errors'][] = 'file "'.$file['path'].'": '.$e->getMessage();
				continue;
			}

			$result['files'][] = ['id' => (string) $added->getId(), 'name' => basename($path), 'shared' => $share];
		}//end foreach

		return $result;
	}//end attach()

	/**
	 * The names of the files an object has.
	 *
	 * @param string $objectId The object's id.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
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
	 * Delete one file by its id; true when it is gone.
	 *
	 * @param string $fileId The file id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function delete(string $fileId): bool {
		try {
			$this->fileService()->deleteFile(file: (int) $fileId);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end delete()

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
		if ($root === false || $real === false || str_starts_with($real, $root.'/') === false || is_file($real) === false || is_readable($real) === false) {
			return null;
		}

		return $real;
	}//end pathOf()

	/**
	 * OpenRegister's FileService.
	 *
	 * @return FileService
	 */
	private function fileService(): FileService {
		return $this->container->get(FileService::class);
	}//end fileService()
}//end class
