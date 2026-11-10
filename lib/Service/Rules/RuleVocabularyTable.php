<?php

/**
 * OpenRegister RuleVocabularyTable
 *
 * The three closed sets of the rules engine's vocabulary, as tables, and the
 * listing a consuming surface renders from them.
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
 * The kinds, verdicts and actions tables behind {@see RuleVocabulary}.
 *
 * RuleVocabulary publishes the words and is read from all over the engine;
 * the tables that describe them, and the listing built from them, live here
 * so the widely read class stays a list of names.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleVocabularyTable {

	/**
	 * Every kind, with the annotation it is read from and what it does.
	 *
	 * `order` is the position the save pipeline evaluates the kind in, and it
	 * is the sort key of the inventory. It is a property of the pipeline, not
	 * of an individual rule: a calculation always runs before a lifecycle
	 * condition sees the object, because the condition reads what it wrote.
	 *
	 * @var array<string, array{order: int, source: string, actions: array<int, string>, description: string}>
	 */
	public const KINDS = [
		RuleVocabulary::KIND_CALCULATION => [
			'order' => 1,
			'source' => 'x-openregister-calculations',
			'actions' => [RuleVocabulary::ACTION_SET_VALUE],
			'description' => 'Derives a value from the object and writes it before the object is stored.',
		],
		RuleVocabulary::KIND_STATE_FIELD_RULE => [
			'order' => 2,
			'source' => 'x-openregister-lifecycle.states',
			'actions' => [RuleVocabulary::ACTION_HIDE_FIELD, RuleVocabulary::ACTION_READ_ONLY_FIELD, RuleVocabulary::ACTION_REQUIRE_FIELD],
			'description' => 'Hides, freezes or requires a property while the object is in one state.',
		],
		RuleVocabulary::KIND_LIFECYCLE_CONDITION => [
			'order' => 3,
			'source' => 'x-openregister-lifecycle.transitions',
			'actions' => [RuleVocabulary::ACTION_REFUSE_TRANSITION],
			'description' => 'Decides whether a transition may proceed, and refuses it when it may not.',
		],
		RuleVocabulary::KIND_ADMINISTERED_VALIDATION => [
			'order' => 4,
			'source' => 'x-openregister-validations',
			'actions' => [RuleVocabulary::ACTION_REFUSE_WRITE],
			'description' => 'Refuses or warns about a write, in the sentence the administrator wrote.',
		],
		RuleVocabulary::KIND_FLOW => [
			// Moved from 4 to 5 so the validation sits in front of it. That is
			// the pipeline's real order, not a preference: a validation refuses
			// BEFORE the object is stored, and a flow runs AFTER it is. `order`
			// is the sort key of the inventory, so this changes where the two
			// appear in a list and nothing else.
			'order' => 5,
			'source' => 'openregister_flow_triggers',
			'actions' => [RuleVocabulary::ACTION_RUN_FLOW],
			'description' => 'Runs a flow after the object is stored.',
		],
	];

	/**
	 * Every verdict, with the sentence a surface renders for it.
	 *
	 * @var array<string, string>
	 */
	public const VERDICTS = [
		RuleVocabulary::VERDICT_FIRED => 'The rule ran and did what it declares.',
		RuleVocabulary::VERDICT_NO_MATCH => 'The rule was evaluated and its condition did not hold.',
		RuleVocabulary::VERDICT_REFUSED => 'The rule held and refused the write it was guarding.',
		RuleVocabulary::VERDICT_ERROR => 'The rule could not be evaluated.',
	];

	/**
	 * Every action, with the sentence a surface renders for it.
	 *
	 * @var array<string, string>
	 */
	public const ACTIONS = [
		RuleVocabulary::ACTION_SET_VALUE => 'Writes a value onto the object being saved.',
		RuleVocabulary::ACTION_HIDE_FIELD => 'Keeps a property out of the rendered object.',
		RuleVocabulary::ACTION_READ_ONLY_FIELD => 'Renders a property but refuses a change to it.',
		RuleVocabulary::ACTION_REQUIRE_FIELD => 'Refuses a save that leaves a property empty.',
		RuleVocabulary::ACTION_REFUSE_TRANSITION => 'Refuses the transition the condition guards.',
		RuleVocabulary::ACTION_REFUSE_WRITE => 'Refuses the write, in the sentence the administrator wrote.',
		RuleVocabulary::ACTION_RUN_FLOW => 'Starts a flow run.',
	];

	/**
	 * The whole vocabulary, in the shape a consuming surface renders.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The three closed sets,
	 *   keyed `kinds`, `verdicts` and `actions`.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function listing(): array {
		$kinds = [];
		foreach (self::KINDS as $kind => $descriptor) {
			$kinds[] = array_merge(['kind' => (string)$kind], $descriptor);
		}

		return [
			'kinds' => $kinds,
			'verdicts' => $this->pairs(table: self::VERDICTS, key: 'verdict'),
			'actions' => $this->pairs(table: self::ACTIONS, key: 'action'),
		];
	}//end listing()

	/**
	 * One closed set of words with their sentences, as a list.
	 *
	 * @param array<string, string> $table The word => sentence table.
	 * @param string                $key   The key the word is listed under.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function pairs(array $table, string $key): array {
		$entries = [];
		foreach ($table as $word => $description) {
			$entries[] = [$key => (string)$word, 'description' => $description];
		}

		return $entries;
	}//end pairs()
}//end class
