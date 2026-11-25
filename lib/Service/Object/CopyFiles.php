<?php

/**
 * The files a copy takes along, copied after the copy is committed.
 *
 * Nextcloud's file system is not transactional, so the files are copied once
 * the copy and its links are committed (design D-2), each through the file
 * service's own copy, and each reported `copied` or `failed` with a reason.
 * A failed file does not undo the copy: the object and its links stand, and
 * the report says which file is missing.
 *
 * The source is never changed: each file is copied to the copy's folder.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectFileCopierInterface;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies an object's attached files onto its copy, one outcome per file.
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */
class CopyFiles implements ObjectFileCopierInterface {

	/**
	 * The outcome of a file copied.
	 */
	public const COPIED = 'copied';

	/**
	 * The outcome of a file that could not be copied.
	 */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param FileService     $files  Lists the source's files and copies one to another object.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly FileService $files,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Copy every file attached to the source onto the copy.
	 *
	 * Only stored objects (Db ObjectEntity) carry files; any other implementation has none to copy.
	 *
	 * @param ObjectEntityInterface $source The object copied.
	 * @param ObjectEntityInterface $copy   The new object.
	 *
	 * @return list<array{id: string, title: string, outcome: string, reason: string|null}> One outcome per file, in the source's order.
	 *
	 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
	 */
	public function copy(ObjectEntityInterface $source, ObjectEntityInterface $copy): array {
		if ($source instanceof ObjectEntity === false || $copy instanceof ObjectEntity === false) {
			return [];
		}

		try {
			$nodes = $this->files->getFiles(object: $source);
		} catch (Throwable $e) {
			// An object that never had a file has no folder to list.
			$this->logger->info(
				message: '[CopyFiles] The source has no files to copy',
				context: ['file' => __FILE__, 'line' => __LINE__, 'source' => $source->getUuid(), 'exception' => $e->getMessage()]
			);
			return [];
		}

		$outcomes = [];
		foreach ($nodes as $node) {
			if ($node instanceof File === false) {
				continue;
			}

			$outcomes[] = $this->copyOne(source: $source, copy: $copy, file: $node);
		}

		return $outcomes;
	}//end copy()

	/**
	 * Copy one file and report how it went.
	 *
	 * @param ObjectEntity $source The object copied.
	 * @param ObjectEntity $copy   The new object.
	 * @param File         $file   The source's file.
	 *
	 * @return array{id: string, title: string, outcome: string, reason: string|null}
	 */
	private function copyOne(ObjectEntity $source, ObjectEntity $copy, File $file): array {
		$id    = (string) $file->getId();
		$title = $file->getName();
		try {
			$this->files->copyFile(sourceObject: $source, fileId: (int) $file->getId(), targetObject: $copy);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[CopyFiles] A file was not copied onto the copy',
				context: [
					'file'      => __FILE__,
					'line'      => __LINE__,
					'source'    => $source->getUuid(),
					'copy'      => $copy->getUuid(),
					'fileId'    => $id,
					'exception' => $e->getMessage(),
				]
			);
			return ['id' => $id, 'title' => $title, 'outcome' => self::FAILED, 'reason' => 'The file could not be copied.'];
		}

		return ['id' => $id, 'title' => $title, 'outcome' => self::COPIED, 'reason' => null];
	}//end copyOne()
}//end class
