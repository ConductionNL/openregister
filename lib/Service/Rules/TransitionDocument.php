<?php

/**
 * "Moved into this status" versus "is in this status" (row 11.44).
 *
 * The ledger note: "Row 11.20 rules read current values, so moved into this
 * status and is in this status are the same condition. The first fires once,
 * the second fires every time anything is saved."
 *
 * 🔑 BEFORE AND AFTER, NOT AN EVENT TYPE (D-3). An event type for "moved into"
 * would need one per property and would compose with nothing. A condition that
 * can address the prior value says the same thing once, in the vocabulary that
 * already exists: `{"var": "$before.status"}` beside `{"var": "$after.status"}`.
 * The save pipeline already holds both states for the audit diff, so the
 * operand is available where it is needed.
 *
 * 🔴 ON A CREATE THERE IS NO BEFORE, AND THAT IS NOT `null`. A condition over
 * the prior value has no answer on a create. Returning false silently means the
 * rule never fires and nobody ever finds out why; substituting `null` means
 * `{"==": [{"var": "$before.status"}, null]}` quietly matches every create.
 * So `$before` is ABSENT on a create, a rule that reads it declares that it
 * does, and attaching such a rule to a create-only trigger is refused at SAVE —
 * in front of the person writing it, rather than at three in the morning.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * The evaluation document with both sides of the write in it.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
class TransitionDocument {

	/**
	 * The envelope holding the values before the write.
	 *
	 * @var string
	 */
	public const BEFORE = '$before';

	/**
	 * The envelope holding the values after it.
	 *
	 * @var string
	 */
	public const AFTER = '$after';

	/**
	 * The declaration a rule carries when its condition reads the prior value.
	 *
	 * @var string
	 */
	public const REQUIRES_PRIOR = 'requiresPrior';

	/**
	 * The triggers that have no prior value.
	 *
	 * @var array<int, string>
	 */
	public const CREATE_TRIGGERS = ['create', 'onCreate', 'beforeCreate', 'afterCreate'];

	/**
	 * Build the document a condition is evaluated against.
	 *
	 * The after values stay at the TOP LEVEL as well as under `$after`, because
	 * every condition written before this change reads `{"var": "status"}` and
	 * means the value being saved. Moving them would silently change the
	 * meaning of every existing rule, which is a migration nobody asked for
	 * dressed up as a feature.
	 *
	 * @param array<string, mixed>      $after  The object as it will be saved.
	 * @param array<string, mixed>|null $before The object as it was, null on a create.
	 *
	 * @return array<string, mixed> The document.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function build(array $after, ?array $before): array {
		$document = $after;
		$document[self::AFTER] = $after;

		// ABSENT on a create, never null and never []. `{"var": "$before.status"}`
		// against an absent envelope resolves to null the way any missing path
		// does, and the declaration below is what stops a rule relying on that.
		if ($before !== null) {
			$document[self::BEFORE] = $before;
		}

		return $document;
	}//end build()

	/**
	 * Whether a condition addresses the value before the write.
	 *
	 * Read from the expression rather than trusted from the declaration, so
	 * the declaration can be CHECKED against the expression instead of merely
	 * believed. A rule that reads `$before` without declaring it is the case
	 * this exists to catch.
	 *
	 * @param mixed $node The condition node.
	 *
	 * @return bool True when it reads the prior value.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function readsPrior(mixed $node): bool {
		if (is_string($node) === true) {
			return (str_starts_with($node, self::BEFORE . '.') === true || $node === self::BEFORE);
		}

		return is_array($node) === true && $this->anyReadsPrior(node: $node);
	}//end readsPrior()

	/**
	 * Whether any key of a node is the prior envelope, or any value reads it.
	 *
	 * @param array<int|string, mixed> $node The condition node.
	 *
	 * @return bool True when it reads the prior value.
	 */
	private function anyReadsPrior(array $node): bool {
		foreach ($node as $key => $value) {
			if ((string)$key === self::BEFORE || $this->readsPrior(node: $value) === true) {
				return true;
			}
		}

		return false;
	}//end anyReadsPrior()

	/**
	 * Why a rule may not be attached to its trigger, or null when it may.
	 *
	 * Two refusals, and the second is the one that matters more. A rule that
	 * DECLARES a prior value and sits on a create is refused, obviously. A rule
	 * that READS one without declaring it is refused too — otherwise the
	 * declaration is decoration, and the first check is a check of a field
	 * nobody has to fill in truthfully.
	 *
	 * @param RuleAttachment $attachment The rule's name, its declaration and the trigger it is attached to.
	 * @param mixed          $node       Its condition.
	 *
	 * @return string|null The reason, naming the rule.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function refusalFor(RuleAttachment $attachment, mixed $node): ?string {
		if ($attachment->requiresPrior() === false) {
			return $this->undeclaredReadRefusal(ruleName: $attachment->ruleName, node: $node);
		}

		return $this->createTriggerRefusal(ruleName: $attachment->ruleName, trigger: $attachment->trigger);
	}//end refusalFor()

	/**
	 * The refusal for a rule that reads the prior value without declaring it.
	 *
	 * @param string $ruleName The rule, for the message.
	 * @param mixed  $node     Its condition.
	 *
	 * @return string|null The reason, or null when the condition does not read it.
	 */
	private function undeclaredReadRefusal(string $ruleName, mixed $node): ?string {
		if ($this->readsPrior(node: $node) === false) {
			return null;
		}

		return sprintf(
			'rule "%s" reads the value before the write but does not declare "%s": declare it, so the trigger can be checked',
			$ruleName,
			self::REQUIRES_PRIOR
		);
	}//end undeclaredReadRefusal()

	/**
	 * The refusal for a rule that declares a prior value on a trigger that has none.
	 *
	 * @param string      $ruleName The rule, for the message.
	 * @param string|null $trigger  The trigger it is attached to.
	 *
	 * @return string|null The reason, or null when the trigger has a prior value.
	 */
	private function createTriggerRefusal(string $ruleName, ?string $trigger): ?string {
		if ($trigger === null || in_array($trigger, self::CREATE_TRIGGERS, true) === false) {
			return null;
		}

		return sprintf(
			'rule "%s" needs the value before the write, and "%s" has none; it would never match rather than failing',
			$ruleName,
			$trigger
		);
	}//end createTriggerRefusal()

	/**
	 * Which operand decided the verdict, for the run log (task 2.3).
	 *
	 * Not "which one was read" but which one the verdict turned on: a rule
	 * whose before and after hold the same value did not turn on the
	 * transition, and a run log saying it did would send somebody looking for
	 * a move that never happened.
	 *
	 * @param mixed                $node     The condition.
	 * @param array<string, mixed> $document The document it was evaluated against.
	 *
	 * @return string One of `transition`, `after` or `unchanged`.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function decidedBy(mixed $node, array $document): string {
		if ($this->readsPrior(node: $node) === false || array_key_exists(self::BEFORE, $document) === false) {
			return 'after';
		}

		if ($this->isUnchanged(before: $document[self::BEFORE], after: ($document[self::AFTER] ?? [])) === true) {
			return 'unchanged';
		}

		return 'transition';
	}//end decidedBy()

	/**
	 * Whether the two sides of the write hold the same pairs.
	 *
	 * The spaceship rather than `===` on purpose: two documents holding the
	 * same pairs in a different key order are the same document, and a save
	 * that re-serialises may hand back the keys in another order. `===` would
	 * read that as a transition and fire every rule on a write that changed
	 * nothing.
	 *
	 * @param mixed $before The values before the write.
	 * @param mixed $after  The values after it.
	 *
	 * @return bool True when both are arrays holding the same pairs.
	 */
	private function isUnchanged(mixed $before, mixed $after): bool {
		return is_array($before) === true && is_array($after) === true && ($before <=> $after) === 0;
	}//end isUnchanged()
}//end class
