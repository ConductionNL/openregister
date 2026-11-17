<?php

/**
 * FileTextReaderInterface: the published read of a document's text.
 *
 * OpenRegister reads the text of PDF, Word and OpenDocument files for its own
 * extraction runs. A consuming app that lets its user pick a document (a
 * requirements brief, for one) needs the same text without reaching into
 * this app's extraction handlers, so the read is published here, next to
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
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * Reads the text of one file.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
interface FileTextReaderInterface {

	/**
	 * The text of a file the caller has already opened.
	 *
	 * The caller opens the node through its user's own folder
	 * (`IRootFolder::getUserFolder($userId)->getById()`), so a file the user
	 * cannot read never gets here: the read itself applies no rights. Nothing
	 * is stored; the text is read each time.
	 *
	 * @param \OCP\Files\File $file The file.
	 *
	 * @return string|null The text, or null when the type is not one of
	 *                     {@see self::readableMimeTypes()} or the file holds no text.
	 *
	 * @throws \Exception When the file's reader fails on it.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function readText(\OCP\Files\File $file): ?string;

	/**
	 * The mime types {@see self::readText()} reads.
	 *
	 * A file is also read by its extension (`pdf`, `docx`, `doc`, `odt`) when
	 * its mime type is a generic one such as `application/octet-stream`.
	 *
	 * @return array<int,string> The mime types.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function readableMimeTypes(): array;
}//end interface
