<?php

/**
 * OpenRegister DMN Decision Engine
 *
 * Pure evaluation of a decision-table definition against an inputs map.
 * No OpenRegister, HTTP, or database dependency — a deterministic function
 * of (decisionTable, inputs) -> outputs. Never silently defaults: every
 * ambiguous or invalid situation surfaces as a typed
 * {@see DecisionEvaluationException}.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * Evaluates a decisionTable definition against a runtime inputs map.
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */
class DecisionTableEvaluator {

	/**
	 * The hit policies this evaluator implements.
	 *
	 * PRIORITY is here because openbuild's evaluator had it and dossiq's did
	 * not. ADR-065 names that explicitly: consolidating without it would be a
	 * capability REGRESSION dressed up as a consolidation, since openbuild's
	 * tables can already use it. This list is the union of the two, not the
	 * intersection.
	 *
	 * Public so that {@see DecisionTableValidator} refuses exactly the
	 * policies this class refuses. A second list would drift; the constant
	 * cannot.
	 *
	 * @var array<int, string>
	 */
	public const IMPLEMENTED_HIT_POLICIES = ['UNIQUE', 'FIRST', 'COLLECT', 'PRIORITY', 'ANY'];

	/**
	 * Common spellings of the declared column types, mapped onto this
	 * evaluator's own vocabulary.
	 *
	 * The fleet's tables were authored against two different vocabularies:
	 * openbuild's shipped credit-approval table declares `integer` for
	 * `applicantAge`, which is not one of {@see UnaryTestEvaluator::VALID_TYPES},
	 * so it falls back to `string`.
	 *
	 * Measured, that fallback costs nothing for numbers or dates — the unary-test
	 * grammar parses its operand and compares numerically, so `>=18` against 25
	 * matches under either type, and ISO dates compare correctly as strings. The
	 * numeric and date aliases below are therefore alignment, not a bug fix.
	 *
	 * The boolean alias IS a bug fix: a real PHP `true` coerced to a string
	 * becomes `"1"`, which does not match a cell reading `true`, so a column
	 * declaring `bool` rather than `boolean` silently stops matching. That is the
	 * one case where the fallback changes the answer instead of the spelling.
	 *
	 * Aliasing is additive: a type this map does not know still falls back to
	 * `string` exactly as before.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_ALIASES = [
		'int'       => 'number',
		'integer'   => 'number',
		'long'      => 'number',
		'float'     => 'number',
		'double'    => 'number',
		'decimal'   => 'number',
		'bool'      => 'boolean',
		'datetime'  => 'date',
		'timestamp' => 'date',
	];

	/**
	 * Constructor.
	 *
	 * The evaluator is a pure, stateless collaborator; the default keeps the
	 * engine directly constructible (`new DecisionEngine()`) while the
	 * Nextcloud container autowires the concrete class when resolved via DI.
	 *
	 * @param UnaryTestEvaluator $evaluator The rule-cell expression evaluator.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly UnaryTestEvaluator $evaluator = new UnaryTestEvaluator(),
	) {
	}//end __construct()

	/**
	 * Evaluate a decision table.
	 *
	 * @param array<string, mixed> $decisionTable The decision table definition
	 *                                            (`inputs`, `outputs`, `rules`, `hitPolicy`).
	 * @param array<string, mixed> $inputs Caller-supplied input values, keyed by input name.
	 *
	 * @return array{outputs: array<string, mixed>, matchedRuleIds: array<int, string>, hitPolicy: string}
	 *
	 * @throws DecisionEvaluationException `unknown_input`, `missing_input`, `type_mismatch`,
	 *                                     `invalid_expression`, `no_rule_matched`,
	 *                                     `hit_policy_violation`, `hit_policy_not_implemented`.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	public function evaluate(array $decisionTable, array $inputs): array {
		$declaredInputs = self::normaliseFields(fields: ($decisionTable['inputs'] ?? []));
		$declaredOutputs = self::normaliseFields(fields: ($decisionTable['outputs'] ?? []));
		$hitPolicy = strtoupper((string)($decisionTable['hitPolicy'] ?? 'UNIQUE'));
		if (in_array($hitPolicy, self::IMPLEMENTED_HIT_POLICIES, true) === false) {
			throw new DecisionEvaluationException(errorCode: 'hit_policy_not_implemented', details: ['hitPolicy' => $hitPolicy]);
		}

		$coercedInputs = $this->resolveInputs(declaredInputs: $declaredInputs, inputs: $inputs);
		$matched = $this->matchingRules(decisionTable: $decisionTable, coercedInputs: $coercedInputs);

		return $this->applyHitPolicy(hitPolicy: $hitPolicy, match: new RuleMatch(rules: $matched, declaredOutputs: $declaredOutputs));
	}//end evaluate()

	/**
	 * The rules whose every input entry matches the coerced inputs, in declaration order.
	 *
	 * @param array<string, mixed> $decisionTable The decision table definition.
	 * @param array<string, mixed> $coercedInputs Coerced runtime values, keyed by name.
	 *
	 * @return array<int, array<string, mixed>> The matching rules.
	 *
	 * @throws DecisionEvaluationException `invalid_expression`/`type_mismatch` with rule context.
	 */
	private function matchingRules(array $decisionTable, array $coercedInputs): array {
		$declaredInputs = self::normaliseFields(fields: ($decisionTable['inputs'] ?? []));
		$inputs = new ResolvedInputs(declared: $declaredInputs, coerced: $coercedInputs);
		$matched = [];
		foreach ($this->rulesOf(decisionTable: $decisionTable) as $index => $rule) {
			$cells = ['id' => ($rule['id'] ?? $index), 'inputEntries' => ($rule['inputEntries'] ?? null)];
			if ($this->ruleMatches(cells: $cells, inputs: $inputs) === true) {
				$matched[] = $rule;
			}
		}

		return $matched;
	}//end matchingRules()

