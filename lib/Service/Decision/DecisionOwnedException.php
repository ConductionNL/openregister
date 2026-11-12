<?php

/**
 * OpenRegister DecisionOwnedException
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

use Exception;

/**
 * Raised when someone other than the decider, a supervisor or an
 * administrator tries to change a decider-owned decision.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */
final class DecisionOwnedException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $refusal The refusal body: error, decision and, unless
	 *                                      hideOthers is on, decidedBy and decidedAt.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function __construct(private readonly array $refusal) {
		parent::__construct(message: 'This decision belongs to '.(string)($refusal['decidedBy'] ?? 'another reviewer').'.');
	}//end __construct()

	/**
	 * The refusal body the API answers with.
	 *
	 * @return array<string, mixed> error, decision and, unless hideOthers is on, decidedBy and decidedAt.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function getRefusal(): array {
		return $this->refusal;
	}//end getRefusal()
}//end class
