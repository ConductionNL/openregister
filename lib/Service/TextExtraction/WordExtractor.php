<?php

/**
 * OpenRegister Word Extractor
 *
 * Dedicated handler for extracting plain text from Word-family documents
 * (docx/doc/odt via PhpWord). Split out of the TextExtractionService god-class
 * (extract-god-class-services) so each format lives in its own single-
 * responsibility class, mirroring the EmlParser / SpreadsheetExtractor /
 * PdfExtractor handlers. Behaviour is identical to the former
 * TextExtractionService::extractWord() and its helpers.
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

use Exception;
use OCP\Files\File;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Psr\Log\LoggerInterface;

/**
 * Extracts text content from Word-family documents.
 */
class WordExtractor {
	/**
	 * Maximum recursion depth when walking Word document elements.
	 */
	private const MAX_WORD_DEPTH = 50;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Extract plain text from a Word-family document.
	 *
	 * @param File $file The document to extract.
	 *
	 * @return string|null The extracted text, or null when empty / on per-document failure.
	 *
	 * @throws Exception When the PhpWord library is unavailable.
	 */
	public function extract(File $file): ?string {
		// Check if PhpWord library is available (deployment error — still throws).
		if (class_exists('PhpOffice\PhpWord\IOFactory') === false) {
			$this->logger->warning(
				message: '[WordExtractor] PhpWord library not available',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
				]
			);
			$msg = 'PhpWord library (phpoffice/phpword) is not installed. ';
			$msg .= 'Run: composer require phpoffice/phpword';
			throw new Exception($msg);
		}

		$readerName = $this->resolveWordReader(mimeType: (string)$file->getMimeType(), fileName: (string)$file->getName());

		$tempFile = null;
		try {
			$this->logger->debug(
				message: '[WordExtractor] Extracting Word document',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'name' => $file->getName(),
					'reader' => $readerName,
				]
			);

			// Write the content to a temp file for PhpWord to read.
			$content = $file->getContent();
			$tempFile = tmpfile();
			$tempPath = stream_get_meta_data($tempFile)['uri'];
			fwrite($tempFile, $content);

			// Load with the reader chosen from the MIME/extension.
			$phpWord = WordIOFactory::load($tempPath, $readerName);

			// Walk every section: headers, body, footers.
			$text = '';
			foreach ($phpWord->getSections() as $section) {
				foreach ($section->getHeaders() as $header) {
					$text .= $this->walkWordElements(elements: $header->getElements());
				}

				$text .= $this->walkWordElements(elements: $section->getElements());

				foreach ($section->getFooters() as $footer) {
					$text .= $this->walkWordElements(elements: $footer->getElements());
				}
			}

			// Always capture document-level footnotes/endnotes in addition to
			// any inline notes the body walk already picked up.
			$text .= $this->extractWordNotes(phpWord: $phpWord);

			fclose($tempFile);
			$tempFile = null;

			if (trim($text) === '') {
				$this->logger->warning(
					message: '[WordExtractor] Word extraction returned empty text',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'fileId' => $file->getId(),
						'reader' => $readerName,
					]
				);
				return null;
			}

			$this->logger->debug(
				message: '[WordExtractor] Word document extracted successfully',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'length' => strlen($text),
				]
			);

			return $text;
		} catch (\Throwable $e) {
			if (is_resource($tempFile) === true) {
				fclose($tempFile);
			}

			// Per-document failure (e.g. limited MsDoc binary parsing): log
			// structure only (no document content, per ADR-005) and degrade
			// to null so the surrounding pipeline treats it as "no text".
			$this->logger->error(
				message: '[WordExtractor] Word extraction failed; returning null',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'mimeType' => (string)$file->getMimeType(),
					'reader' => $readerName,
					'exception' => get_class($e),
				]
			);
			return null;
		}//end try
	}//end extract()

	/**
	 * Recursively walk PhpWord elements accumulating their text.
	 *
	 * @param iterable $elements The elements to walk.
	 * @param int $depth Current recursion depth.
	 *
	 * @return string Accumulated text.
	 */
	private function walkWordElements(iterable $elements, int $depth = 0): string {
		if ($depth > self::MAX_WORD_DEPTH) {
			$this->logger->debug(
				message: '[WordExtractor] Word element walk hit MAX_WORD_DEPTH; stopping descent',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'maxDepth' => self::MAX_WORD_DEPTH,
				]
			);
			return '';
		}

		$text = '';
		foreach ($elements as $element) {
			// Table: rows → cells → recurse cell elements (nested tables re-enter here).
			if (method_exists($element, 'getRows') === true) {
				foreach ($element->getRows() as $row) {
					if (method_exists($row, 'getCells') === false) {
						continue;
					}

					foreach ($row->getCells() as $cell) {
						if (method_exists($cell, 'getElements') === true) {
							$text .= $this->walkWordElements(elements: $cell->getElements(), depth: ($depth + 1));
						}
					}

					$text .= "\n";
				}

				continue;
			}

			// Composite container (TextRun, ListItemRun, Footnote, ...): descend into children.
			if (method_exists($element, 'getElements') === true) {
				$children = $element->getElements();
				if (empty($children) === false) {
					$text .= $this->walkWordElements(elements: $children, depth: ($depth + 1));
					$text .= "\n";
					continue;
				}
			}

			// Leaf text-bearing element (Text, Title, Link, ListItem, PreserveText).
			if (method_exists($element, 'getText') === true) {
				$value = $element->getText();
				if (is_string($value) === true) {
					if ($value !== '') {
						$text .= $value . "\n";
					}
				} elseif (is_object($value) === true) {
					// Some elements (e.g. Title) return a TextRun from getText() — walk it.
					$text .= $this->walkWordElements(elements: [$value], depth: ($depth + 1));
				}
			}
		}//end foreach

		return $text;
	}//end walkWordElements()

	/**
	 * Extract document-level footnote and endnote text.
	 *
	 * @param \PhpOffice\PhpWord\PhpWord $phpWord Loaded PhpWord document.
	 *
	 * @return string Accumulated footnote/endnote text.
	 */
	private function extractWordNotes(\PhpOffice\PhpWord\PhpWord $phpWord): string {
		$text = '';

		$collections = [];
		try {
			$collections[] = $phpWord->getFootnotes();
			$collections[] = $phpWord->getEndnotes();
		} catch (\Throwable $e) {
			// Older/newer PhpWord without these accessors — inline capture still applies.
			return $text;
		}

		foreach ($collections as $collection) {
			if (method_exists($collection, 'getItems') === false) {
				continue;
			}

			foreach ($collection->getItems() as $note) {
				if (method_exists($note, 'getElements') === true) {
					$text .= $this->walkWordElements(elements: $note->getElements());
				}
			}
		}

		return $text;
	}//end extractWordNotes()

	/**
	 * Map a Word-family MIME type (or filename extension) to a PhpWord reader name.
	 *
	 * @param string $mimeType The file MIME type.
	 * @param string $fileName The file name (extension used as fallback).
	 *
	 * @return string PhpWord reader name (Word2007 | MsDoc | ODText).
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