	/**
	 * The table's rules that are objects, keyed by their declared position.
	 *
	 * The keys are kept: a refusal names a rule without an id by that position.
	 *
	 * @param array<string, mixed> $decisionTable The decision table definition.
	 *
	 * @return array<int|string, array<string, mixed>> The declared rules; none when `rules` is not a list.
	 */
	private function rulesOf(array $decisionTable): array {
		if (is_array($decisionTable['rules'] ?? null) === true) {
			return array_filter($decisionTable['rules'], 'is_array');
		}

		return [];
	}//end rulesOf()

	/**
	 * Validate the caller's inputs against the declared inputs and coerce
	 * each to its declared type.
	 *
	 * @param array<int, array{name: string, type: string}> $declaredInputs Declared inputs.
	 * @param array<string, mixed> $inputs Caller-supplied values.
	 *
	 * @return array<string, mixed> Coerced values keyed by input name.
	 *
	 * @throws DecisionEvaluationException `unknown_input`, `missing_input`, `type_mismatch`.
	 */
	private function resolveInputs(array $declaredInputs, array $inputs): array {
		$declaredNames = array_map(static fn (array $input): string => $input['name'], $declaredInputs);

		foreach (array_keys($inputs) as $key) {
			if (in_array($key, $declaredNames, true) === false) {
				throw new DecisionEvaluationException(errorCode: 'unknown_input', details: ['key' => $key]);
			}
		}

		$coerced = [];
		foreach ($declaredInputs as $declared) {
			$name = $declared['name'];
			if (array_key_exists($name, $inputs) === false) {
				throw new DecisionEvaluationException(errorCode: 'missing_input', details: ['name' => $name]);
			}

			$coerced[$name] = $this->evaluator->coerce(value: $inputs[$name], type: $declared['type']);
		}

		return $coerced;
	}//end resolveInputs()

