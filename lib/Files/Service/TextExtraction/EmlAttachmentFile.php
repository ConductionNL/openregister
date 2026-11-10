<?php

/**
 * EmlAttachmentFile: the file an EML attachment carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * Filename, MIME type and decoded bytes of one EML attachment.
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */
final class EmlAttachmentFile {
	/**
	 * Constructor.
	 *
	 * @param string $filename Resolved filename (always non-empty).
	 * @param string $mimeType MIME type from `Content-Type`.
	 * @param string $content  Decoded binary bytes of the attachment.
	 */
	public function __construct(
		public readonly string $filename,
		public readonly string $mimeType,
		public readonly string $content,
	) {
	}//end __construct()
}//end class
