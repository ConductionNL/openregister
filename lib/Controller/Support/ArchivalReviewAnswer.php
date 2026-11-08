<?php

/**
 * OpenRegister archival review answer
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller\Support;

/**
 * A reviewer's answer to one destruction-list entry, as the request gave it.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final readonly class ArchivalReviewAnswer {
	/**
	 * Bundle the parts of one review answer.
	 *
	 * @param string      $answer   destroy, retain or transfer.
	 * @param string      $reason   Why the reviewer answers so.
	 * @param string|null $newDate  The new archiefactiedatum of a retained record.
	 * @param string      $reviewer The user id of the reviewer answering.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function __construct(
		public string $answer,
		public string $reason,
		public ?string $newDate,
		public string $reviewer,
	) {
	}//end __construct()
}//end class