	/**
	 * Check whether every input entry on a rule matches the coerced inputs.
	 *
	 * @param array{id: mixed, inputEntries: mixed} $cells  The rule's id (its position when it has none) and input entries.
	 * @param ResolvedInputs                       $inputs The declared inputs and their coerced runtime values.
	 *
	 * @return bool
	 *
	 * @throws DecisionEvaluationException `invalid_expression`/`type_mismatch` (re-thrown with rule context).
	 */
	private function ruleMatches(array $cells, ResolvedInputs $inputs): bool {
		$entries = [];
		if (is_array($cells['inputEntries']) === true) {
			$entries = $cells['inputEntries'];
		}

		foreach ($inputs->declared as $position => $declared) {
			$expression = (string)($entries[$position] ?? '-');
			$subject = new UnaryTestValue(value: $inputs->coerced[$declared['name']], type: $declared['type']);

			try {
				if ($this->evaluator->matchesValue(expression: $expression, subject: $subject) === false) {
					return false;
				}
			} catch (DecisionEvaluationException $e) {
				throw new DecisionEvaluationException(
					errorCode: $e->getErrorCode(),
					details: array_merge($e->getDetails(), ['ruleId' => $cells['id'], 'input' => $declared['name']]),
				);
			}
		}

		return true;
	}//end ruleMatches()

	/**
	 * Refuse an ANY table whose matching rules disagree.
	 *
	 * @param RuleMatch $match The matching rules (at least one) and the declared outputs.
	 *
	 * @return void
	 *
	 * @throws DecisionEvaluationException `hit_policy_violation` when they differ.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	private function assertAllOutputsAgree(RuleMatch $match): void {
		$first = $match->outputsOf(rule: $match->rules[0]);
		foreach ($match->rules as $rule) {
			if ($match->outputsOf(rule: $rule) !== $first) {
				throw new DecisionEvaluationException(
					errorCode: 'hit_policy_violation',
					details: ['hitPolicy' => 'ANY', 'matchedRuleIds' => $match->ids()],
				);
			}
		}
	}//end assertAllOutputsAgree()

	/**
	 * Every matching rule's value, per declared output.
	 *
	 * COLLECT is the one policy whose result is a LIST rather than a value, so
	 * it is built apart from the single-winner path instead of inside it.
	 *
	 * @param RuleMatch $match The matching rules and the declared outputs.
	 *
	 * @return array<string, array<int, mixed>> The collected outputs.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	private function collectOutputs(RuleMatch $match): array {
		$outputs = [];
		foreach ($match->declaredOutputs as $position => $declared) {
			$outputs[$declared['name']] = array_map(
				static fn (array $rule): mixed => ($rule['outputEntries'][$position] ?? null),
				$match->rules,
			);
		}

		return $outputs;
	}//end collectOutputs()

	/**
	 * Which matching rule wins, for the single-winner policies.
	 *
	 * PRIORITY takes the highest `priority`; everything else takes the first in
	 * declaration order. Ties are not an error under PRIORITY — DMN says the
	 * output with the highest priority is taken, and two rules may legitimately
	 * share one — so declaration order breaks them, which makes the outcome
	 * deterministic rather than dependent on array iteration.
	 *
	 * @param string $hitPolicy The hit policy.
	 * @param array<int, array<string, mixed>> $matchedRules The matching rules, in declaration order.
	 *
	 * @return array<string, mixed> The winning rule.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	private function selectWinner(string $hitPolicy, array $matchedRules): array {
		if ($hitPolicy === 'PRIORITY') {
			return $this->highestPriority(matchedRules: $matchedRules);
		}

		return $matchedRules[0];

	}//end selectWinner()

	/**
	 * The matched rule with the highest `priority`, ties broken by order.
	 *
	 * A rule that declares no priority is treated as 0, so a table that mixes
	 * prioritised and unprioritised rules behaves predictably instead of
	 * depending on whether the key happens to exist.
	 *
	 * @param array<int, array<string, mixed>> $matchedRules The matching rules, in declaration order.
	 *
	 * @return array<string, mixed> The winning rule.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	private function highestPriority(array $matchedRules): array {
		$winner = $matchedRules[0];
		$best = (int) ($winner['priority'] ?? 0);

		foreach ($matchedRules as $rule) {
			$priority = (int) ($rule['priority'] ?? 0);
			// STRICTLY greater, so an equal priority leaves the earlier rule in
			// place and declaration order is the tie-break.
			if ($priority > $best) {
				$winner = $rule;
				$best = $priority;
			}
		}

		return $winner;

	}//end highestPriority()

	/**
	 * Apply the hit policy to the set of matched rules and build the outputs.
	 *
	 * @param string    $hitPolicy One of {@see IMPLEMENTED_HIT_POLICIES}.
	 * @param RuleMatch $match     The matching rules and the declared outputs.
	 *
	 * @return array{outputs: array<string, mixed>, matchedRuleIds: array<int, string>, hitPolicy: string}
	 *
	 * @throws DecisionEvaluationException `no_rule_matched`, `hit_policy_violation`.
	 */
	private function applyHitPolicy(string $hitPolicy, RuleMatch $match): array {
		if ($hitPolicy === 'COLLECT') {
			return ['outputs' => $this->collectOutputs(match: $match), 'matchedRuleIds' => $match->ids(), 'hitPolicy' => $hitPolicy];
		}

		$this->assertSingleWinner(hitPolicy: $hitPolicy, match: $match);
		$winner = $this->selectWinner(hitPolicy: $hitPolicy, matchedRules: $match->rules);
		$winnerId = (string)($winner['id'] ?? 0);

		return ['outputs' => $match->outputsOf(rule: $winner), 'matchedRuleIds' => [$winnerId], 'hitPolicy' => $hitPolicy];
	}//end applyHitPolicy()

