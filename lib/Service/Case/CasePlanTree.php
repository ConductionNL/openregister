<?php

/**
 * One case plan, read once and questioned many times.
 *
 * An in-memory view over the rows of ONE object's plan, built from a single
 * indexed read (`CaseItemMapper::findByObject()`). It answers the structural
 * questions the evaluator, the completion rule and the authorization service
 * ask (children, ancestors, "is this key terminal", the plan settings), and
 * it writes nothing.
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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;

/**
 * Structural reads over one plan's rows.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One method per structural
 * question the evaluator, the completion rule and the authorization ask;
 * the tree is a read-only query vocabulary, like a mapper.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */
class CasePlanTree {

	/**
	 * Rows by id.
	 *
	 * @var array<int, CaseItem>
	 */
	private array $byId = [];

	/**
	 * Row ids by parent id (0 for the root).
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $childIds = [];

	/**
	 * Row ids by item key, in realisation order.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $keyRows = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, CaseItem> $items The plan's rows.
	 */
	public function __construct(array $items) {
		foreach ($items as $item) {
			$id = (int)$item->getId();
			$this->byId[$id] = $item;
			$this->childIds[(int)($item->getParentItemId() ?? 0)][] = $id;
			$this->keyRows[(string)$item->getItemKey()][] = $id;
		}

	}//end __construct()

