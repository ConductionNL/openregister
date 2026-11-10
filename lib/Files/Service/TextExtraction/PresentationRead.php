<?php

/**
 * OpenRegister presentation read
 *
 * One PresentationExtractor::extract() call in progress: the deck, its MIME
 * type, and the temporary file and zip handle opened for it, so they can be
 * released together however the read ends.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-a-deck-that-cannot-be-read-degrades-to-no-result-req-pptx-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCP\Files\File;
use ZipArchive;

/**
 * The deck being read and the handles opened for it.
 *
 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-a-deck-that-cannot-be-read-degrades-to-no-result-req-pptx-005
 */
final class PresentationRead {

	/**
	 * The deck.
	 *
	 * @var File
	 */
	public readonly File $file;

	/**
	 * The deck's MIME type, as the file reported it.
	 *
	 * @var string
	 */
	public readonly string $mimeType;

	/**
	 * The temporary file holding the deck's bytes, once created.
	 *
	 * @var resource|false|null
	 */
	public mixed $tempFile = null;

	/**
	 * The opened zip, set only once it opened successfully.
	 *
	 * @var ZipArchive|null
	 */
	public ?ZipArchive $zip = null;

	/**
	 * Start a read of a deck.
	 *
	 * @param File   $file     The deck.
	 * @param string $mimeType The deck's MIME type.
	 */
	public function __construct(File $file, string $mimeType) {
		$this->file = $file;
		$this->mimeType = $mimeType;
	}//end __construct()

	/**
	 * Close the zip and the temporary file, whichever were opened.
	 *
	 * @return void
	 */
	public function close(): void {
		if ($this->zip !== null) {
			$this->zip->close();
		}

		if (is_resource($this->tempFile) === true) {
			fclose($this->tempFile);
		}
	}//end close()
}//end class
