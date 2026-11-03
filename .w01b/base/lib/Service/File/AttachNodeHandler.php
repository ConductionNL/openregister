<?php

/**
 * Attach a file the caller already has in Files to a register object.
 *
 * The bytes go through FileService::addFile(), the same validate, write, own
 * and tag pipeline an upload takes, so an attached file is checked, owned,
 * tagged and audited exactly as an uploaded one. Nothing here writes a file
 * any other way.
 *
 * A folder attaches the files directly inside it, at most MAX_FILES, and
 * says how many it skipped: a folder of three thousand scans attached
 * silently in one request would be a timeout half way, with no list of what
 * arrived.
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
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-files-action-attaches-a-file-to-a-register-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * Copies a Files node into an object's files through the upload pipeline.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-files-action-attaches-a-file-to-a-register-object
 */
class AttachNodeHandler {

	/**
	 * The most files one folder attaches in one request.
	 *
	 * @var int
	 */
	public const MAX_FILES = 50;

	/**
	 * Constructor.
	 *
	 * @param FileService $fileService The upload pipeline.
	 */
	public function __construct(
		private readonly FileService $fileService,
	) {
	}//end __construct()

	/**
	 * Attach a file, or the files directly inside a folder, to the object.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Node         $node   The caller's file or folder.
	 *
	 * @return array{attached: File[], skipped: int} The files written to the object, and how many were left out.
	 *
	 * @throws \Exception When the pipeline refuses a file.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-files-action-attaches-a-file-to-a-register-object
	 */
	public function attach(ObjectEntity $object, Node $node): array {
		$files = [];
		$skipped = 0;

		if ($node instanceof File === true) {
			$files[] = $node;
		} elseif ($node instanceof Folder === true) {
			foreach ($node->getDirectoryListing() as $child) {
				if ($child instanceof File === false) {
					continue;
				}

				if (count($files) >= self::MAX_FILES) {
					$skipped++;
					continue;
				}

				$files[] = $child;
			}
		}

		$attached = [];
		foreach ($files as $file) {
			$content = $file->fopen('r');
			if ($content === false) {
				$content = $file->getContent();
			}

			$attached[] = $this->fileService->addFile(
				objectEntity: $object,
				fileName: $file->getName(),
				content: $content
			);
		}

		return ['attached' => $attached, 'skipped' => $skipped];
	}//end attach()
}//end class
