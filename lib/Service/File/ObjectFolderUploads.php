<?php

/**
 * Store a batch of uploads in one object's folder.
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
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectFolderConflictException;
use OCP\Files\NotFoundException;

/**
 * Each uploaded file goes through ObjectFolderBrowser::upload() on its own,
 * so one refused file does not stop the rest of the batch.
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
 */
class ObjectFolderUploads {

	/**
	 * Constructor.
	 *
	 * @param ObjectFolderBrowser $browser The folder tree of one object.
	 */
	public function __construct(
		private readonly ObjectFolderBrowser $browser,
	) {
	}//end __construct()

	/**
	 * Store a batch of uploaded files in a folder of the object, one by one.
	 *
	 * A file the pipeline refuses, or whose name is taken, is named in
	 * `rejected` and the rest are still stored. A path that is wrong for the
	 * whole batch (it could leave the folder, or is no folder of the object)
	 * is thrown, since no file of the batch can land.
	 *
	 * @param ObjectEntity                                              $object  The object, already known to be changeable.
	 * @param string                                                    $path    The target folder, relative to the object folder.
	 * @param list<array{name: string, tmp_name: string, error: int}> $uploads The uploads, as PHP received them.
	 *
	 * @return array{stored: list<array<string, mixed>>, rejected: list<array{name: string, error: string, conflict: bool}>} The outcome.
	 *
	 * @throws InvalidArgumentException When the path could leave the object folder.
	 * @throws NotFoundException        When the path is not a folder of the object.
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	public function uploadAll(ObjectEntity $object, string $path, array $uploads): array {
		$stored = [];
		$rejected = [];
		foreach ($uploads as $upload) {
			try {
				$stored[] = $this->uploadOne(object: $object, path: $path, upload: $upload);
			} catch (ObjectFolderConflictException $e) {
				$rejected[] = ['name' => $upload['name'], 'error' => $e->getMessage(), 'conflict' => true];
			} catch (InvalidArgumentException $e) {
				if ($e->getCode() === ObjectFolderBrowser::BAD_PATH) {
					throw $e;
				}

				$rejected[] = ['name' => $upload['name'], 'error' => $e->getMessage(), 'conflict' => false];
			} catch (NotFoundException $e) {
				throw $e;
			} catch (Exception $e) {
				$rejected[] = ['name' => $upload['name'], 'error' => $e->getMessage(), 'conflict' => false];
			}//end try
		}//end foreach

		return ['stored' => $stored, 'rejected' => $rejected];
	}//end uploadAll()

	/**
	 * Store one uploaded file, streamed, so its bytes are stored as sent: the
	 * string path of the pipeline guesses at data URIs and base64, a stream
	 * skips that.
	 *
	 * @param ObjectEntity                                    $object The object.
	 * @param string                                          $path   The target folder.
	 * @param array{name: string, tmp_name: string, error: int} $upload The upload.
	 *
	 * @return array<string, mixed> The stored file, as a listing entry.
	 *
	 * @throws Exception When the upload failed or the pipeline refused it.
	 */
	private function uploadOne(ObjectEntity $object, string $path, array $upload): array {
		if ($upload['error'] !== UPLOAD_ERR_OK || is_readable($upload['tmp_name']) === false) {
			throw new Exception('The upload failed');
		}

		$stream = fopen($upload['tmp_name'], 'r');
		if ($stream === false) {
			throw new Exception('The upload failed');
		}

		try {
			return $this->browser->upload(object: $object, path: $path, name: $upload['name'], content: $stream);
		} finally {
			if (is_resource($stream) === true) {
				fclose($stream);
			}
		}
	}//end uploadOne()
}//end class
