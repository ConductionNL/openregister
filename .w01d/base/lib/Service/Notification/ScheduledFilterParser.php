<?php

/**
 * Parses a scheduled-trigger `filter` annotation into a normalised AST.
 *
 * This is the single gate between what an author writes and what the engine
 * runs. The validator reports the errors this parser produces; the evaluator
 * executes the AST it produces. Neither enumerates operators itself, so a shape
 * the validator accepts is by construction a shape the evaluator can execute —
 * which is precisely what was untrue before: 24 filter entries across three
 * apps were accepted at save time and matched nothing at scan time.
 *
 * The AST is deliberately free of anything time-dependent. A clause holds its
 * operand exactly as written and the evaluator resolves `now` against the scan's
 * single reference instant, so parsing can be cached and, later, compiled to SQL
 * (PERF-3) without the parse result silently ageing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use DateInterval;
use Exception;

/**
 * Turns a raw `filter` map into either an AST or a list of structured errors.
 *
 * The class is complex because the grammar is: four entry forms, eight
 * operators over four operand families, and two nesting combinators. Splitting
 * it further would separate the accept decision from the error that explains a
 * rejection, and it is exactly that separation — the validator deciding one
 * thing and the evaluator another — which let 24 unexecutable filters ship.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class ScheduledFilterParser {

	/**
	 * Parse a filter map.
	 *
	 * @param array<string, mixed> $filter  The raw `trigger.filter` map.
	 * @param string               $ruleKey Notification rule name, for error reporting.
	 *
	 * @return array{ast: ?array<string, mixed>, errors: array<int, array<string, mixed>>}
	 *         `ast` is null when `errors` is non-empty.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function parse(array $filter, string $ruleKey = ''): array {
		$errors  = [];
		$clauses = [];

		foreach ($filter as $key => $spec) {
			$key      = (string) $key;
			$position = new ScheduledFilterPosition(ruleKey: $ruleKey, path: $key, depth: 1, key: $key);

			$parsed   = $this->parseTopLevelEntry(spec: $spec, position: $position);

			$errors = array_merge($errors, $parsed['errors']);
			if ($parsed['node'] !== null) {
				$clauses[] = $parsed['node'];
			}
		}//end foreach

		if (empty($errors) === false) {
			return ['ast' => null, 'errors' => $errors];
		}

		// Top-level entries are ANDed, unchanged from the previous grammar.
		return [
			'ast'    => ['type' => 'all', 'clauses' => $clauses],
			'errors' => [],
		];

	}//end parse()

	/**
	 * Parse one top-level entry: a combinator or a field.
	 *
	 * @param mixed                   $spec     The entry's value.
	 * @param ScheduledFilterPosition $position The entry, keyed by its field or combinator.
	 *
	 * @return array{node: ?array<string, mixed>, errors: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function parseTopLevelEntry($spec, ScheduledFilterPosition $position): array {
		if (ScheduledFilterGrammar::isCombinator($position->key) === true) {
			return $this->parseCombinator(spec: $spec, position: $position);
		}

		return $this->parseFieldEntry(spec: $spec, position: $position);

	}//end parseTopLevelEntry()

	/**
	 * Parse one top-level `field => spec` entry into a leaf node.
	 *
	 * @param mixed                   $spec     Scalar, bare list, or operator object.
	 * @param ScheduledFilterPosition $position The entry, keyed by its field.
	 *
	 * @return array{node: ?array<string, mixed>, errors: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function parseFieldEntry($spec, ScheduledFilterPosition $position): array {
		// Scalar shortcut — strict equality, the historical default.
		if (is_array($spec) === false) {
			return $this->accept(node: ['type' => 'leaf', 'field' => $position->key, 'operator' => 'equals', 'operand' => $spec]);
		}

		// Operator object.
		if (array_is_list($spec) === false) {
			return $this->parseOperatorObject(spec: $spec, position: $position);
		}

		// Bare list — membership over the list.
		if ($spec === []) {
			return $this->reject(
				error: $position->error(
					code: 'notification-scheduled-empty-list',
					issue: [
						'field'   => $position->path,
						'value'   => $spec,
						'message' => $position->message(diagnosis: ' is an empty list; a membership test over no values can never match.'),
					]
				)
			);
		}

		return $this->accept(node: ['type' => 'leaf', 'field' => $position->key, 'operator' => 'in', 'operand' => $spec]);

	}//end parseFieldEntry()

	/**
	 * Parse an operator object into a leaf node.
	 *
	 * @param array<string, mixed>    $spec     The operator object.
	 * @param ScheduledFilterPosition $position The entry, keyed by the field it tests.
	 *
	 * @return array{node: ?array<string, mixed>, errors: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function parseOperatorObject(array $spec, ScheduledFilterPosition $position): array {
		$errors = $this->operatorErrors(spec: $spec, position: $position);
		if (empty($errors) === true) {
			$errors = $this->validateOperand(spec: $spec, position: $position);
		}

		if (empty($errors) === false) {
			return ['node' => null, 'errors' => $errors];
		}

		$operator = (string) $spec['operator'];
		return $this->accept(
			node: [
				'type'     => 'leaf',
				'field'    => $position->key,
				'operator' => $operator,
				'operand'  => $spec[ScheduledFilterGrammar::operandKey($operator)],
			]
		);

	}//end parseOperatorObject()

	/**
	 * Check an operator object names a known operator and carries its operand key.
	 *
	 * @param array<string, mixed>    $spec     The operator object.
	 * @param ScheduledFilterPosition $position The entry.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operator and its key are present.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function operatorErrors(array $spec, ScheduledFilterPosition $position): array {
		if (array_key_exists('operator', $spec) === false) {
			return [$this->missingOperatorError(spec: $spec, position: $position)];
		}

		$operator = (string) $spec['operator'];
		if (ScheduledFilterGrammar::isOperator($operator) === false) {
			return [
				$position->error(
					code: 'notification-scheduled-bad-filter-operator',
					issue: [
						'field'   => $position->field(suffix: '.operator'),
						'value'   => $operator,
						'message' => $position->message(
							diagnosis: sprintf(
								'.operator must be one of [%s]; got "%s".',
								implode(', ', ScheduledFilterGrammar::OPERATORS),
								$operator
							)
						),
					]
				),
			];
		}

		$operandKey = ScheduledFilterGrammar::operandKey($operator);
		if (array_key_exists($operandKey, $spec) === true) {
			return [];
		}

		return [
			$position->error(
				code: 'notification-scheduled-bad-filter-missing-value',
				issue: [
					'field'   => $position->field(suffix: '.'.$operandKey),
					'value'   => null,
					'message' => $position->message(
						diagnosis: sprintf(' uses operator "%s" and therefore requires a "%s" key.', $operator, $operandKey)
					),
				]
			),
		];

	}//end operatorErrors()

	/**
	 * The error for an operator object without an `operator` key.
	 *
	 * `op` is the spelling openconnector reached for. It gets its own error
	 * naming the expected key, because the author's intent is unambiguous and
	 * the fix is one word.
	 *
	 * @param array<string, mixed>    $spec     The operator object.
	 * @param ScheduledFilterPosition $position The entry.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function missingOperatorError(array $spec, ScheduledFilterPosition $position): array {
		if (array_key_exists('op', $spec) === true) {
			return $position->error(
				code: 'notification-scheduled-bad-filter-operator-key',
				issue: [
					'field'   => $position->field(),
					'value'   => $spec['op'],
					'message' => $position->message(
						diagnosis: sprintf(
							' names the operator under "op"; the expected key is "operator" (got "op": %s).',
							var_export($spec['op'], true)
						)
					),
				]
			);
		}

		return $position->error(
			code: 'notification-scheduled-bad-filter-shape',
			issue: [
				'field'   => $position->field(),
				'value'   => $spec,
				'message' => $position->message(
					diagnosis: ' is a map without an "operator" key; it is neither a scalar, a list, nor an operator object.'
				),
			]
		);

	}//end missingOperatorError()

	/**
	 * Check that an operand suits its operator.
	 *
	 * @param array<string, mixed>    $spec     The operator object, with a known operator and its operand key.
	 * @param ScheduledFilterPosition $position The entry.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateOperand(array $spec, ScheduledFilterPosition $position): array {
		$operator = (string) $spec['operator'];

		if (in_array($operator, ScheduledFilterGrammar::MEMBERSHIP_OPERATORS, true) === true) {
			return $this->validateMembershipOperand(spec: $spec, position: $position);
		}

		if (in_array($operator, ScheduledFilterGrammar::DURATION_OPERATORS, true) === true) {
			return $this->validateDurationOperand(spec: $spec, position: $position);
		}

		if (in_array($operator, ScheduledFilterGrammar::INSTANT_OPERATORS, true) === true) {
			return $this->validateInstantOperand(spec: $spec, position: $position);
		}

		return $this->validateScalarOperand(spec: $spec, position: $position);

	}//end validateOperand()

	/**
	 * Check a membership operand is a non-empty list.
	 *
	 * @param array<string, mixed>    $spec     The operator object.
	 * @param ScheduledFilterPosition $position The entry.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateMembershipOperand(array $spec, ScheduledFilterPosition $position): array {
		$operand = $spec['values'];
		if (is_array($operand) === true && array_is_list($operand) === true && $operand !== []) {
			return [];
		}

		return [
			$position->error(
				code: 'notification-scheduled-bad-filter-values',
				issue: [
					'field'   => $position->field(suffix: '.values'),
					'value'   => $operand,
					'message' => $position->message(
						diagnosis: sprintf(
							' uses operator "%s" and therefore requires "values" to be a non-empty list.',
							(string) $spec['operator']
						)
					),
				]
			),
		];

	}//end validateMembershipOperand()

	/**
	 * Check a duration operand parses as an ISO-8601 duration.
	 *
	 * @param array<string, mixed>    $spec     The operator object.
	 * @param ScheduledFilterPosition $position The entry.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateDurationOperand(array $spec, ScheduledFilterPosition $position): array {
		$operand = $spec['value'];
		if (is_string($operand) === true && $this->parseDuration(duration: (string) $operand) !== null) {
			return [];
		}

		return [
			$position->error(
				code: 'notification-scheduled-bad-filter-duration',
				issue: [
					'field'   => $position->field(suffix: '.value'),
					'value'   => $operand,
					'message' => $position->message(
						diagnosis: sprintf(
							' uses operator "%s" and therefore requires an ISO-8601 duration (e.g. "P7D", "PT24H"); got %s.',
							(string) $spec['operator'],
							var_export($operand, true)
						)
					),
				]
			),
		];

	}//end validateDurationOperand()

	/**
	 * Check an instant operand resolves.
	 *
	 * @param array<string, mixed>    $spec     The operator object.
	 * @param ScheduledFilterPosition $position The entry.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateInstantOperand(array $spec, ScheduledFilterPosition $position): array {
		$operand = $spec['value'];
		if (is_string($operand) === true && $this->isResolvableInstant(value: (string) $operand) === true) {
			return [];
		}

		return [
			$position->error(
				code: 'notification-scheduled-bad-filter-instant',
				issue: [
					'field'   => $position->field(suffix: '.value'),
					'value'   => $operand,
					'message' => $position->message(
						diagnosis: sprintf(
							' uses operator "%s" and therefore requires "now", an ISO-8601 '
							.'date/date-time, or a signed ISO-8601 duration ("P7D" future, "-P7D" past); got %s.',
							(string) $spec['operator'],
							var_export($operand, true)
						)
					),
				]
			),
		];

	}//end validateInstantOperand()

	/**
	 * Check a scalar operand is not an array.
	 *
	 * @param array<string, mixed>    $spec     The operator object.
	 * @param ScheduledFilterPosition $position The entry.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateScalarOperand(array $spec, ScheduledFilterPosition $position): array {
		$operator   = (string) $spec['operator'];
		$operandKey = ScheduledFilterGrammar::operandKey($operator);
		$operand    = $spec[$operandKey];
		if (is_array($operand) === false) {
			return [];
		}

		return [
			$position->error(
				code: 'notification-scheduled-bad-filter-value',
				issue: [
					'field'   => $position->field(suffix: '.'.$operandKey),
					'value'   => $operand,
					'message' => $position->message(
						diagnosis: sprintf(' uses operator "%s" and therefore requires a scalar "value"; got an array.', $operator)
					),
				]
			),
		];

	}//end validateScalarOperand()

	/**
	 * Parse a combinator entry into a nested node.
	 *
	 * @param mixed                   $spec     The clause list.
	 * @param ScheduledFilterPosition $position The entry, keyed by its combinator (`all` or `any`).
	 *
	 * @return array{node: ?array<string, mixed>, errors: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function parseCombinator($spec, ScheduledFilterPosition $position): array {
		if ($position->depth > ScheduledFilterGrammar::MAX_DEPTH) {
			return $this->reject(
				error: $position->error(
					code: 'notification-scheduled-filter-too-deep',
					issue: [
						'field'   => $position->field(),
						'value'   => $position->depth,
						'message' => $position->message(
							diagnosis: sprintf(' nests combinators deeper than %d levels.', ScheduledFilterGrammar::MAX_DEPTH)
						),
					]
				)
			);
		}

		if (is_array($spec) === false || array_is_list($spec) === false) {
			return $this->reject(
				error: $position->error(
					code: 'notification-scheduled-bad-combinator',
					issue: [
						'field'   => $position->field(),
						'value'   => $spec,
						'message' => $position->message(diagnosis: ' must be a list of clauses.'),
					]
				)
			);
		}

		$errors  = [];
		$clauses = [];

		foreach ($spec as $index => $clause) {
			$parsed = $this->parseClause(clause: $clause, position: $position->clause(index: (int) $index));

			$errors  = array_merge($errors, $parsed['errors']);
			$clauses = array_merge($clauses, $parsed['nodes']);
		}

		if (empty($errors) === false) {
			return ['node' => null, 'errors' => $errors];
		}

		return $this->accept(node: ['type' => $position->key, 'clauses' => $clauses]);

	}//end parseCombinator()

	/**
	 * Parse one clause inside a combinator.
	 *
	 * A clause is either a nested combinator or a `{field, operator, …}` object.
	 *
	 * @param mixed                   $clause   The clause as written.
	 * @param ScheduledFilterPosition $position The clause, at the depth of its combinator.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function parseClause($clause, ScheduledFilterPosition $position): array {
		if (is_array($clause) === false) {
			return [
				'nodes'  => [],
				'errors' => [
					$position->error(
						code: 'notification-scheduled-bad-clause',
						issue: [
							'field'   => $position->field(),
							'value'   => $clause,
							'message' => $position->message(diagnosis: ' must be a clause object.'),
						]
					),
				],
			];
		}

		// A clause may itself be a nested combinator.
		$nestedKeys = array_intersect(array_keys($clause), ScheduledFilterGrammar::COMBINATORS);
		if (empty($nestedKeys) === false) {
			return $this->parseNestedCombinators(clause: $clause, position: $position);
		}

		if (array_key_exists('field', $clause) === false || is_string($clause['field']) === false) {
			return [
				'nodes'  => [],
				'errors' => [
					$position->error(
						code: 'notification-scheduled-bad-clause-field',
						issue: [
							'field'   => $position->field(suffix: '.field'),
							'value'   => ($clause['field'] ?? null),
							'message' => $position->message(diagnosis: ' requires a string "field".'),
						]
					),
				],
			];
		}

		$parsed = $this->parseOperatorObject(spec: $clause, position: $position->testing(field: (string) $clause['field']));

		$nodes = [];
		if ($parsed['node'] !== null) {
			$nodes[] = $parsed['node'];
		}

		return ['nodes' => $nodes, 'errors' => $parsed['errors']];

	}//end parseClause()

	/**
	 * Parse every combinator a clause names, one level deeper.
	 *
	 * @param array<string, mixed>    $clause   The clause, holding `all` and/or `any`.
	 * @param ScheduledFilterPosition $position The clause.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function parseNestedCombinators(array $clause, ScheduledFilterPosition $position): array {
		$errors = [];
		$nodes  = [];

		foreach (array_intersect(array_keys($clause), ScheduledFilterGrammar::COMBINATORS) as $nestedKey) {
			$parsed = $this->parseCombinator(
				spec: $clause[$nestedKey],
				position: $position->nested(combinator: (string) $nestedKey)
			);

			$errors = array_merge($errors, $parsed['errors']);
			if ($parsed['node'] !== null) {
				$nodes[] = $parsed['node'];
			}
		}

		return ['nodes' => $nodes, 'errors' => $errors];

	}//end parseNestedCombinators()

	/**
	 * Report whether a reference instant can be resolved.
	 *
	 * @param string $value The instant as written.
	 *
	 * @return bool True when `now`, a parseable date, or a signed duration.
	 */
	private function isResolvableInstant(string $value): bool {
		if ($value === ScheduledFilterGrammar::INSTANT_NOW) {
			return true;
		}

		if ($this->parseDuration(duration: $value) !== null) {
			return true;
		}

		return (strtotime($value) !== false);

	}//end isResolvableInstant()

	/**
	 * Parse an ISO-8601 duration, accepting a leading `-` for the past direction.
	 *
	 * `DateInterval::__construct()` rejects a leading minus, so the sign is
	 * stripped and expressed as `invert`.
	 *
	 * @param string $duration The duration as written.
	 *
	 * @return DateInterval|null The interval, or null when unparseable.
	 */
	private function parseDuration(string $duration): ?DateInterval {
		if ($duration === '') {
			return null;
		}

		$negative = false;
		if (str_starts_with($duration, '-') === true) {
			$negative = true;
			$duration = substr($duration, 1);
		}

		try {
			$interval = new DateInterval($duration);
		} catch (Exception $e) {
			return null;
		}

		if ($negative === true) {
			$interval->invert = 1;
		}

		return $interval;

	}//end parseDuration()

	/**
	 * Wrap a parsed node as a successful result.
	 *
	 * @param array<string, mixed> $node The parsed node.
	 *
	 * @return array{node: array<string, mixed>, errors: array<int, array<string, mixed>>}
	 */
	private function accept(array $node): array {
		return ['node' => $node, 'errors' => []];

	}//end accept()

	/**
	 * Wrap one error as a failed result.
	 *
	 * @param array<string, mixed> $error The error entry.
	 *
	 * @return array{node: null, errors: array<int, array<string, mixed>>}
	 */
	private function reject(array $error): array {
		return ['node' => null, 'errors' => [$error]];

	}//end reject()

}//end class
