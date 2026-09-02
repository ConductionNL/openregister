<?php

/**
 * OpenRegister decision-table validator.
 *
 * Structural and grammar validation of a decision-table definition, for the
 * save path. The grammar half does not parse anything itself: every rule
 * cell is EXECUTED through {@see UnaryTestEvaluator::matches()} with a probe
 * value of the column's effective type, so the accepted grammar is the
 * executable grammar by construction. A parallel parser here would be a
 * second opinion that can drift, and a cell it wrongly accepted would fail
 * at run time in a scheduled flow at 03:00 instead of in the editor.
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
 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * Says, at save time, whether the evaluator could execute a table.
 *
 * Returns problems rather than throwing, so a caller can show every defect
 * in one refusal instead of one per save attempt.
 *
 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */
class DecisionTableValidator {

	/**
	 * One already-coerced probe value per effective type, used to exercise
	 * every rule cell through the evaluator's own grammar. The probe's VALUE
	 * is irrelevant — a cell may match it or not — only the parse matters:
	 * `invalid_expression` and `type_mismatch` surface authoring errors, a
	 * boolean answer means the grammar executed.
	 *
	 * @var array<string, string|float|bool|int>
	 */
	private const PROBES = [
		'string'  => '',
		'number'  => 0.0,
		'boolean' => false,
		'date'    => 0,
	];

	/**
	 * Constructor.
	 *
	 * @param UnaryTestEvaluator $evaluator The grammar being validated against, never re-implemented.
	 */
	public function __construct(
		private readonly UnaryTestEvaluator $evaluator = new UnaryTestEvaluator(),
	) {
	}//end __construct()

