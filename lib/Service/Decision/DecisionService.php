<?php

/**
 * OpenRegister DecisionService
 *
 * Records and reads decisions as their own objects, for any subject an app
 * links them from (decision 197).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\DecisionMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Records a decision, finds one, and lists a subject's decisions.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */
class DecisionService {

	/**
	 * The longest note kept, in characters.
	 */
	public const MAX_NOTE = 4000;

	/**
	 * Constructor.
	 *
	 * @param DecisionMapperInterface $decisionMapper Stores decisions.
	 */
	public function __construct(
		private readonly DecisionMapperInterface $decisionMapper,
	) {
	}//end __construct()

	/**
	 * Record a new decision on a subject.
	 *
	 * The caller names the decision: its subject type and id, verdict,
	 * decider, and optionally a note and the uuid of the decision it replaces.
	 * The store gives it its uuid and moment.
	 *
	 * @param Decision $decision The unsaved decision.
	 *
	 * @return Decision The stored decision, with its uuid and moment.
	 *
	 * @throws InvalidArgumentException When a required value is empty or the note is too long.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function record(Decision $decision): Decision {
		$required = [
			'subjectType' => $decision->getSubjectType(),
			'subjectId' => $decision->getSubjectId(),
			'verdict' => $decision->getVerdict(),
			'decidedBy' => $decision->getDecidedBy(),
		];
		foreach ($required as $field => $value) {
			if (trim((string)$value) === '') {
				throw new InvalidArgumentException('A decision needs '.$field.'.');
			}
		}

		$note = $decision->getNote();
		if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
			throw new InvalidArgumentException('A decision note is at most '.self::MAX_NOTE.' characters.');
		}

		return $this->decisionMapper->insert(entity: $decision);
	}//end record()

	/**
	 * One decision by uuid, or null when the uuid is empty or names none.
	 *
	 * @param string|null $uuid The decision uuid.
	 *
	 * @return Decision|null The decision.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function find(?string $uuid): ?Decision {
		if ($uuid === null || $uuid === '') {
			return null;
		}

		try {
			return $this->decisionMapper->findByUuid(uuid: $uuid);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end find()

	/**
	 * Every decision taken on a subject, newest first.
	 *
	 * @param string $subjectType The subject type.
	 * @param string $subjectId   The subject id.
	 *
	 * @return Decision[] The decisions.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function history(string $subjectType, string $subjectId): array {
		return $this->decisionMapper->findForSubject(subjectType: $subjectType, subjectId: $subjectId);
	}//end history()
}//end class
