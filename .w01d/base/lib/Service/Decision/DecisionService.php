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
use OCA\OpenRegister\Db\DecisionMapper;
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
	 * @param DecisionMapper $decisionMapper Stores decisions.
	 */
	public function __construct(
		private readonly DecisionMapper $decisionMapper,
	) {
	}//end __construct()

	/**
	 * Record a new decision on a subject.
	 *
	 * @param string      $subjectType What kind of thing was decided on.
	 * @param string      $subjectId   Its id within that kind.
	 * @param string      $verdict     The verdict.
	 * @param string      $decidedBy   The user who decided.
	 * @param string|null $note        The decider's note.
	 * @param string|null $supersedes  The uuid of the decision this one replaces.
	 *
	 * @return Decision The stored decision, with its uuid and moment.
	 *
	 * @throws InvalidArgumentException When a required value is empty or the note is too long.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function record(
		string $subjectType,
		string $subjectId,
		string $verdict,
		string $decidedBy,
		?string $note=null,
		?string $supersedes=null,
	): Decision {
		foreach (['subjectType' => $subjectType, 'subjectId' => $subjectId, 'verdict' => $verdict, 'decidedBy' => $decidedBy] as $field => $value) {
			if (trim($value) === '') {
				throw new InvalidArgumentException('A decision needs '.$field.'.');
			}
		}

		if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
			throw new InvalidArgumentException('A decision note is at most '.self::MAX_NOTE.' characters.');
		}

		$decision = new Decision();
		$decision->setSubjectType($subjectType);
		$decision->setSubjectId($subjectId);
		$decision->setVerdict($verdict);
		$decision->setDecidedBy($decidedBy);
		$decision->setNote($note);
		$decision->setSupersedes($supersedes);

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
