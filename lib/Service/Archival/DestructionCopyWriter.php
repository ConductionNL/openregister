<?php

/**
 * OpenRegister destruction copy writer
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#requirement-a-destruction-writes-a-restorable-copy-first-req-ipc-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Writes the restorable copy of an object before it is destroyed.
 *
 * The copy is a precondition of the destruction, not a side effect of it
 * (design D-4): an archivaris cannot sign a verklaring for a destruction
 * that happened while its copy failed. So this writes the copy and answers
 * where it lies, or throws, naming the location, and the caller destroys
 * only after it answered.
 *
 * One file per object, `<location>/<list uuid>/<object uuid>.json`, holding
 * the object as the API serialises it plus its register and schema, which is
 * what a restore needs to put it back.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#requirement-a-destruction-writes-a-restorable-copy-first-req-ipc-004
 */
final class DestructionCopyWriter {

	/**
	 * Write the copy of one object and answer its path.
	 *
	 * @param string       $location The configured copy location: an absolute directory outside the application.
	 * @param string       $listUuid The destruction list the object is destroyed under.
	 * @param ObjectEntity $object   The object about to be destroyed.
	 *
	 * @return string The path of the written copy.
	 *
	 * @throws RuntimeException When the copy cannot be written; the message names the location.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#requirement-a-destruction-writes-a-restorable-copy-first-req-ipc-004
	 */
	public function write(string $location, string $listUuid, ObjectEntity $object): string {
		$directory = $this->listDirectory(location: $location, listUuid: $listUuid);
		$path      = $directory . '/' . $this->safeName(name: (string) $object->getUuid()) . '.json';

		$copy = json_encode(
			[
				'uuid'     => $object->getUuid(),
				'register' => $object->getRegister(),
				'schema'   => $object->getSchema(),
				'object'   => $object->jsonSerialize(),
			],
			(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
		);
		if ($copy === false || @file_put_contents($path, $copy, LOCK_EX) !== strlen($copy)) {
			$message = 'The copy of object %s could not be written to the destruction copy location "%s".';
			throw new RuntimeException(sprintf($message, (string) $object->getUuid(), $location));
		}

		return $path;
	}//end write()

	/**
	 * The list's directory under the location, made when absent.
	 *
	 * @param string $location The configured copy location.
	 * @param string $listUuid The destruction list.
	 *
	 * @return string The directory.
	 *
	 * @throws RuntimeException When the location is not an absolute, writable directory.
	 */
	private function listDirectory(string $location, string $listUuid): string {
		$base = rtrim($location, '/');
		if (str_starts_with($location, '/') === false || $base === '' || is_dir($base) === false || is_writable($base) === false) {
			throw new RuntimeException(sprintf('The destruction copy location "%s" is not an absolute directory that can be written to.', $location));
		}

		$directory = $base . '/' . $this->safeName(name: $listUuid);
		if (is_dir($directory) === false && @mkdir($directory, 0750) === false && is_dir($directory) === false) {
			throw new RuntimeException(sprintf('The destruction copy location "%s" refused a directory for list %s.', $location, $listUuid));
		}

		return $directory;
	}//end listDirectory()

	/**
	 * A uuid as a file name: anything but letters, digits, dash and underscore becomes an underscore.
	 *
	 * @param string $name The uuid.
	 *
	 * @return string The file-safe name; `unnamed` when nothing is left.
	 */
	private function safeName(string $name): string {
		$safe = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
		if (trim($safe, '_') === '') {
			return 'unnamed';
		}

		return $safe;
	}//end safeName()
}//end class