	/**
	 * Validate a decision-table definition.
	 *
	 * @param array<string, mixed> $table The table definition (`hitPolicy`, `inputs`, `outputs`, `rules`).
	 *
	 * @return array<int, string> Problems, each naming the offending part. Empty means the evaluator can execute the table.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	public function validate(array $table): array {
		$problems = [];

		$hitPolicy = strtoupper(trim((string)($table['hitPolicy'] ?? 'UNIQUE')));
		if (in_array($hitPolicy, DecisionTableEvaluator::IMPLEMENTED_HIT_POLICIES, true) === false) {
			$problems[] = sprintf(
				'hit policy "%s" is not implemented; the implemented policies are %s',
				$hitPolicy,
				implode(', ', DecisionTableEvaluator::IMPLEMENTED_HIT_POLICIES)
			);
		}

		$inputs = $this->validateColumns(raw: ($table['inputs'] ?? null), side: 'inputs', problems: $problems);
		$outputs = $this->validateColumns(raw: ($table['outputs'] ?? null), side: 'outputs', problems: $problems);

		$this->validateRules(
			raw: ($table['rules'] ?? null),
			inputs: $inputs,
			outputCount: count($outputs),
			problems: $problems
		);

		return $problems;
	}//end validate()

	/**
	 * Validate one side's column declarations.
	 *
	 * An empty side is refused: a table with no inputs matches nothing in
	 * particular and a table with no outputs decides nothing — both are the
	 * silent no-op shape. A duplicate name is refused because evaluation
	 * results are keyed by name, so two columns sharing one would silently
	 * collapse into a single answer.
	 *
	 * @param mixed $raw The declared `inputs` or `outputs`.
	 * @param string $side `inputs` or `outputs`, for the messages.
	 * @param array<int, string> $problems Collected problems, appended to.
	 *
	 * @return array<int, array{name: string, type: string}> The usable columns, with effective types.
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	private function validateColumns(mixed $raw, string $side, array &$problems): array {
		if (is_array($raw) === false || $raw === []) {
			$problems[] = sprintf('the table declares no %s; it needs at least one', $side);

			return [];
		}

		$columns = [];
		$seen = [];
		foreach (array_values($raw) as $position => $column) {
			if (is_array($column) === false) {
				$problems[] = sprintf('%s entry %d is not an object', $side, $position);
				continue;
			}

			$name = trim((string)($column['name'] ?? ''));
			if ($name === '') {
				$problems[] = sprintf('%s entry %d has no name', $side, $position);
				continue;
			}

			if (in_array($name, $seen, true) === true) {
				$problems[] = sprintf('%s name "%s" is declared twice; results are keyed by name, so the columns would collapse', $side, $name);
				continue;
			}

			$seen[] = $name;
			$columns[] = [
				'name' => $name,
				'type' => DecisionTableEvaluator::effectiveType(type: (string)($column['type'] ?? 'string')),
			];
		}//end foreach

		return $columns;
	}//end validateColumns()

	/**
	 * Validate the rules: positional alignment, integer priority, and every
	 * input cell executed through the evaluator's grammar.
	 *
	 * @param mixed $raw The declared `rules`.
	 * @param array<int, array{name: string, type: string}> $inputs The declared inputs, positionally.
	 * @param int $outputCount The declared output count.
	 * @param array<int, string> $problems Collected problems, appended to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	private function validateRules(mixed $raw, array $inputs, int $outputCount, array &$problems): void {
		if (is_array($raw) === false || $raw === []) {
			$problems[] = 'the table declares no rules; a table that can never match decides nothing';

			return;
		}

		foreach (array_values($raw) as $position => $rule) {
			if (is_array($rule) === false) {
				$problems[] = sprintf('rule %d is not an object', $position);
				continue;
			}

			$ruleId = trim((string)($rule['id'] ?? '')) !== '' ? trim((string)$rule['id']) : ('#' . $position);
			$this->validateRule(rule: $rule, ruleId: $ruleId, inputs: $inputs, outputCount: $outputCount, problems: $problems);
		}

	}//end validateRules()

	/**
	 * Validate one rule row.
	 *
	 * @param array<string, mixed> $rule The rule row.
	 * @param string $ruleId The rule's id or position, for the messages.
	 * @param array<int, array{name: string, type: string}> $inputs The declared inputs, positionally.
	 * @param int $outputCount The declared output count.
	 * @param array<int, string> $problems Collected problems, appended to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	private function validateRule(array $rule, string $ruleId, array $inputs, int $outputCount, array &$problems): void {
		$inputEntries = is_array($rule['inputEntries'] ?? null) === true ? array_values($rule['inputEntries']) : [];
		$outputEntries = is_array($rule['outputEntries'] ?? null) === true ? array_values($rule['outputEntries']) : [];

		if (count($inputEntries) !== count($inputs)) {
			// A short row would silently wildcard its missing tail: the
			// evaluator reads an absent positional entry as `-`.
			$problems[] = sprintf(
				'rule %s has %d input entries for %d declared inputs; a short row would silently wildcard the missing columns',
				$ruleId,
				count($inputEntries),
				count($inputs)
			);
		}

		if (count($outputEntries) !== $outputCount) {
			$problems[] = sprintf(
				'rule %s has %d output entries for %d declared outputs',
				$ruleId,
				count($outputEntries),
				$outputCount
			);
		}

		if (array_key_exists('priority', $rule) === true && is_int($rule['priority']) === false) {
			$problems[] = sprintf('rule %s has a non-integer priority', $ruleId);
		}

		foreach ($inputs as $columnPosition => $column) {
			if (array_key_exists($columnPosition, $inputEntries) === false) {
				continue;
			}

			$this->probeCell(
				expression: (string)$inputEntries[$columnPosition],
				column: $column,
				ruleId: $ruleId,
				problems: $problems
			);
		}

	}//end validateRule()

	/**
	 * Execute one cell through the evaluator's grammar and record what it
	 * refused. A boolean answer means the cell is executable; whether the
	 * probe matched is deliberately ignored.
	 *
	 * @param string $expression The raw cell text.
	 * @param array{name: string, type: string} $column The column it sits under.
	 * @param string $ruleId The rule's id, for the messages.
	 * @param array<int, string> $problems Collected problems, appended to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	private function probeCell(string $expression, array $column, string $ruleId, array &$problems): void {
		try {
			$this->evaluator->matches(
				expression: $expression,
				value: self::PROBES[$column['type']],
				type: $column['type']
			);
		} catch (DecisionEvaluationException $e) {
			$problems[] = sprintf(
				'rule %s, column "%s": the cell "%s" cannot be executed (%s)',
				$ruleId,
				$column['name'],
				$expression,
				$e->getErrorCode()
			);
		}

	}//end probeCell()
}//end class