	/**
	 * Every row.
	 *
	 * @return array<int, CaseItem> The rows, keyed by id.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function all(): array {
		return $this->byId;
	}//end all()

	/**
	 * A row by id.
	 *
	 * @param int|null $id The row id.
	 *
	 * @return CaseItem|null The row, or null.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function byId(?int $id): ?CaseItem {
		if ($id === null) {
			return null;
		}

		return ($this->byId[$id] ?? null);
	}//end byId()

	/**
	 * The direct children of a stage (or the roots for null).
	 *
	 * @param int|null $parentId The stage's row id, or null for the plan root.
	 *
	 * @return array<int, CaseItem> The children.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function children(?int $parentId): array {
		$rows = [];
		foreach (($this->childIds[(int)($parentId ?? 0)] ?? []) as $id) {
			$rows[] = $this->byId[$id];
		}

		return $rows;
	}//end children()

	/**
	 * Every descendant of a stage, depth first.
	 *
	 * @param int $parentId The stage's row id.
	 *
	 * @return array<int, CaseItem> The descendants.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function descendants(int $parentId): array {
		$rows = [];
		foreach ($this->children(parentId: $parentId) as $child) {
			$rows[] = $child;
			foreach ($this->descendants(parentId: (int)$child->getId()) as $grandchild) {
				$rows[] = $grandchild;
			}
		}

		return $rows;
	}//end descendants()

	/**
	 * The parent of a row, or null at the root.
	 *
	 * @param CaseItem $item The row.
	 *
	 * @return CaseItem|null The parent stage.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function parentOf(CaseItem $item): ?CaseItem {
		return $this->byId(id: $item->getParentItemId());
	}//end parentOf()

	/**
	 * A row's ancestors, nearest first.
	 *
	 * @param CaseItem $item The row.
	 *
	 * @return array<int, CaseItem> The ancestors.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function ancestors(CaseItem $item): array {
		$chain = [];
		$current = $this->parentOf(item: $item);
		$guard = 0;
		while ($current !== null && $guard < 1000) {
			$chain[] = $current;
			$current = $this->parentOf(item: $current);
			$guard++;
		}

		return $chain;
	}//end ancestors()

	/**
	 * Whether a child may become actionable: its parent is `active`, or it
	 * has no parent.
	 *
	 * @param CaseItem $item The row.
	 *
	 * @return boolean True when the containing stage is active or absent.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function isParentActive(CaseItem $item): bool {
		$parent = $this->parentOf(item: $item);
		if ($parent === null) {
			return $item->getParentItemId() === null;
		}

		return $parent->getState() === CaseItem::STATE_ACTIVE;
	}//end isParentActive()

	/**
	 * Every realisation row of one item key.
	 *
	 * @param string $key The item key.
	 *
	 * @return array<int, CaseItem> The rows, oldest first.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function rowsForKey(string $key): array {
		$rows = [];
		foreach (($this->keyRows[$key] ?? []) as $id) {
			$rows[] = $this->byId[$id];
		}

		return $rows;
	}//end rowsForKey()

	/**
	 * Whether any realisation of a key is in a state. Terminal states are
	 * monotonic, so for those "has occurred" and "is currently so" coincide.
	 *
	 * @param string $key The item key.
	 * @param string $state The state.
	 *
	 * @return boolean True when some row of that key is in that state.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function keyHasState(string $key, string $state): bool {
		foreach ($this->rowsForKey(key: $key) as $row) {
			if ($row->getState() === $state) {
				return true;
			}
		}

		return false;
	}//end keyHasState()

	/**
	 * Whether an ITEM (all realisations of a key) is terminal: every row is
	 * terminal AND the repetition rule is exhausted.
	 *
	 * @param CaseItem $item Any row of the item.
	 *
	 * @return boolean True when nothing of that key can still move.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function isItemTerminal(CaseItem $item): bool {
		$rows = $this->rowsForKey(key: (string)$item->getItemKey());
		$latest = $item;
		foreach ($rows as $row) {
			if ($row->isInTerminalState() === false) {
				return false;
			}

			if ((int)$row->getRealisationCount() >= (int)$latest->getRealisationCount()) {
				$latest = $row;
			}
		}

		return $this->repetitionExhausted(item: $latest);
	}//end isItemTerminal()

	/**
	 * Whether a row's repetition rule allows no further realisation.
	 *
	 * A row without a rule is exhausted after its one realisation. A row
	 * that ended other than `completed` is exhausted too: a terminated or
	 * disabled item does not repeat.
	 *
	 * @param CaseItem $item The latest row of the item.
	 *
	 * @return boolean True when no further realisation may be created.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function repetitionExhausted(CaseItem $item): bool {
		$rule = $item->getRepetition();
		if (is_array($rule) === false || isset($rule['max']) === false) {
			return true;
		}

		if ($item->getState() !== CaseItem::STATE_COMPLETED) {
			return true;
		}

		return (int)$item->getRealisationCount() >= (int)$rule['max'];
	}//end repetitionExhausted()

	/**
	 * The stage-completion rule: every REQUIRED child item is terminal AND no
	 * child row is `active` AND at least one required child exists.
	 *
	 * The last clause is the `$mandatoryFound` guard of the reference
	 * (`PlanItemTree.php:98-117`): a stage with only optional children must
	 * NOT auto-complete on activation, and the only thing separating "all
	 * required children are terminal" from "there are no required children"
	 * is that flag.
	 *
	 * @param CaseItem $stage The stage.
	 *
	 * @return boolean True when the stage may auto-complete.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function stageMayComplete(CaseItem $stage): bool {
		$mandatoryFound = false;
		foreach ($this->children(parentId: (int)$stage->getId()) as $child) {
			if ($child->getState() === CaseItem::STATE_ACTIVE) {
				return false;
			}

			if ($child->getRequired() !== true) {
				continue;
			}

			$mandatoryFound = true;
			if ($this->isItemTerminal(item: $child) === false) {
				return false;
			}
		}

		return $mandatoryFound;
	}//end stageMayComplete()

	/**
	 * The state of every key: the sentry document's `case.items` map. For a
	 * repeating item the latest realisation's state is reported.
	 *
	 * @return array<string, string> key => state.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function stateMap(): array {
		$map = [];
		foreach ($this->keyRows as $key => $ids) {
			$latest = null;
			foreach ($ids as $id) {
				$row = $this->byId[$id];
				if ($latest === null || (int)$row->getRealisationCount() >= (int)$latest->getRealisationCount()) {
					$latest = $row;
				}
			}

			if ($latest !== null) {
				$map[(string)$key] = (string)$latest->getState();
			}
		}

		return $map;
	}//end stateMap()

	/**
	 * The plan-level settings, carried on every row and frozen at creation.
	 *
	 * @return array<string, mixed> The settings; empty for a plan with none.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function settings(): array {
		foreach ($this->byId as $row) {
			$settings = $row->getPlanSettings();
			if (is_array($settings) === true && $settings !== []) {
				return $settings;
			}
		}

		return [];
	}//end settings()
}//end class
