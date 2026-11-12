<?php

/**
 * OpenRegister RuleVocabulary
 *
 * The published vocabulary of the rules engine: the kinds of rule a schema can
 * carry, the verdicts an evaluation can reach, and the actions a rule can take.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * One table for the words the rules engine uses about itself.
 *
 * A consumer that renders a rule needs three closed sets: what kind of rule it
 * is reading, what an evaluation can have concluded, and what the rule does
 * when it fires. Publishing them from one class means a leaf app renders a
 * label for a verdict it has never seen rather than falling through to a blank.
 *
 * The condition operand vocabulary is deliberately NOT here. Per D-7 of the
 * change design, a condition is written in the JSON AST whose catalogue is
 * {@see \OCA\OpenRegister\Service\Calculation\OperatorCatalogue}, and a second
 * copy of it here is the drift this class exists to avoid.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleVocabulary {

	/**
	 * A transition's declarative `condition`: may this move proceed.
	 */
	public const KIND_LIFECYCLE_CONDITION = 'lifecycleCondition';

	/**
	 * A state's `fields` block: what is hidden, read only or required in a state.
	 */
	public const KIND_STATE_FIELD_RULE = 'stateFieldRule';

	/**
	 * A declared calculation: a value derived from the object's own data.
	 */
	public const KIND_CALCULATION = 'calculation';

	/**
	 * A check an administrator added, with the sentence it says.
	 */
	public const KIND_ADMINISTERED_VALIDATION = 'administeredValidation';

	/**
	 * A flow triggered by this schema's objects.
	 */
	public const KIND_FLOW = 'flow';

	/**
	 * The rule ran and did what it declares.
	 */
	public const VERDICT_FIRED = 'fired';

	/**
	 * The rule was evaluated and its condition did not hold.
	 */
	public const VERDICT_NO_MATCH = 'no_match';

	/**
	 * The rule held and refused the write it was guarding.
	 */
	public const VERDICT_REFUSED = 'refused';

	/**
	 * The rule could not be evaluated at all.
	 */
	public const VERDICT_ERROR = 'error';

	/**
	 * Every kind, with the annotation it is read from and what it does.
	 *
	 * `order` is the position the save pipeline evaluates the kind in, and the
	 * sort key of the inventory; the table, and why each kind sits where it
	 * does, is {@see RuleVocabularyTable::KINDS}.
	 *
	 * @var array<string, array{order: int, source: string, actions: array<int, string>, description: string}>
	 */
	public const KINDS = RuleVocabularyTable::KINDS;

	/**
	 * Refuses the write outright, in the administrator's own words.
	 */
	public const ACTION_REFUSE_WRITE = 'refuseWrite';

	/**
	 * Writes a value onto the object being saved.
	 */
	public const ACTION_SET_VALUE = 'setValue';

	/**
	 * Keeps a property out of the rendered object.
	 */
	public const ACTION_HIDE_FIELD = 'hideField';

	/**
	 * Renders a property but refuses a change to it.
	 */
	public const ACTION_READ_ONLY_FIELD = 'readOnlyField';

	/**
	 * Refuses a save that leaves a property empty.
	 */
	public const ACTION_REQUIRE_FIELD = 'requireField';

	/**
	 * Refuses the transition the condition guards.
	 */
	public const ACTION_REFUSE_TRANSITION = 'refuseTransition';

	/**
	 * Starts a flow run.
	 */
	public const ACTION_RUN_FLOW = 'runFlow';

	/**
	 * Every verdict, with the sentence a surface renders for it.
	 *
	 * @var array<string, string>
	 */
	public const VERDICTS = RuleVocabularyTable::VERDICTS;

	/**
	 * Every action, with the sentence a surface renders for it.
	 *
	 * @var array<string, string>
	 */
	public const ACTIONS = RuleVocabularyTable::ACTIONS;

	/**
	 * The whole vocabulary, in the shape a consuming surface renders.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The three closed sets,
	 *   keyed `kinds`, `verdicts` and `actions`.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function all(): array {
		return (new RuleVocabularyTable())->listing();
	}//end all()

	/**
	 * The evaluation-order position of a kind.
	 *
	 * An unknown kind sorts last rather than throwing: the inventory is a read,
	 * and a rule kind added by a later change must not make the whole list
	 * unreadable before this table learns about it.
	 *
	 * @param string $kind The rule kind.
	 *
	 * @return int The sort position.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function orderOf(string $kind): int {
		return (self::KINDS[$kind]['order'] ?? 99);
	}//end orderOf()

	/**
	 * Whether a verdict is one the engine can reach.
	 *
	 * @param string $verdict The verdict to check.
	 *
	 * @return bool True when the verdict is in the published set.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function hasVerdict(string $verdict): bool {
		return array_key_exists($verdict, self::VERDICTS);
	}//end hasVerdict()
}//end class
