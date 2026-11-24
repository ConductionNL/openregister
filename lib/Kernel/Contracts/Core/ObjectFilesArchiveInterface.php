<?php
/**
 * ObjectFilesArchiveInterface: every file of an object as one ZIP, audited as one download.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * What the object API (Core) asks of file storage for `GET .../{id}/files/download`.
 *
 * The object API finds the object (with the caller's access), serves the bytes
 * and removes the temporary file; file storage (Files) builds the archive and
 * keeps the audit trail. Files binds its answer in its own registrar.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface ObjectFilesArchiveInterface {

	/**
	 * Build a temporary ZIP of every file in the object's folder.
	 *
	 * @param ObjectEntity $object  The object whose files are archived.
	 * @param string|null  $zipName The requested file name, or null for the default.
	 *
	 * @return array The temporary file (`path`), its `filename` and `mimeType`, and its `size` when known.
	 *
	 * @throws \Exception When the archive cannot be built.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function createZip(ObjectEntity $object, ?string $zipName): array;

	/**
	 * Record the download on the audit trail as ONE entry tied to the object, naming every file.
	 *
	 * @param ObjectEntity $object  The object whose files were downloaded.
	 * @param array        $zipInfo The archive as createZip() answered it.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the entry cannot be written; the caller decides whether that matters.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function recordDownload(ObjectEntity $object, array $zipInfo): void;
}//end interface
