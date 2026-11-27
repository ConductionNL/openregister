<?php

/**
 * FileTextReader: the text of one file, for consuming apps.
 *
 * Picks the reader the file's type needs (PdfExtractor for PDF, WordExtractor
 * for Word and OpenDocument text, the content itself for plain text) and
 * answers the text. Unlike TextExtractionService::extractFile() it stores
 * nothing: no chunks, no extraction status, no event.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Contract\FileTextReaderInterface;
use OCP\Files\File;

/**
 * Reads a file's text through the reader its type needs.
 *
 * @spec openspec/changes/a-file-text-read-for-consuming-apps/specs/text-extraction/spec.md
 */
class FileTextReader implements FileTextReaderInterface {

	/**
	 * Word-family MIME types, read by WordExtractor.
	 *
	 * @var list<string>
	 */
	private const WORD_MIME_TYPES = [
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/msword',
		'application/vnd.oasis.opendocument.text',
	];

	/**
	 * Word-family extensions, used when the MIME type is generic.
	 *
	 * @var list<string>
	 */
	private const WORD_EXTENSIONS = ['docx', 'doc', 'odt'];

	/**
	 * Plain-text extensions, used when the MIME type is generic.
	 *
	 * @var list<string>
	 */
	private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown'];

	/**
	 * Constructor.
	 *
	 * @param PdfExtractor  $pdfExtractor  Reads PDF text.
	 * @param WordExtractor $wordExtractor Reads Word and OpenDocument text.
	 */
	public function __construct(
		private readonly PdfExtractor $pdfExtractor,
		private readonly WordExtractor $wordExtractor,
	) {
	}//end __construct()

	/**
	 * The text of a file, or null when its type has no reader or it holds no text.
	 *
	 * @param File $file The file.
	 *
	 * @return string|null
	 *
	 * @throws \Exception When the reader the type needs cannot read the file.
	 *
	 * @spec openspec/changes/a-file-text-read-for-consuming-apps/specs/text-extraction/spec.md
	 */
	public function readFileText(File $file): ?string {
		$mimeType  = strtolower((string)$file->getMimeType());
		$extension = strtolower(pathinfo((string)$file->getName(), PATHINFO_EXTENSION));

		if ($mimeType === 'application/pdf' || $extension === 'pdf') {
			return $this->pdfExtractor->extract(file: $file);
		}

		if (in_array($mimeType, self::WORD_MIME_TYPES, true) === true || in_array($extension, self::WORD_EXTENSIONS, true) === true) {
			return $this->wordExtractor->extract(file: $file);
		}

		if (str_starts_with($mimeType, 'text/') === true || in_array($extension, self::TEXT_EXTENSIONS, true) === true) {
			return self::nonEmpty(text: (string)$file->getContent());
		}

		return null;
	}//end readFileText()

	/**
	 * The text, or null when it holds only white space.
	 *
	 * @param string $text The text.
	 *
	 * @return string|null
	 */
	private static function nonEmpty(string $text): ?string {
		if (trim($text) === '') {
			return null;
		}

		return $text;
	}//end nonEmpty()
}//end class
