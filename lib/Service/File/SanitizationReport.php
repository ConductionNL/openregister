<?php

/**
 * SanitizationReport
 *
 * Immutable per-category count record produced by an Office document
 * sanitiser. Persisted as JSON alongside the anonymisation result for audit.
 *
 * Per ADR-005 it carries ONLY counts and the applied sentinel string — never
 * document content, comment text, metadata values, or hyperlink URLs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use JsonSerializable;

/**
 * Immutable value object capturing the per-category sanitisation counts.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */
final class SanitizationReport implements JsonSerializable {
	/**
	 * Constructor.
	 *
	 * @param int $commentsRemoved Distinct comments/annotations removed.
	 * @param int $trackedChangesAccepted Insert ranges accepted (unwrapped).
	 * @param int $trackedChangesDropped Delete ranges dropped.
	 * @param int $revisionAttributesStripped Revision (rsid) attributes removed.
	 * @param int $hyperlinksFlattened Hyperlinks flattened to plain text.
	 * @param int $metadataFieldsScrubbed Metadata fields replaced with sentinel.
	 * @param int $customXmlPartsDropped Custom XML parts removed.
	 * @param int $fieldCodesStripped Person-identity field codes removed.
	 * @param string $sentinelApplied The sentinel string used for scrubbing.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) Property names are the stable audit-report JSON keys (design D9).
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function __construct(
		public readonly int $commentsRemoved = 0,
		public readonly int $trackedChangesAccepted = 0,
		public readonly int $trackedChangesDropped = 0,
		public readonly int $revisionAttributesStripped = 0,
		public readonly int $hyperlinksFlattened = 0,
		public readonly int $metadataFieldsScrubbed = 0,
		public readonly int $customXmlPartsDropped = 0,
		public readonly int $fieldCodesStripped = 0,
		public readonly string $sentinelApplied = '',
	) {
	}//end __construct()

	/**
	 * Serialise to a stable, ordered associative array.
	 *
	 * @return array<string, int|string>
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'commentsRemoved' => $this->commentsRemoved,
			'trackedChangesAccepted' => $this->trackedChangesAccepted,
			'trackedChangesDropped' => $this->trackedChangesDropped,
			'revisionAttributesStripped' => $this->revisionAttributesStripped,
			'hyperlinksFlattened' => $this->hyperlinksFlattened,
			'metadataFieldsScrubbed' => $this->metadataFieldsScrubbed,
			'customXmlPartsDropped' => $this->customXmlPartsDropped,
			'fieldCodesStripped' => $this->fieldCodesStripped,
			'sentinelApplied' => $this->sentinelApplied,
		];
	}//end jsonSerialize()
}//end class
