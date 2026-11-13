<?php

/**
 * OpenRegister ProvidedTextRequest.
 *
 * A request to index text a caller already extracted (for example by OCR) as the content of a file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * A request to index text a caller already extracted (for example by OCR) as the content of a file.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ProvidedTextRequest {
	/**
	 * Constructor.
	 *
	 * @param int                     $fileId      The Nextcloud file id the text belongs to.
	 * @param string                  $text        The provided text.
	 * @param array<int, string>|null $entityTypes Entity types to detect; null for all.
	 * @param string                  $method      The extraction method recorded with the chunks.
	 * @param array<mixed>|null       $words       OCR words with positions, `[{text, page, box: {x, y, w, h}}]`; null when the provider has none.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $text,
		public readonly ?array $entityTypes = null,
		public readonly string $method = 'ocr',
		public readonly ?array $words = null,
	) {
	}//end __construct()
}//end class
