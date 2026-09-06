<?php

/**
 * The plan-item lifecycle as a table, not as prose.
 *
 * Ported from the reference implementation
 * (`procest/lib/Service/Cmmn/PlanItemTransitions.php:41-97`), including its
 * one asymmetry: a milestone has exactly two edges, `available -> completed`
 * and `available -> terminated`, because a milestone performs no work and so
 * has nothing to be `enabled` or `active` during. Presence in this table is
 * the ONLY definition of legality; a transition absent from it is refused
 * naming all four facts (item, type, from, to) and is never coerced to a
 * legal neighbour.
 *
 * Pure and stateless: injected as a collaborator so the state machine and
 * its tests share one table rather than two copies that drift.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseTransitionException;

/**
 * The exhaustive per-type edge table and the terminal set.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */
class CasePlanTransitions {

	/**
	 * The edges a stage or a human task may take. No edge leaves a terminal
	 * state, and no edge is a self-loop.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const WORK_ITEM_EDGES = [
		CaseItem::STATE_AVAILABLE => [
			CaseItem::STATE_ENABLED,
			CaseItem::STATE_ACTIVE,
			CaseItem::STATE_DISABLED,
			CaseItem::STATE_TERMINATED,
		],
		CaseItem::STATE_ENABLED => [
			CaseItem::STATE_ACTIVE,
			CaseItem::STATE_DISABLED,
			CaseItem::STATE_TERMINATED,
		],
		CaseItem::STATE_ACTIVE => [
			CaseItem::STATE_COMPLETED,
			CaseItem::STATE_TERMINATED,
		],
		CaseItem::STATE_COMPLETED => [],
		CaseItem::STATE_TERMINATED => [],
		CaseItem::STATE_DISABLED => [],
	];

	/**
	 * A milestone's two edges: the asymmetry the reference keeps at
	 * `PlanItemTransitions.php:80-83`.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const MILESTONE_EDGES = [
		CaseItem::STATE_AVAILABLE => [
			CaseItem::STATE_COMPLETED,
			CaseItem::STATE_TERMINATED,
		],
		CaseItem::STATE_ENABLED => [],
		CaseItem::STATE_ACTIVE => [],
		CaseItem::STATE_COMPLETED => [],
		CaseItem::STATE_TERMINATED => [],
		CaseItem::STATE_DISABLED => [],
	];

	/**
	 * The table, keyed by plan-item type.
	 *
	 * @var array<string, array<string, array<int, string>>>
	 */
	private const TABLE = [
		CaseItem::TYPE_STAGE => self::WORK_ITEM_EDGES,
		CaseItem::TYPE_HUMAN_TASK => self::WORK_ITEM_EDGES,
		CaseItem::TYPE_MILESTONE => self::MILESTONE_EDGES,
	];

	/**
	 * The legal target states of a type from a state.
	 *
	 * @param string $type The plan-item type.
	 * @param string $from The current state.
	 *
	 * @return array<int, string> The legal targets; empty for a terminal state or an unknown type.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function targetsFor(string $type, string $from): array {
		return (self::TABLE[$type][$from] ?? []);
	}//end targetsFor()

	/**
	 * Whether an edge is in the table.
	 *
	 * @param string $type The plan-item type.
	 * @param string $from The current state.
	 * @param string $to The requested state.
	 *
	 * @return boolean True only for an edge present in the table.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function isLegal(string $type, string $from, string $to): bool {
		return in_array($to, $this->targetsFor(type: $type, from: $from), true);
	}//end isLegal()

	/**
	 * Refuse an edge absent from the table, naming all four facts.
	 *
	 * @param CaseItem $item The item being moved.
	 * @param string $to The requested state.
	 *
	 * @return void
	 *
	 * @throws CaseTransitionException Naming item, type, from-state and to-state.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function assertLegal(CaseItem $item, string $to): void {
		$type = (string)$item->getPlanItemType();
		$from = (string)$item->getState();
		if ($this->isLegal(type: $type, from: $from, to: $to) === true) {
			return;
		}

		throw new CaseTransitionException(
			message: sprintf(
				"Plan item '%s' (%s) cannot move from '%s' to '%s': no such transition exists in the lifecycle table.",
				(string)$item->getUuid(),
				$type,
				$from,
				$to
			)
		);
	}//end assertLegal()

	/**
	 * Whether a state is terminal for every type.
	 *
	 * @param string $state The state.
	 *
	 * @return boolean True for completed, terminated and disabled.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function isTerminal(string $state): bool {
		return in_array($state, CaseItem::TERMINAL_STATES, true);
	}//end isTerminal()
}//end class
