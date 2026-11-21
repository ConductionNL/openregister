<?php

/**
 * OpenRegister RedactionReviewGate
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\RedactionReviewMarkMapper;
use OCA\OpenRegister\Exception\ReviewRequiredException;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;

/**
 * Nothing is written until a person has checked it.
 *
 * With `anonymisation.requireReview` on, an anonymised copy is refused until
 * every detection of the file's current run has a decision and a review mark
 * matches the run's fingerprint. Enforced where the copy is written, so the
 * endpoint, a direct service call and every batch path meet the same refusal.
 */
class RedactionReviewGate {

	/**
	 * Constructor.
	 *
	 * @param FileSettingsHandler       $settings  Whether review is required.
	 * @param EntityRelationMapperInterface $relations The file's detections and their decisions.
	 * @param RedactionReviewMarkMapper $marks     The file's review marks.
	 */
	public function __construct(
		private readonly FileSettingsHandler $settings,
		private readonly EntityRelationMapperInterface $relations,
		private readonly RedactionReviewMarkMapper $marks,
	) {
	}//end __construct()

	/**
	 * Refuse anonymised output of a file a person has not checked.
	 *
	 * @param int $fileId The file.
	 *
	 * @return void
	 *
	 * @throws ReviewRequiredException When a detection is undecided, or no mark matches the current run.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.3
	 */
	public function assertMayAnonymise(int $fileId): void {
		if ($this->settings->requireReview() === false) {
			return;
		}

		$detections = $this->relations->findDetectionsForReview(fileId: $fileId);
		$undecided = 0;
		foreach ($detections as $row) {
			if ((string)($row['redaction_decision'] ?? EntityRelation::DECISION_UNDECIDED) === EntityRelation::DECISION_UNDECIDED) {
				$undecided++;
			}
		}

		if ($undecided > 0) {
			throw new ReviewRequiredException(
				reason: ReviewRequiredException::UNDECIDED_ENTITIES,
				undecided: $undecided,
				message: sprintf(
					'This document must be checked before it is anonymised: %d of its detections have no decision yet. '
					. 'Decide every detection, then mark the document as checked.',
					$undecided
				)
			);
		}

		$mark = $this->marks->findLatestForFile(fileId: $fileId);
		if ($mark === null) {
			throw new ReviewRequiredException(
				reason: ReviewRequiredException::NO_MARK,
				undecided: 0,
				message: 'This document must be checked before it is anonymised: mark it as checked once every detection is decided.'
			);
		}

		if (hash_equals($mark->getRunFingerprint(), RedactionRunFingerprint::of(detections: $detections)) === false) {
			throw new ReviewRequiredException(
				reason: ReviewRequiredException::STALE_MARK,
				undecided: 0,
				message: sprintf(
					'This document must be checked again: it was checked by %s on %s, before the detection changed.',
					$mark->getCheckedBy(),
					(string)$mark->getCheckedAt()?->format('Y-m-d')
				)
			);
		}
	}//end assertMayAnonymise()
}//end class
