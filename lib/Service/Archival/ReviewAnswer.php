<?php

/**
 * OpenRegister ReviewAnswer
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * One review answer as a reviewer gave it: the answer, why, the new
 * archiefactiedatum a retention moves to, and the transfer list a transfer
 * was handed to. {@see DestructionReviewService::recordAnswer()} guards it and
 * records its facts as one.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final readonly class ReviewAnswer {

	/**
	 * Bundle the answer.
	 *
	 * @param string      $answer      The answer given.
	 * @param string      $reason      The reason given.
	 * @param string|null $newDate     The new archiefactiedatum, for a retention.
	 * @param string|null $transferRef The transfer list the entry was handed to, if any.
	 */
	public function __construct(
		public string $answer,
		public string $reason,
		public ?string $newDate,
		public ?string $transferRef,
	) {
	}//end __construct()
}//end class
