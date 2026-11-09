<?php

/**
 * OpenRegister ObjectTreeBounds
 *
 * Decides where the object tree walk stops: depth, node budget and the end of a climb; holds no state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Decides where the object tree walk stops: depth, node budget and the end of a climb; holds no state.
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */
final class ObjectTreeBounds {
	/**
	 * The depth a walk goes to: the declared bound, or the one asked for within it and at least 1.
	 *
	 * @param int|null $asked    The depth asked for.
	 * @param int      $declared The declared bound.
	 *
	 * @return int The depth.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function effectiveDepth(int|null $asked, int $declared): int {
		if ($asked === null) {
			return $declared;
		}

		return min(max(1, $asked), $declared);
	}//end effectiveDepth()

	/**
	 * Whether a level's read ends the walk, and why; null when the walk goes on.
	 *
	 * @param array{children: array<string, string>, overflow: bool}|null $read The level's read.
	 *
	 * @return array{done: bool, truncatedBy: string|null}|null The stop.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function levelStop(array|null $read): array|null {
		if ($read === null) {
			return ['done' => true, 'truncatedBy' => ObjectTreeService::TRUNCATED_UNREADABLE];
		}

		if ($read['overflow'] === true) {
			return ['done' => true, 'truncatedBy' => ObjectTreeService::TRUNCATED_CAP];
		}

		if ($read['children'] === []) {
			return ['done' => true, 'truncatedBy' => null];
		}

		return null;
	}//end levelStop()

	/**
	 * The unseen rows of a bounded read, cut to the budget, and whether the read overflowed it.
	 *
	 * @param array<string, string> $rows   Child uuid => parent uuid, as read.
	 * @param array<string, mixed>  $seen   The uuids already in the answer.
	 * @param int                   $budget The children the cap still admits.
	 *
	 * @return array{children: array<string, string>, overflow: bool} The children.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function withinBudget(array $rows, array $seen, int $budget): array {
		// Counted before the seen ones are dropped: a row that is a cycle
		// still used up a place of the bounded read, so it may hide a wider level.
		$overflow = (count($rows) > $budget);
		$children = array_diff_key($rows, $seen);
		if ($overflow === true) {
			$children = array_slice($children, 0, $budget, true);
		}

		return ['children' => $children, 'overflow' => $overflow];
	}//end withinBudget()

	/**
	 * How a climb ends: at a parent that cannot be shown, or at one past the depth bound.
	 *
	 * @param array<string, ObjectEntity>                               $chain The ancestors so far.
	 * @param array{object: ObjectEntity|null, truncatedBy: string|null} $step  The step that ended it.
	 *
	 * @return array{chain: array<string, ObjectEntity>, truncatedBy: string|null, topParent: string|null} The climb.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function climbEnd(array $chain, array $step): array {
		if ($step['object'] === null) {
			return ['chain' => $chain, 'truncatedBy' => $step['truncatedBy'], 'topParent' => null];
		}

		return ['chain' => $chain, 'truncatedBy' => ObjectTreeService::TRUNCATED_DEPTH, 'topParent' => (string)$step['object']->getUuid()];
	}//end climbEnd()

	/**
	 * The uuid a parent property holds, or null.
	 *
	 * @param mixed $value The stored value: a uuid, or a reference object carrying one.
	 *
	 * @return string|null The uuid.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function reference(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['uuid'] ?? ($value['id'] ?? ($value['value'] ?? null)));
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end reference()
}//end class
