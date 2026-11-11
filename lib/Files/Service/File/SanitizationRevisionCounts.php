<?php

/**
 * OpenRegister sanitization revision counts
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
 * The review-trail counts of a sanitisation run: comments and tracked changes. Counts only, never content (ADR-005).
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */
final class SanitizationRevisionCounts {
	/**
	 * Bundle the counts.
	 *
	 * @param int $commentsRemoved            Distinct comments/annotations removed.
	 * @param int $trackedChangesAccepted     Insert ranges accepted (unwrapped).
	 * @param int $trackedChangesDropped      Delete ranges dropped.
	 * @param int $revisionAttributesStripped Revision (rsid) attributes removed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function __construct(
		public readonly int $commentsRemoved = 0,
		public readonly int $trackedChangesAccepted = 0,
		public readonly int $trackedChangesDropped = 0,
		public readonly int $revisionAttributesStripped = 0,
	) {
	}//end __construct()
}//end class
