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

namespace OCA\OpenRegister\Files\Service\File;

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
	 * Distinct comments/annotations removed.
	 *
	 * @var int
	 */
	public readonly int $commentsRemoved;

	/**
	 * Insert ranges accepted (unwrapped).
	 *
	 * @var int
	 */
	public readonly int $trackedChangesAccepted;

	/**
	 * Delete ranges dropped.
	 *
	 * @var int
	 */
	public readonly int $trackedChangesDropped;

	/**
	 * Revision (rsid) attributes removed.
	 *
	 * @var int
	 */
	public readonly int $revisionAttributesStripped;

	/**
	 * Hyperlinks flattened to plain text.
	 *
	 * @var int
	 */
	public readonly int $hyperlinksFlattened;

	/**
	 * Metadata fields replaced with sentinel.
	 *
	 * @var int
	 */
	public readonly int $metadataFieldsScrubbed;

	/**
	 * Custom XML parts removed.
	 *
	 * @var int
	 */
	public readonly int $customXmlPartsDropped;

	/**
	 * Person-identity field codes removed.
	 *
	 * @var int
	 */
	public readonly int $fieldCodesStripped;

	/**
	 * The sentinel string used for scrubbing.
	 *
	 * @var string
	 */
	public readonly string $sentinelApplied;

	/**
	 * Constructor.
	 *
	 * @param SanitizationRevisionCounts  $revisions       Comment and tracked-change counts.
	 * @param SanitizationStructureCounts $structure       Hyperlink, metadata, custom XML and field-code counts.
	 * @param string                      $sentinelApplied The sentinel string used for scrubbing.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function __construct(
		SanitizationRevisionCounts $revisions = new SanitizationRevisionCounts(),
		SanitizationStructureCounts $structure = new SanitizationStructureCounts(),
		string $sentinelApplied = '',
	) {
		$this->commentsRemoved = $revisions->commentsRemoved;
		$this->trackedChangesAccepted = $revisions->trackedChangesAccepted;
		$this->trackedChangesDropped = $revisions->trackedChangesDropped;
		$this->revisionAttributesStripped = $revisions->revisionAttributesStripped;
		$this->hyperlinksFlattened = $structure->hyperlinksFlattened;
		$this->metadataFieldsScrubbed = $structure->metadataFieldsScrubbed;
		$this->customXmlPartsDropped = $structure->customXmlPartsDropped;
		$this->fieldCodesStripped = $structure->fieldCodesStripped;
		$this->sentinelApplied = $sentinelApplied;
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
