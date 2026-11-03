<?php

/**
 * OpenRegister PDF Extractor
 *
 * Dedicated handler for extracting plain text from PDF files (via
 * smalot/pdfparser). Split out of the TextExtractionService god-class
 * (extract-god-class-services) so each format lives in its own single-
 * responsibility class, mirroring the existing EmlParser / SpreadsheetExtractor
 * handlers. Behaviour is identical to the former
 * TextExtractionService::extractPdf().
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
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Extracts text content from PDF files.
 */
class PdfExtractor {
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
	 * Extract plain text from a PDF file.
	 *
	 * @param File $file The PDF file to extract.
	 *
	 * @return string|null The extracted text, or null when empty.
	 *
	 * @throws Exception When the PDF parser is unavailable or extraction fails.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function extract(File $file): ?string {
		// Check if PdfParser library is available.
		if (class_exists('Smalot\PdfParser\Parser') === false) {
			$this->logger->warning(
				message: '[PdfExtractor] PDF parser library not available',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
				]
			);
			$msg = 'PDF parser library (smalot/pdfparser) is not installed. ';
			$msg .= 'Run: composer require smalot/pdfparser';
			throw new Exception($msg);
		}

		try {
			$this->logger->debug(
				message: '[PdfExtractor] Extracting PDF',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'name' => $file->getName(),
				]
			);

			$text = $this->extractText(pdfBytes: $file->getContent());

			if ($text === '') {
				$this->logger->warning(
					message: '[PdfExtractor] PDF extraction returned empty text',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'fileId' => $file->getId(),
					]
				);
				return null;
			}

			$this->logger->debug(
				message: '[PdfExtractor] PDF extracted successfully',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'length' => strlen($text),
				]
			);

			return $text;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[PdfExtractor] PDF extraction failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'error' => $e->getMessage(),
				]
			);
			throw new Exception('PDF extraction failed: ' . $e->getMessage());
		}//end try
	}//end extract()

	/**
	 * Extract the raw text layer from PDF bytes.
	 *
	 * This is the only place that talks to the PDF parsing library
	 * (smalot/pdfparser, LGPL-3.0); other services call this method instead.
	 *
	 * @param string $pdfBytes Raw PDF content.
	 *
	 * @return string The extracted text, possibly empty.
	 *
	 * @throws Exception When the bytes cannot be parsed, for example a secured or malformed PDF.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function extractText(string $pdfBytes): string {
		$parser = new PdfParser();

		return $parser->parseContent($pdfBytes)->getText();
	}//end extractText()
}//end class
