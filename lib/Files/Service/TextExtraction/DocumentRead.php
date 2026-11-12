<?php

/**
 * DocumentRead
 *
 * One document being read by DocumentExtractor: the file and its MIME type,
 * which every log line of the read carries.
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
 * @spec openspec/specs/text-extraction-document/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCP\Files\File;

/**
 * The document and MIME type of one read.
 *
 * @spec openspec/specs/text-extraction-document/spec.md
 */
final readonly class DocumentRead {
	/**
	 * Constructor.
	 *
	 * @param File   $file     The document.
	 * @param string $mimeType The file MIME type, for the log.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md
	 */
	public function __construct(
		public File $file,
		public string $mimeType,
	) {
	}//end __construct()
}//end class
