<?php

/**
 * TextExtractionReaders: the document readers of TextExtractionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * The document readers of TextExtractionService.
 */
final class TextExtractionReaders {
	/**
	 * Bundle the services.
	 *
	 * @param EmlParser            $emlParser            EML message parser (message/rfc822 inputs and parseEmlStructured)
	 * @param SpreadsheetExtractor $spreadsheetExtractor Extractor for spreadsheet documents
	 * @param PdfExtractor         $pdfExtractor         Extractor for PDF documents
	 * @param WordExtractor        $wordExtractor        Extractor for Word documents
	 */
	public function __construct(
		public readonly EmlParser $emlParser,
		public readonly SpreadsheetExtractor $spreadsheetExtractor,
		public readonly PdfExtractor $pdfExtractor,
		public readonly WordExtractor $wordExtractor,
	) {
	}//end __construct()
}//end class
