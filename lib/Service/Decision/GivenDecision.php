<?php

/**
 * OpenRegister GivenDecision
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
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

/**
 * What a reviewer's body gives: the decision fields, and the note when one
 * was sent (ADR-011 Rule 2 value object).
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */
final class GivenDecision {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $fields  The decision fields, without the note.
	 * @param bool                 $hasNote Whether the body carried a note key.
	 * @param string|null          $note    The note, trimmed; null when absent, empty or null.
	 */
	public function __construct(
		public readonly array $fields,
		public readonly bool $hasNote,
		public readonly ?string $note,
	) {
	}//end __construct()

	/**
	 * The fields written, plus the note under the note key when one was given.
	 *
	 * @return array<string, mixed>
	 */
	public function given(): array {
		$given = $this->fields;
		if ($this->hasNote === true) {
			$given[RelationDecisionService::NOTE_FIELD] = $this->note;
		}

		return $given;
	}//end given()
}//end class
