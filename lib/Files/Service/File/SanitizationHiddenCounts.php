<?php

/**
 * OpenRegister sanitization hidden-content counts
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
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * What a spreadsheet or presentation hid and the sanitiser took out: hidden
 * sheets, the cells of hidden rows and columns, hidden slides and speaker
 * notes. Counts only, never content (ADR-005).
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */
final class SanitizationHiddenCounts {
	/**
	 * Bundle the counts.
	 *
	 * @param int $hiddenSheetsRemoved  Hidden and very hidden sheets removed.
	 * @param int $hiddenRowsCleared    Hidden rows whose cells were cleared.
	 * @param int $hiddenColumnsCleared Hidden columns whose cells were cleared.
	 * @param int $hiddenSlidesRemoved  Hidden slides removed.
	 * @param int $speakerNotesRemoved  Speaker notes removed.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function __construct(
		public readonly int $hiddenSheetsRemoved = 0,
		public readonly int $hiddenRowsCleared = 0,
		public readonly int $hiddenColumnsCleared = 0,
		public readonly int $hiddenSlidesRemoved = 0,
		public readonly int $speakerNotesRemoved = 0,
	) {
	}//end __construct()

	/**
	 * The counts as the report's extra keys, in a fixed order.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function toArray(): array {
		return [
			'hiddenSheetsRemoved' => $this->hiddenSheetsRemoved,
			'hiddenRowsCleared' => $this->hiddenRowsCleared,
			'hiddenColumnsCleared' => $this->hiddenColumnsCleared,
			'hiddenSlidesRemoved' => $this->hiddenSlidesRemoved,
			'speakerNotesRemoved' => $this->speakerNotesRemoved,
		];
	}//end toArray()
}//end class
