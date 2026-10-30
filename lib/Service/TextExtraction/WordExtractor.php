<?php

/**
 * OpenRegister Word Extractor
 *
 * Dedicated handler for extracting plain text from Word-family documents:
 * docx and odt through WordTextReader. Legacy binary doc is not read.
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
 */

namespace OCA\OpenRegister\Service\TextExtraction;

use OCA\OpenRegister\Service\File\Office\ConversionManagerConverter;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Extracts text content from Word-family documents.
 */
class WordExtractor {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface                 $logger          Logger.
	 * @param ConversionManagerConverter|null $officeConverter Office server conversion, for legacy .doc.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?ConversionManagerConverter $officeConverter = null,
	) {
	}//end __construct()

	/**
	 * Extract plain text from a Word-family document.
	 *
	 * DOCX and ODT are read by {@see WordTextReader}. A legacy binary .doc is
	 * converted by the office server to DOCX (read the same way) or to plain
	 * text; without an office server that converts it, it gives null.
	 *
	 * @param File $file The document to extract.
	 *
	 * @return string|null The extracted text, or null when empty, unsupported or on per-document failure.
	 *
	 * @spec openspec/specs/text-extraction-word/spec.md
	 */
	public function extract(File $file): ?string {
		$readerName = $this->resolveWordReader(mimeType: (string)$file->getMimeType(), fileName: (string)$file->getName());
		if ($readerName === 'MsDoc' && $this->officeConverter?->legacyDocTarget() === null) {
			$this->logger->info(
				message: '[WordExtractor] Legacy binary .doc is not supported; no text extracted',
				context: ['fileId' => $file->getId()]
			);
			return null;
		}

		return $this->extractPackage(file: $file, readerName: $readerName);
	}//end extract()

	/**
	 * Extract the text, logging failures without document content.
	 *
	 * @param File   $file       The document.
	 * @param string $readerName `Word2007`, `ODText` or `MsDoc`.
	 *
	 * @return string|null The text, or null when empty or unreadable.
	 */
	private function extractPackage(File $file, string $readerName): ?string {
		try {
			$text = $this->textOf(file: $file, readerName: $readerName);
		} catch (\Throwable $e) {
			$text = null;
			$this->logger->error(
				message: '[WordExtractor] Word extraction failed; returning null',
				context: ['fileId' => $file->getId(), 'mimeType' => (string)$file->getMimeType(), 'reader' => $readerName, 'exception' => get_class($e)]
			);
		}

		if ($text === null || trim($text) === '') {
			$this->logger->warning(
				message: '[WordExtractor] Word extraction returned empty text',
				context: ['fileId' => $file->getId(), 'reader' => $readerName]
			);
			return null;
		}

		return $text;
	}//end extractPackage()

	/**
	 * The text of a document: DOCX and ODT directly, legacy .doc after conversion.
	 *
	 * @param File   $file       The document.
	 * @param string $readerName `Word2007`, `ODText` or `MsDoc`.
	 *
	 * @return string|null
	 *
	 * @throws \RuntimeException When the office server cannot convert a .doc.
	 */
	private function textOf(File $file, string $readerName): ?string {
		if ($readerName !== 'MsDoc' || $this->officeConverter === null) {
			return (new WordTextReader())->read(bytes: $file->getContent(), format: $readerName);
		}

		$target = (string)$this->officeConverter->legacyDocTarget();
		$converted = $this->officeConverter->convertLegacyDoc(docBytes: $file->getContent(), target: $target);
		if ($target === 'docx') {
			return (new WordTextReader())->read(bytes: $converted, format: 'Word2007');
		}

		return $converted;
	}//end textOf()

	/**
	 * Map a Word-family MIME type (or filename extension) to a reader name.
	 *
	 * @param string $mimeType The file MIME type.
	 * @param string $fileName The file name (extension used as fallback).
	 *
	 * @return string Reader name (Word2007 | MsDoc | ODText).
	 */
	private function resolveWordReader(string $mimeType, string $fileName): string {
		$byMime = [
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word2007',
			'application/msword' => 'MsDoc',
			'application/vnd.oasis.opendocument.text' => 'ODText',
		];

		if (isset($byMime[$mimeType]) === true) {
			return $byMime[$mimeType];
		}

		// Fall back to the filename extension when the MIME is generic/ambiguous.
		$byExt = [
			'docx' => 'Word2007',
			'doc' => 'MsDoc',
			'odt' => 'ODText',
		];

		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if (isset($byExt[$ext]) === true) {
			return $byExt[$ext];
		}

		return 'Word2007';
	}//end resolveWordReader()
}//end class
