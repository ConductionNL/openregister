<?php

/**
 * How far a task completion may push its run before handing back to the worker.
 *
 * ADR-098 Decision 9 gives the user-task node a budget with exactly three
 * shapes, and this class is the ONE place they are spelled:
 *
 *   0       park the run as due and return; the worker advances it (default)
 *   N       continue for at most N transitions in the completing request
 *   "all"   continue until the run suspends, reaches another user task, or ends
 *
 * 🔴 UNLIMITED IS SPELLED "all" AND NEVER null. In PHP and JSON a null budget
 * is indistinguishable from an absent one at every coercion that matters:
 * `(int)null === 0`, `$x ?? 'all'` fires on both, `empty(null) === empty(0)`,
 * and a definition editor may drop a null key on save. Every one of those
 * turns "unlimited" into "park for the worker" silently, so `null` is REFUSED
 * here, naming itself and stating the spelling (design D-4). `-1` is refused
 * for the same family of reasons (`-1 < 1` reads as "already spent" to any
 * naive bound check) and so is every other string.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use UnexpectedValueException;

/**
 * The validated `advance` budget of a user-task node.
 */
final class FlowAdvanceBudget {

	/**
	 * The one spelling of "no limit".
	 *
	 * @var string
	 */
	public const ALL = 'all';

	/**
	 * Constructor.
	 *
	 * @param integer|null $transitions The transition ceiling; null for unlimited.
	 */
	private function __construct(
		private readonly ?int $transitions,
	) {

	}//end __construct()

	/**
	 * The default: park for the worker.
	 *
	 * @return self A zero budget.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public static function none(): self {
		return new self(transitions: 0);
	}//end none()

	/**
	 * Read a budget off a node configuration.
	 *
	 * The caller passes whether the key was PRESENT separately from its value,
	 * because that distinction is the whole point: absent means `0`, present
	 * and null is a refused value, and `??` cannot tell the two apart.
	 *
	 * @param array<string, mixed> $config The node configuration.
	 * @param string $key The config key holding the budget.
	 *
	 * @return self The budget.
	 *
	 * @throws UnexpectedValueException When the value is null, empty, negative,
	 *                                  fractional or any string but "all".
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public static function fromConfig(array $config, string $key = 'advance'): self {
		if (array_key_exists($key, $config) === false) {
			return self::none();
		}

		return self::fromValue(value: $config[$key]);
	}//end fromConfig()

	/**
	 * Read a budget off a stored value.
	 *
	 * @param mixed $value The stored or configured value.
	 *
	 * @return self The budget.
	 *
	 * @throws UnexpectedValueException When the value is not `0`, a positive
	 *                                  integer or the string "all".
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public static function fromValue(mixed $value): self {
		if ($value === null) {
			throw new UnexpectedValueException(
				'advance is null. A null budget reads as 0 at every PHP and JSON coercion, so it is refused: '
				. 'unlimited is spelled "all", the default is 0, and any other value is a positive number of transitions.'
			);
		}

		if (is_string($value) === true && strtolower(trim($value)) === self::ALL) {
			return new self(transitions: null);
		}

		if (is_bool($value) === false && is_numeric($value) === true) {
			$asFloat = (float)$value;
			$asInt = (int)$value;
			if ($asFloat === (float)$asInt && $asInt >= 0) {
				return new self(transitions: $asInt);
			}
		}

		throw new UnexpectedValueException(
			sprintf(
				'advance %s is not a budget. Use 0 to park the run for the worker, a positive number of transitions, or "all".',
				var_export($value, true)
			)
		);
	}//end fromValue()

	/**
	 * Whether the completing request should advance the run at all.
	 *
	 * @return boolean False for the default zero budget.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public function advancesInRequest(): bool {
		return ($this->transitions !== 0);
	}//end advancesInRequest()

	/**
	 * Whether the budget is "all".
	 *
	 * @return boolean True when the walk runs to its next natural stop.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public function isUnlimited(): bool {
		return ($this->transitions === null);
	}//end isUnlimited()

	/**
	 * The transition ceiling, or null for unlimited.
	 *
	 * @return integer|null The number of transitions the completion may push.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public function transitions(): ?int {
		return $this->transitions;
	}//end transitions()

	/**
	 * The storable spelling: `0`, `N` or `"all"`.
	 *
	 * Written into the node's resume slot at task creation so the completion
	 * listener reads the budget the node was saved with, not one re-derived
	 * from a definition that may since have been edited.
	 *
	 * @return integer|string The stored form.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public function toStored(): int|string {
		if ($this->transitions === null) {
			return self::ALL;
		}

		return $this->transitions;
	}//end toStored()
}//end class