	/**
	 * Refuse a single-winner match the hit policy does not allow.
	 *
	 * ANY: every matching rule must agree. DMN says a table declaring ANY
	 * asserts that overlapping rules produce the SAME output, so a
	 * disagreement is a fault in the table, not a choice to make silently.
	 * openbuild's evaluator treated `any` as `collect` and returned a list;
	 * that is a different answer of a different shape, and consolidating it
	 * unexamined would have been the quiet regression this whole exercise
	 * exists to avoid.
	 *
	 * @param string    $hitPolicy A hit policy other than COLLECT.
	 * @param RuleMatch $match     The matching rules and the declared outputs.
	 *
	 * @return void
	 *
	 * @throws DecisionEvaluationException `no_rule_matched`, `hit_policy_violation`.
	 */
	private function assertSingleWinner(string $hitPolicy, RuleMatch $match): void {
		if (count($match->rules) === 0) {
			throw new DecisionEvaluationException(errorCode: 'no_rule_matched');
		}

		if ($hitPolicy === 'UNIQUE' && count($match->rules) > 1) {
			throw new DecisionEvaluationException(errorCode: 'hit_policy_violation', details: ['matchedRuleIds' => $match->ids()]);
		}

		if ($hitPolicy === 'ANY') {
			$this->assertAllOutputsAgree(match: $match);
		}
	}//end assertSingleWinner()

	/**
	 * The type a declared column type actually evaluates under.
	 *
	 * Applies {@see TYPE_ALIASES} and the string fallback in one place.
	 * Public because {@see DecisionTableValidator} probes every rule cell
	 * with a value of the column's effective type; if it normalised types
	 * itself, the validator and the evaluator could disagree about what a
	 * column means, which is the drift this method exists to prevent.
	 *
	 * @param string $type The declared column type, any spelling.
	 *
	 * @return string One of {@see UnaryTestEvaluator::VALID_TYPES}.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	public static function effectiveType(string $type): string {
		$lower = strtolower($type);
		$lower = (self::TYPE_ALIASES[$lower] ?? $lower);
		if (in_array($lower, UnaryTestEvaluator::VALID_TYPES, true) === false) {
			return 'string';
		}

		return $lower;
	}//end effectiveType()

	/**
	 * Normalise a decision table's `inputs`/`outputs` array into a clean
	 * positional list of `{name, type}`.
	 *
	 * @param array<int, mixed> $fields Raw `inputs`/`outputs` array.
	 *
	 * @return array<int, array{name: string, type: string}>
	 */
	private static function normaliseFields(array $fields): array {
		$result = [];
		foreach ($fields as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$result[] = ['name' => $name, 'type' => self::effectiveType(type: (string)($field['type'] ?? 'string'))];
		}

		return $result;
	}//end normaliseFields()
}//end class
