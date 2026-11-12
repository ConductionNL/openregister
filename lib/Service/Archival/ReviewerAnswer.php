<?php

/**
 * OpenRegister ReviewerAnswer
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
 * A review answer as the reviewer gave it, before it is carried out: the
 * answer, why, the new archiefactiedatum a retention moves to, and who gave it.
 *
 * {@see ReviewOutcomeService::apply()} carries it out on the record, and
 * {@see DestructionReviewService::recordAnswer()} writes it into the list with
 * the transfer list the carrying-out produced. Built by
 * {@see DestructionReviewServiceInterface::reviewerAnswer()}, so callers
 * outside this component never name it.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final readonly class ReviewerAnswer {

	/**
	 * Bundle the answer.
	 *
	 * @param string      $answer   One of DestructionReviewService::ANSWERS.
	 * @param string      $reason   Why the reviewer answered this way.
	 * @param string|null $newDate  The new archiefactiedatum, for a retention.
	 * @param string|null $reviewer Who answered; must be the entry's assigned reviewer to be recorded.
	 */
	public function __construct(
		public string $answer,
		public string $reason,
		public ?string $newDate = null,
		public ?string $reviewer = null,
	) {
	}//end __construct()
}//end class
