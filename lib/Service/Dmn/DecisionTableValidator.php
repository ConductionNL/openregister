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
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * Says, at save time, whether the evaluator could execute a table.
 *
 * Returns problems rather than throwing, so a caller can show every defect
 * in one refusal instead of one per save attempt.
 *
 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
 */
class DecisionTableValidator implements DecisionTableValidatorInterface {

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
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	public function validate(array $table): array {
		$run = new TableValidationRun(table: $table);

		$hitPolicy = strtoupper(trim((string)($table['hitPolicy'] ?? 'UNIQUE')));
		if (in_array($hitPolicy, DecisionTableEvaluator::IMPLEMENTED_HIT_POLICIES, true) === false) {
			$run->problems[] = sprintf(
				'hit policy "%s" is not implemented; the implemented policies are %s',
				$hitPolicy,
				implode(', ', DecisionTableEvaluator::IMPLEMENTED_HIT_POLICIES)
			);
		}

		$inputs = $this->validateColumns(run: $run, side: 'inputs');
		$outputs = $this->validateColumns(run: $run, side: 'outputs');
		$run->columns = new DecisionTableColumns(inputs: $inputs, outputCount: count($outputs));

		$this->validateRules(run: $run);

		return $run->problems;
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
	 * @param TableValidationRun $run  The run: the table, and the problems, appended to.
	 * @param string             $side `inputs` or `outputs`.
	 *
	 * @return array<int, array{name: string, type: string}> The usable columns, with effective types.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `DecisionTableEvaluator::effectiveType()`
	 * is static precisely so validator and evaluator share one normalisation.
	 */
	private function validateColumns(TableValidationRun $run, string $side): array {
		$raw = ($run->table[$side] ?? null);
		if (is_array($raw) === false || $raw === []) {
			$run->problems[] = sprintf('the table declares no %s; it needs at least one', $side);

			return [];
		}

		$columns = [];
		foreach (array_values($raw) as $position => $column) {
			$problem = $this->columnProblem(column: $column, where: sprintf('%s entry %d', $side, $position));
			$name = trim((string)($column['name'] ?? ''));
			if ($problem === null && in_array($name, array_column($columns, 'name'), true) === true) {
				$problem = sprintf('%s name "%s" is declared twice; results are keyed by name, so the columns would collapse', $side, $name);
			}

			if ($problem !== null) {
				$run->problems[] = $problem;
				continue;
			}

			$columns[] = [
				'name' => $name,
				'type' => DecisionTableEvaluator::effectiveType(type: (string)($column['type'] ?? 'string')),
			];
		}//end foreach

		return $columns;
	}//end validateColumns()

	/**
	 * Why one column entry is dropped (not an object, or unnamed), or null
	 * when it is kept. A duplicate name is the caller's check: it knows the
	 * names already declared on this side.
	 *
	 * @param mixed  $column The raw column entry.
	 * @param string $where  `<side> entry <position>`, for the messages.
	 *
	 * @return string|null The problem, or null.
	 */
	private function columnProblem(mixed $column, string $where): ?string {
		if (is_array($column) === false) {
			return sprintf('%s is not an object', $where);
		}

		if (trim((string)($column['name'] ?? '')) === '') {
			return sprintf('%s has no name', $where);
		}

		return null;
	}//end columnProblem()

	/**
	 * Validate the rules: positional alignment, integer priority, and every
	 * input cell executed through the evaluator's grammar.
	 *
	 * @param TableValidationRun $run The run: the table, the validated columns, and the problems, appended to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	private function validateRules(TableValidationRun $run): void {
		$raw = ($run->table['rules'] ?? null);
		if (is_array($raw) === false || $raw === []) {
			$run->problems[] = 'the table declares no rules; a table that can never match decides nothing';

			return;
		}

		foreach (array_values($raw) as $position => $rule) {
			if (is_array($rule) === false) {
				$run->problems[] = sprintf('rule %d is not an object', $position);
				continue;
			}

			$run->ruleId = $this->ruleIdOf(rule: $rule, position: $position);
			$this->validateRule(run: $run, rule: $rule);
		}

	}//end validateRules()

	/**
	 * The rule's trimmed id, or `#<position>` when it has none.
	 *
	 * @param array<string, mixed> $rule     The rule.
	 * @param int                  $position Its position in the rule list.
	 *
	 * @return string
	 */
	private function ruleIdOf(array $rule, int $position): string {
		$ruleId = trim((string)($rule['id'] ?? ''));
		if ($ruleId === '') {
			return ('#' . $position);
		}

		return $ruleId;
	}//end ruleIdOf()

	/**
	 * Validate one rule row.
	 *
	 * @param TableValidationRun   $run  The run: the rule's id, the validated columns, and the problems, appended to.
	 * @param array<string, mixed> $rule The rule row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	private function validateRule(TableValidationRun $run, array $rule): void {
		$inputEntries = $this->entriesOf(rule: $rule, key: 'inputEntries');
		$outputEntries = $this->entriesOf(rule: $rule, key: 'outputEntries');

		$this->checkInputCount(run: $run, inputEntries: $inputEntries);
		$this->checkOutputCount(run: $run, outputEntries: $outputEntries);

		if (array_key_exists('priority', $rule) === true && is_int($rule['priority']) === false) {
			$run->problems[] = sprintf('rule %s has a non-integer priority', $run->ruleId);
		}

		$this->probeInputs(run: $run, inputEntries: $inputEntries);

	}//end validateRule()

	/**
	 * A rule must carry one input entry per declared input.
	 *
	 * @param TableValidationRun $run          The validation in progress, at this rule.
	 * @param array<int, mixed>  $inputEntries The rule's input entries.
	 *
	 * @return void
	 */
	private function checkInputCount(TableValidationRun $run, array $inputEntries): void {
		if (count($inputEntries) !== count($run->columns->inputs)) {
			// A short row would silently wildcard its missing tail: the
			// evaluator reads an absent positional entry as `-`.
			$run->problems[] = sprintf(
				'rule %s has %d input entries for %d declared inputs; a short row would silently wildcard the missing columns',
				$run->ruleId,
				count($inputEntries),
				count($run->columns->inputs)
			);
		}
	}//end checkInputCount()

	/**
	 * A rule must carry one output entry per declared output.
	 *
	 * @param TableValidationRun $run           The validation in progress, at this rule.
	 * @param array<int, mixed>  $outputEntries The rule's output entries.
	 *
	 * @return void
	 */
	private function checkOutputCount(TableValidationRun $run, array $outputEntries): void {
		if (count($outputEntries) !== $run->columns->outputCount) {
			$run->problems[] = sprintf(
				'rule %s has %d output entries for %d declared outputs',
				$run->ruleId,
				count($outputEntries),
				$run->columns->outputCount
			);
		}
	}//end checkOutputCount()

	/**
	 * A rule's positional entries under `$key`, or [] when that is not a list.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param string               $key  `inputEntries` or `outputEntries`.
	 *
	 * @return array<int, mixed>
	 */
	private function entriesOf(array $rule, string $key): array {
		if (is_array($rule[$key] ?? null) === true) {
			return array_values($rule[$key]);
		}

		return [];
	}//end entriesOf()

	/**
	 * Probe every input cell present for a declared input column.
	 *
	 * @param TableValidationRun $run          The run: the rule's id, the validated columns, and the problems, appended to.
	 * @param array<int, mixed>  $inputEntries The rule's input entries.
	 *
	 * @return void
	 */
	private function probeInputs(TableValidationRun $run, array $inputEntries): void {
		foreach ($run->columns->inputs as $columnPosition => $column) {
			if (array_key_exists($columnPosition, $inputEntries) === false) {
				continue;
			}

			$problem = $this->probeCell(expression: (string)$inputEntries[$columnPosition], column: $column);
			if ($problem !== null) {
				$run->problems[] = sprintf('rule %s, %s', $run->ruleId, $problem);
			}
		}

	}//end probeInputs()

	/**
	 * Execute one cell through the evaluator's grammar and say what it
	 * refused. A boolean answer means the cell is executable; whether the
	 * probe matched is deliberately ignored.
	 *
	 * @param string                            $expression The raw cell text.
	 * @param array{name: string, type: string} $column     The column it sits under.
	 *
	 * @return string|null The refusal, naming the column and the cell, or null when the cell executes.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md#requirement-a-table-the-evaluator-cannot-execute-is-refused-at-save
	 */
	private function probeCell(string $expression, array $column): ?string {
		try {
			$this->evaluator->matchesValue(
				expression: $expression,
				subject: new UnaryTestValue(value: self::PROBES[$column['type']], type: $column['type'])
			);
		} catch (DecisionEvaluationException $e) {
			return sprintf(
				'column "%s": the cell "%s" cannot be executed (%s)',
				$column['name'],
				$expression,
				$e->getErrorCode()
			);
		}

		return null;
	}//end probeCell()
}//end class
