<?php

/**
 * OoxmlFormatFilter
 *
 * Decides whether a file is one of the OOXML formats an extractor reads: by
 * its MIME type, or, when the MIME type is too generic to decide on, by its
 * file extension. Shared by DocumentExtractor and PresentationExtractor.
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
 * @spec openspec/specs/text-extraction-presentation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * The MIME types and extensions one extractor reads.
 *
 * @spec openspec/specs/text-extraction-document/spec.md
 * @spec openspec/specs/text-extraction-presentation/spec.md
 */
final class OoxmlFormatFilter {

	/**
	 * MIME types too generic to decide on; the file extension decides instead.
	 *
	 * @var list<string>
	 */
	private const GENERIC_MIME_TYPES = ['', 'application/octet-stream', 'application/zip', 'application/x-zip-compressed'];

	/**
	 * Constructor.
	 *
	 * @param list<string> $mimeTypes  MIME types read directly (lower case).
	 * @param list<string> $extensions Extensions read when the MIME type is generic (lower case).
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md
	 */
	public function __construct(
		private readonly array $mimeTypes,
		private readonly array $extensions,
	) {
	}//end __construct()

	/**
	 * Whether a file is a format this filter accepts, by MIME type or, when that is generic, by extension.
	 *
	 * @param string $mimeType The file MIME type.
	 * @param string $fileName The file name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-the-supported-formats-can-be-asked-for-req-docx-010
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-the-supported-formats-can-be-asked-for-req-pptx-007
	 */
	public function accepts(string $mimeType, string $fileName): bool {
		$mimeType = strtolower($mimeType);
		if (in_array($mimeType, $this->mimeTypes, true) === true) {
			return true;
		}

		if (in_array($mimeType, self::GENERIC_MIME_TYPES, true) === false) {
			return false;
		}

		return in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), $this->extensions, true);
	}//end accepts()
}//end class
