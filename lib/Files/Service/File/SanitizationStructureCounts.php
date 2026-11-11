<?php

/**
 * OpenRegister sanitization structure counts
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * The document-structure counts of a sanitisation run: links, metadata, custom XML and field codes. Counts only, never content (ADR-005).
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */
final class SanitizationStructureCounts {
	/**
	 * Bundle the counts.
	 *
	 * @param int $hyperlinksFlattened    Hyperlinks flattened to plain text.
	 * @param int $metadataFieldsScrubbed Metadata fields replaced with sentinel.
	 * @param int $customXmlPartsDropped  Custom XML parts removed.
	 * @param int $fieldCodesStripped     Person-identity field codes removed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function __construct(
		public readonly int $hyperlinksFlattened = 0,
		public readonly int $metadataFieldsScrubbed = 0,
		public readonly int $customXmlPartsDropped = 0,
		public readonly int $fieldCodesStripped = 0,
	) {
	}//end __construct()
}//end class
