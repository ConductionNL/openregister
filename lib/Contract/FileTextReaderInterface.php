<?php

/**
 * FileTextReaderInterface: the published text read of one Nextcloud file.
 *
 * OpenRegister already reads PDF, Word and OpenDocument text for its own
 * search index. A consuming app that needs the text of one file its user
 * picked (a document to generate an app from, for one) would otherwise have to
 * ship its own parsers, so the read is published here, next to
 * {@see ObjectFileReaderInterface}, and owned by the app that implements it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * Reads the text of a file without storing anything.
 *
 * @spec openspec/changes/a-file-text-read-for-consuming-apps/specs/text-extraction/spec.md
 */
interface FileTextReaderInterface {

	/**
	 * The text of a file: PDF, Word (docx, legacy doc where an office server converts it),
	 * OpenDocument text, or a plain-text type (text/*, Markdown).
	 *
	 * The caller hands in a file node it already opened as its user, so the
	 * read applies no rights of its own. Nothing is stored, indexed or chunked,
	 * and no event is dispatched.
	 *
	 * @param \OCP\Files\File $file The file.
	 *
	 * @return string|null The text, or null when the type has no reader or the file holds no text.
	 *
	 * @throws \Exception When a reader the type needs cannot read the file.
	 *
	 * @spec openspec/changes/a-file-text-read-for-consuming-apps/specs/text-extraction/spec.md
	 */
	public function readFileText(\OCP\Files\File $file): ?string;
}//end interface
