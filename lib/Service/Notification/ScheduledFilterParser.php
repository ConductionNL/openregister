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
			$key = (string) $key;

			if (ScheduledFilterGrammar::isCombinator($key) === true) {
				$parsed = $this->parseCombinator(
					combinator: $key,
					spec: $spec,
					ruleKey: $ruleKey,
					path: $key,
					depth: 1
				);

				$errors = array_merge($errors, $parsed['errors']);
				if ($parsed['node'] !== null) {
					$clauses[] = $parsed['node'];
				}

				continue;
			}

			$parsed = $this->parseFieldEntry(field: $key, spec: $spec, ruleKey: $ruleKey, path: $key);

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
	 * Parse one `field => spec` entry into a leaf node.
	 *
	 * @param string $field   Field name on the object.
	 * @param mixed  $spec    Scalar, bare list, or operator object.
	 * @param string $ruleKey Notification rule name.
	 * @param string $path    Dotted path for error reporting.
	 *
	 * @return array{node: ?array<string, mixed>, errors: array<int, array<string, mixed>>}
	 */
	private function parseFieldEntry(string $field, $spec, string $ruleKey, string $path): array {
		// Scalar shortcut — strict equality, the historical default.
		if (is_array($spec) === false) {
			return $this->accept(node: ['type' => 'leaf', 'field' => $field, 'operator' => 'equals', 'operand' => $spec]);
		}

		// Bare list — membership over the list.
		if (array_is_list($spec) === true) {
			if ($spec === []) {
				return $this->reject(
				error: $this->error(
							code: 'notification-scheduled-empty-list',
							ruleKey: $ruleKey,
							field: $path,
							value: $spec,
							message: sprintf(
								'Notification "%s" trigger.filter.%s is an empty list; a membership test over no values can never match.',
								$ruleKey,
								$path
							)
						));
			}

			return $this->accept(node: ['type' => 'leaf', 'field' => $field, 'operator' => 'in', 'operand' => $spec]);
		}//end if

		// Operator object.
		return $this->parseOperatorObject(field: $field, spec: $spec, ruleKey: $ruleKey, path: $path);

	}//end parseFieldEntry()

	/**
	 * Parse an operator object into a leaf node.
	 *
	 * @param string               $field   Field name on the object.
	 * @param array<string, mixed> $spec    The operator object.
	 * @param string               $ruleKey Notification rule name.
	 * @param string               $path    Dotted path for error reporting.
	 *
	 * @return array{node: ?array<string, mixed>, errors: array<int, array<string, mixed>>}
	 */
	private function parseOperatorObject(string $field, array $spec, string $ruleKey, string $path): array {
		if (array_key_exists('operator', $spec) === false) {
			// `op` is the spelling openconnector reached for. Name the expected
			// key rather than reporting a generic shape error, because the
			// author's intent is unambiguous and the fix is one word.
			if (array_key_exists('op', $spec) === true) {
				return [
					'node'   => null,
					'errors' => [
						$this->error(
							code: 'notification-scheduled-bad-filter-operator-key',
							ruleKey: $ruleKey,
							field: sprintf('trigger.filter.%s', $path),
							value: $spec['op'],
							message: sprintf(
								'Notification "%s" trigger.filter.%s names the operator under "op"; the expected key is "operator" (got "op": %s).',
								$ruleKey,
								$path,
								var_export($spec['op'], true)
							)
						),
					],
				];
			}

			return $this->reject(
				error: $this->error(
						code: 'notification-scheduled-bad-filter-shape',
						ruleKey: $ruleKey,
						field: sprintf('trigger.filter.%s', $path),
						value: $spec,
						message: sprintf(
							'Notification "%s" trigger.filter.%s is a map without an "operator" key; it is neither a scalar, a list, nor an operator object.',
							$ruleKey,
							$path
						)
					));
		}//end if

		$operator = (string) $spec['operator'];

		if (ScheduledFilterGrammar::isOperator($operator) === false) {
			return [
				'node'   => null,
				'errors' => [
					$this->error(
						code: 'notification-scheduled-bad-filter-operator',
						ruleKey: $ruleKey,
						field: sprintf('trigger.filter.%s.operator', $path),
						value: $operator,
						message: sprintf(
							'Notification "%s" trigger.filter.%s.operator must be one of [%s]; got "%s".',
							$ruleKey,
							$path,
							implode(', ', ScheduledFilterGrammar::OPERATORS),
							$operator
						)
					),
				],
			];
		}

		$operandKey = ScheduledFilterGrammar::operandKey($operator);

		if (array_key_exists($operandKey, $spec) === false) {
			return $this->reject(
				error: $this->error(
						code: 'notification-scheduled-bad-filter-missing-value',
						ruleKey: $ruleKey,
						field: sprintf('trigger.filter.%s.%s', $path, $operandKey),
						value: null,
						message: sprintf(
							'Notification "%s" trigger.filter.%s uses operator "%s" and therefore requires a "%s" key.',
							$ruleKey,
							$path,
							$operator,
							$operandKey
						)
					));
		}

		$operand = $spec[$operandKey];
		$errors  = $this->validateOperand(
			operator: $operator,
			operand: $operand,
			ruleKey: $ruleKey,
			path: $path,
			operandKey: $operandKey
		);

		if (empty($errors) === false) {
			return ['node' => null, 'errors' => $errors];
		}

		return $this->accept(node: ['type' => 'leaf', 'field' => $field, 'operator' => $operator, 'operand' => $operand]);

	}//end parseOperatorObject()

	/**
	 * Check that an operand suits its operator.
	 *
	 * @param string $operator   The operator name.
	 * @param mixed  $operand    The operand as written.
	 * @param string $ruleKey    Notification rule name.
	 * @param string $path       Dotted path for error reporting.
	 * @param string $operandKey Either `value` or `values`.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 */
	private function validateOperand(string $operator, $operand, string $ruleKey, string $path, string $operandKey): array {
		if (in_array($operator, ScheduledFilterGrammar::MEMBERSHIP_OPERATORS, true) === true) {
			return $this->validateMembershipOperand(operator: $operator, operand: $operand, ruleKey: $ruleKey, path: $path);
		}

		if (in_array($operator, ScheduledFilterGrammar::DURATION_OPERATORS, true) === true) {
			return $this->validateDurationOperand(operator: $operator, operand: $operand, ruleKey: $ruleKey, path: $path);
		}

		if (in_array($operator, ScheduledFilterGrammar::INSTANT_OPERATORS, true) === true) {
			return $this->validateInstantOperand(operator: $operator, operand: $operand, ruleKey: $ruleKey, path: $path);
		}

		return $this->validateScalarOperand(
			operator: $operator,
			operand: $operand,
			ruleKey: $ruleKey,
			path: $path,
			operandKey: $operandKey
		);

	}//end validateOperand()

	/**
	 * Check a membership operand is a non-empty list.
	 *
	 * @param string $operator The operator name.
	 * @param mixed  $operand  The operand as written.
	 * @param string $ruleKey  Notification rule name.
	 * @param string $path     Dotted path for error reporting.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 */
	private function validateMembershipOperand(string $operator, $operand, string $ruleKey, string $path): array {
		if (is_array($operand) === true && array_is_list($operand) === true && $operand !== []) {
			return [];
		}

		return [
			$this->error(
				code: 'notification-scheduled-bad-filter-values',
				ruleKey: $ruleKey,
				field: sprintf('trigger.filter.%s.values', $path),
				value: $operand,
				message: sprintf(
					'Notification "%s" trigger.filter.%s uses operator "%s" and therefore requires "values" to be a non-empty list.',
					$ruleKey,
					$path,
					$operator
				)
			),
		];

	}//end validateMembershipOperand()

	/**
	 * Check a duration operand parses as an ISO-8601 duration.
	 *
	 * @param string $operator The operator name.
	 * @param mixed  $operand  The operand as written.
	 * @param string $ruleKey  Notification rule name.
	 * @param string $path     Dotted path for error reporting.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 */
	private function validateDurationOperand(string $operator, $operand, string $ruleKey, string $path): array {
		if (is_string($operand) === true && $this->parseDuration(duration: (string) $operand) !== null) {
			return [];
		}

		return [
			$this->error(
				code: 'notification-scheduled-bad-filter-duration',
				ruleKey: $ruleKey,
				field: sprintf('trigger.filter.%s.value', $path),
				value: $operand,
				message: sprintf(
					'Notification "%s" trigger.filter.%s uses operator "%s" and therefore requires an ISO-8601 duration (e.g. "P7D", "PT24H"); got %s.',
					$ruleKey,
					$path,
					$operator,
					var_export($operand, true)
				)
			),
		];

	}//end validateDurationOperand()

	/**
	 * Check an instant operand resolves.
	 *
	 * @param string $operator The operator name.
	 * @param mixed  $operand  The operand as written.
	 * @param string $ruleKey  Notification rule name.
	 * @param string $path     Dotted path for error reporting.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 */
	private function validateInstantOperand(string $operator, $operand, string $ruleKey, string $path): array {
		if (is_string($operand) === true && $this->isResolvableInstant(value: (string) $operand) === true) {
			return [];
		}

		return [
			$this->error(
				code: 'notification-scheduled-bad-filter-instant',
				ruleKey: $ruleKey,
				field: sprintf('trigger.filter.%s.value', $path),
				value: $operand,
				message: sprintf(
					'Notification "%s" trigger.filter.%s uses operator "%s" and therefore requires "now", an ISO-8601 '
					. 'date/date-time, or a signed ISO-8601 duration ("P7D" future, "-P7D" past); got %s.',
					$ruleKey,
					$path,
					$operator,
					var_export($operand, true)
				)
			),
		];

	}//end validateInstantOperand()

	/**
	 * Check a scalar operand is not an array.
	 *
	 * @param string $operator   The operator name.
	 * @param mixed  $operand    The operand as written.
	 * @param string $ruleKey    Notification rule name.
	 * @param string $path       Dotted path for error reporting.
	 * @param string $operandKey Either `value` or `values`.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the operand suits.
	 */
	private function validateScalarOperand(string $operator, $operand, string $ruleKey, string $path, string $operandKey): array {
		if (is_array($operand) === false) {
			return [];
		}

		return [
			$this->error(
				code: 'notification-scheduled-bad-filter-value',
				ruleKey: $ruleKey,
				field: sprintf('trigger.filter.%s.%s', $path, $operandKey),
				value: $operand,
				message: sprintf(
					'Notification "%s" trigger.filter.%s uses operator "%s" and therefore requires a scalar "value"; got an array.',
					$ruleKey,
					$path,
					$operator
				)
			),
		];

	}//end validateScalarOperand()

	/**
	 * Parse a combinator entry into a nested node.
	 *
	 * @param string $combinator Either `all` or `any`.
	 * @param mixed  $spec       The clause list.
	 * @param string $ruleKey    Notification rule name.
	 * @param string $path       Dotted path for error reporting.
	 * @param int    $depth      Current nesting depth.
	 *
	 * @return array{node: ?array<string, mixed>, errors: array<int, array<string, mixed>>}
	 */
	private function parseCombinator(string $combinator, $spec, string $ruleKey, string $path, int $depth): array {
		if ($depth > ScheduledFilterGrammar::MAX_DEPTH) {
			return $this->reject(
				error: $this->error(
					code: 'notification-scheduled-filter-too-deep',
					ruleKey: $ruleKey,
					field: sprintf('trigger.filter.%s', $path),
					value: $depth,
					message: sprintf(
						'Notification "%s" trigger.filter.%s nests combinators deeper than %d levels.',
						$ruleKey,
						$path,
						ScheduledFilterGrammar::MAX_DEPTH
					)
				)
			);
		}

		if (is_array($spec) === false || array_is_list($spec) === false) {
			return $this->reject(
				error: $this->error(
					code: 'notification-scheduled-bad-combinator',
					ruleKey: $ruleKey,
					field: sprintf('trigger.filter.%s', $path),
					value: $spec,
					message: sprintf(
						'Notification "%s" trigger.filter.%s must be a list of clauses.',
						$ruleKey,
						$path
					)
				)
			);
		}

		$errors  = [];
		$clauses = [];

		foreach ($spec as $index => $clause) {
			$parsed = $this->parseClause(
				clause: $clause,
				ruleKey: $ruleKey,
				path: sprintf('%s[%d]', $path, (int) $index),
				depth: $depth
			);

			$errors  = array_merge($errors, $parsed['errors']);
			$clauses = array_merge($clauses, $parsed['nodes']);
		}//end foreach

		if (empty($errors) === false) {
			return ['node' => null, 'errors' => $errors];
		}

		return $this->accept(node: ['type' => $combinator, 'clauses' => $clauses]);

	}//end parseCombinator()

	/**
	 * Parse one clause inside a combinator.
	 *
	 * A clause is either a nested combinator or a `{field, operator, …}` object.
	 *
	 * @param mixed  $clause  The clause as written.
	 * @param string $ruleKey Notification rule name.
	 * @param string $path    Dotted path for error reporting.
	 * @param int    $depth   Depth of the enclosing combinator.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
	 */
	private function parseClause($clause, string $ruleKey, string $path, int $depth): array {
		if (is_array($clause) === false) {
			return [
				'nodes'  => [],
				'errors' => [
					$this->error(
						code: 'notification-scheduled-bad-clause',
						ruleKey: $ruleKey,
						field: sprintf('trigger.filter.%s', $path),
						value: $clause,
						message: sprintf(
							'Notification "%s" trigger.filter.%s must be a clause object.',
							$ruleKey,
							$path
						)
					),
				],
			];
		}

		// A clause may itself be a nested combinator.
		$nestedKeys = array_intersect(array_keys($clause), ScheduledFilterGrammar::COMBINATORS);
		if (empty($nestedKeys) === false) {
			$errors = [];
			$nodes  = [];

			foreach ($nestedKeys as $nestedKey) {
				$parsed = $this->parseCombinator(
					combinator: (string) $nestedKey,
					spec: $clause[$nestedKey],
					ruleKey: $ruleKey,
					path: sprintf('%s.%s', $path, (string) $nestedKey),
					depth: ($depth + 1)
				);

				$errors = array_merge($errors, $parsed['errors']);
				if ($parsed['node'] !== null) {
					$nodes[] = $parsed['node'];
				}
			}

			return ['nodes' => $nodes, 'errors' => $errors];
		}//end if

		if (array_key_exists('field', $clause) === false || is_string($clause['field']) === false) {
			return [
				'nodes'  => [],
				'errors' => [
					$this->error(
						code: 'notification-scheduled-bad-clause-field',
						ruleKey: $ruleKey,
						field: sprintf('trigger.filter.%s.field', $path),
						value: ($clause['field'] ?? null),
						message: sprintf(
							'Notification "%s" trigger.filter.%s requires a string "field".',
							$ruleKey,
							$path
						)
					),
				],
			];
		}

		$parsed = $this->parseOperatorObject(
			field: (string) $clause['field'],
			spec: $clause,
			ruleKey: $ruleKey,
			path: $path
		);

		$nodes = [];
		if ($parsed['node'] !== null) {
			$nodes[] = $parsed['node'];
		}

		return ['nodes' => $nodes, 'errors' => $parsed['errors']];

	}//end parseClause()

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

	/**
	 * Build one structured error in the shape the validator already reports.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $ruleKey Notification rule name.
	 * @param string $field   Dotted path to the offending key.
	 * @param mixed  $value   The offending value.
	 * @param string $message Human-readable diagnosis.
	 *
	 * @return array<string, mixed> The error entry.
	 */
	private function error(string $code, string $ruleKey, string $field, $value, string $message): array {
		return [
			'code'    => $code,
			'ruleKey' => $ruleKey,
			'field'   => $field,
			'value'   => $value,
			'message' => $message,
		];

	}//end error()

}//end class
