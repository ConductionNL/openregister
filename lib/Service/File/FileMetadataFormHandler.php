<?php

/**
 * OpenRegister file metadata form.
 *
 * Every file on an object, with its name and its description, edited and saved
 * together, with one audit entry per file actually changed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use Throwable;

/**
 * Saves a whole dossier's file metadata in one act.
 *
 * WHY ONE FORM.
 *
 * Tidying a dossier before it goes out means renaming six files. Six round
 * trips through six dialogs is why nobody does it, and a dossier full of
 * `scan0007.pdf` is what a citizen then receives (D-6). One form and one save
 * makes the tidy-up practical.
 *
 * WHY ONE ENTRY PER FILE, NOT ONE PER FORM.
 *
 * An auditor asks what happened to a file, not what happened to a form. A
 * single entry covering six files answers the first question badly and the
 * second one nobody asked. A file the form left alone writes nothing at all:
 * the trail records what changed, and "saved without editing" is not an event.
 */
class FileMetadataFormHandler {

	/**
	 * The audit action a metadata correction is recorded under.
	 *
	 * Distinct from `file.renamed`, which the single-file rename writes. The
	 * two are different acts: one hand-renames a file, the other corrects a
	 * dossier's paperwork in one pass, and a trail that cannot tell them apart
	 * cannot answer either question.
	 *
	 * @var string
	 */
	public const ACTION = 'file.metadata_corrected';

	/**
	 * Constructor.
	 *
	 * @param FileService $fileService The file layer this handler edits through.
	 */
	public function __construct(private readonly FileService $fileService) {
	}//end __construct()

	/**
	 * Save the form.
	 *
	 * Every entry names a `fileId` and may carry a `name`, a `description` or
	 * both. A key that is absent is left alone; an empty description clears
	 * it, which is the distinction `updateFileMetadata` already draws and this
	 * form preserves rather than flattening with a truthiness test.
	 *
	 * One file failing does not roll back the others. Renaming can collide
	 * with a name already in the folder, and refusing the whole dossier
	 * because one file clashed would mean the person fixes one name and loses
	 * five. Each entry reports its own outcome.
	 *
	 * @param ObjectEntity $object The object whose folder the files live in.
	 * @param array $entries The form's rows.
	 *
	 * @return array{changed: array<int, array>, unchanged: array<int, int>, failed: array<int, array>}
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function save(ObjectEntity $object, array $entries): array {
		$changed = [];
		$unchanged = [];
		$failed = [];

		foreach ($entries as $entry) {
			if (is_array($entry) === false || is_numeric($entry['fileId'] ?? null) === false) {
				$failed[] = ['fileId' => null, 'error' => "Every row needs a 'fileId'."];
				continue;
			}

			$fileId = (int)$entry['fileId'];

			try {
				$outcome = $this->saveOne(object: $object, fileId: $fileId, entry: $entry);
			} catch (Throwable $e) {
				$failed[] = ['fileId' => $fileId, 'error' => $e->getMessage()];
				continue;
			}

			if ($outcome === null) {
				$unchanged[] = $fileId;
				continue;
			}

			$changed[] = $outcome;
		}//end foreach

		return [
			'changed' => $changed,
			'unchanged' => $unchanged,
			'failed' => $failed,
		];
	}//end save()

	/**
	 * Apply one row, and record it only if it changed something.
	 *
	 * Returns null when the row asked for nothing the file did not already
	 * say, which is the signal that no audit entry belongs to it.
	 *
	 * @param ObjectEntity $object The object the file belongs to.
	 * @param integer $fileId The file.
	 * @param array $entry The row.
	 *
	 * @return array|null What changed, or null when nothing did.
	 *
	 * @throws Exception When the file cannot be found or the rename is refused.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	private function saveOne(ObjectEntity $object, int $fileId, array $entry): ?array {
		$node = $this->fileService->getFile(object: $object, file: (string)$fileId);
		if ($node === null) {
			throw new Exception(message: "File $fileId is not on this object.");
		}

		$current = $this->fileService->formatFile($node);
		$currentName = (string)($current['name'] ?? '');
		$currentDescription = ($current['description'] ?? null);

		$diff = [];

		$name = $this->requested(entry: $entry, key: 'name');
		if ($name !== null && $name !== $currentName && $name !== '') {
			$this->fileService->renameFile(object: $object, fileId: $fileId, newName: $name);
			$diff['name'] = ['old' => $currentName, 'new' => $name];
		}

		$description = $this->requested(entry: $entry, key: 'description');
		if ($description !== null && $description !== (string)$currentDescription) {
			$this->fileService->updateFileMetadata(fileId: $fileId, description: $description);
			$diff['description'] = ['old' => $currentDescription, 'new' => $description];
		}

		if ($diff === []) {
			return null;
		}

		$this->fileService->getAuditHandler()->logFileAction(
			object: $object,
			fileId: $fileId,
			action: self::ACTION,
			data: $diff
		);

		return array_merge(['fileId' => $fileId], $diff);
	}//end saveOne()

	/**
	 * The value a row asks for, or null when the row does not mention the key.
	 *
	 * Presence, not truthiness: an empty description is a request to clear it
	 * and an absent one is a request to leave it alone. Collapsing the two
	 * with `??` would make a description impossible to remove.
	 *
	 * @param array $entry The row.
	 * @param string $key The key to read.
	 *
	 * @return string|null The requested value, or null when absent.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	private function requested(array $entry, string $key): ?string {
		if (array_key_exists($key, $entry) === false || is_scalar($entry[$key]) === false) {
			return null;
		}

		return trim((string)$entry[$key]);
	}//end requested()
}//end class
