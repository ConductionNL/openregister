<?php

/**
 * FileTextReader: the published read of a document's text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Contract\FileTextReaderInterface;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCP\Files\File;

/**
 * Reads a file's text with the extractor its type asks for.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
class FileTextReader implements FileTextReaderInterface {

	/**
	 * Which reader takes a mime type: `text`, `pdf` or `word`.
	 *
	 * @var array<string,string>
	 */
	private const READER_BY_MIME = [
		'text/plain' => 'text',
		'text/markdown' => 'text',
		'text/x-markdown' => 'text',
		'application/pdf' => 'pdf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
		'application/msword' => 'word',
		'application/vnd.oasis.opendocument.text' => 'word',
	];

	/**
	 * Which reader takes an extension, for a file served with a generic mime type.
	 *
	 * @var array<string,string>
	 */
	private const READER_BY_EXTENSION = [
		'txt' => 'text',
		'md' => 'text',
		'markdown' => 'text',
		'pdf' => 'pdf',
		'docx' => 'word',
		'doc' => 'word',
		'odt' => 'word',
	];

	/**
	 * Mime types that say nothing about the content, so the extension decides.
	 *
	 * @var array<int,string>
	 */
	private const GENERIC_MIMES = ['', 'application/octet-stream'];

	/**
	 * Constructor.
	 *
	 * @param PdfExtractor  $pdfExtractor  Reads PDF files.
	 * @param WordExtractor $wordExtractor Reads Word and OpenDocument text files.
	 */
	public function __construct(
		private readonly PdfExtractor $pdfExtractor,
		private readonly WordExtractor $wordExtractor,
	) {
	}//end __construct()

	/**
	 * The text of a file the caller has already opened.
	 *
	 * @param File $file The file.
	 *
	 * @return string|null The text, or null for an unread type or a file without text.
	 *
	 * @throws \Exception When the file's reader fails on it.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function readText(File $file): ?string {
		return match ($this->readerOf(file: $file)) {
			'text' => (string)$file->getContent(),
			'pdf' => $this->pdfExtractor->extract(file: $file),
			'word' => $this->wordExtractor->extract(file: $file),
			default => null,
		};
	}//end readText()

	/**
	 * The mime types readText() reads.
	 *
	 * @return array<int,string> The mime types.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function readableMimeTypes(): array {
		return array_keys(self::READER_BY_MIME);
	}//end readableMimeTypes()

	/**
	 * The reader a file's mime type names; for a generic mime type the one its extension names; else ''.
	 *
	 * @param File $file The file.
	 *
	 * @return string `text`, `pdf`, `word` or ''.
	 */
	private function readerOf(File $file): string {
		$mime = (string)$file->getMimeType();
		if (isset(self::READER_BY_MIME[$mime]) === true) {
			return self::READER_BY_MIME[$mime];
		}

		if (in_array($mime, self::GENERIC_MIMES, true) === false) {
			return '';
		}

		$extension = strtolower(pathinfo((string)$file->getName(), PATHINFO_EXTENSION));
		return self::READER_BY_EXTENSION[$extension] ?? '';
	}//end readerOf()
}//end class
