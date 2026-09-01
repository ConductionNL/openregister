<?php

/**
 * OpenRegister ScheduledFilterEvaluator
 *
 * Evaluates the `filter` block on a scheduled notification trigger
 * against a single object's data.
 *
 * The grammar it accepts is not enumerated here: `ScheduledFilterParser` owns
 * it, and this class walks the AST the parser returns. That indirection is the
 * point — while the grammar lived in two places, the validator accepted shapes
 * this evaluator could not execute, and 24 filter entries across three apps
 * were saved successfully and then matched nothing, silently, for months.
 *
 * Part of the notification-engine-scheduled-conditions change
 * (Phase 1 — filter operator evaluator), extended by
 * notification-scheduled-filter-grammar (membership, instants, combinators).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Evaluates a scheduled-trigger `filter` map against object data.
 *
 * A `filter` is a map whose entries are ANDed. Each entry is a scalar (strict
 * equality), a bare list (membership), an operator object, or one of the
 * reserved combinator keys `all` / `any`. The operator table and the nesting
 * bound live in `ScheduledFilterGrammar`; the accepted shapes are whatever
 * `ScheduledFilterParser` admits, and nothing else.
 *
 * An empty filter map matches. An empty `all` matches; an empty `any` does not —
 * "any of nothing" is false, and the alternative would silently widen a rule to
 * the whole table.
 *
 * Fail-closed throughout, at two different volumes:
 *  - an unparsable date or instant on one object makes that clause not match,
 *    logged at debug: normal data, one row;
 *  - a filter that does not parse at all makes the whole rule match nothing,
 *    logged at WARNING: that is a rule which can never fire, and the previous
 *    behaviour of accepting it quietly is the defect this class was changed to
 *    end.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ScheduledFilterEvaluator {

	/**
	 * Logger for fail-closed diagnostics.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Parses a raw filter map into the AST this evaluator walks.
	 *
	 * @var ScheduledFilterParser
	 */
	private ScheduledFilterParser $parser;

	/**
	 * Construct an evaluator with an optional logger.
	 *
	 * The logger is used to emit debug-level diagnostics when a value cannot
	 * be parsed; production callers should inject the standard Nextcloud
	 * logger, tests may pass a NullLogger.
	 *
	 * @param LoggerInterface|null $logger Logger for fail-closed diagnostics.
	 * @param ScheduledFilterParser|null $parser Parser producing the AST to walk.
	 */
	public function __construct(?LoggerInterface $logger = null, ?ScheduledFilterParser $parser = null) {
		$this->logger = ($logger ?? new NullLogger());
		$this->parser = ($parser ?? new ScheduledFilterParser());

	}//end __construct()

	/**
	 * Evaluate the filter against the given object data.
	 *
	 * @param array<string, mixed> $objectData Flat field map (typically `$object->getObject()`).
	 * @param array<string, mixed> $filter Filter map per the class docblock.
	 * @param DateTimeImmutable $now Logical "now" for the entire scan pass.
	 *
	 * @return bool True when every entry matches, false otherwise.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function matches(array $objectData, array $filter, DateTimeImmutable $now): bool {
		if (count($filter) === 0) {
			return true;
		}

		$parsed = $this->parser->parse(filter: $filter);

		if ($parsed['ast'] === null) {
			// An unexecutable filter is not normal data the way an unparsable
			// date on one object is — it is a rule that can never fire, so it
			// is reported at warning level rather than debug, and it matches
			// nothing rather than everything.
			$this->logger->warning(
				'ScheduledFilterEvaluator: filter does not parse; the rule matches nothing',
				[
					'errors' => array_column($parsed['errors'], 'message'),
				]
			);

			return false;
		}

		return $this->nodeMatches(node: $parsed['ast'], objectData: $objectData, now: $now);
	}//end matches()

	/**
	 * Evaluate one AST node against an object.
	 *
	 * @param array<string, mixed> $node The AST node.
	 * @param array<string, mixed> $objectData The full object data map.
	 * @param DateTimeImmutable $now Logical "now" for the scan pass.
	 *
	 * @return bool True when the node matches.
	 */
	private function nodeMatches(array $node, array $objectData, DateTimeImmutable $now): bool {
		$type = (string)($node['type'] ?? '');

		if ($type === 'all') {
			// An empty conjunction matches — an unconstrained filter selects
			// everything, which is what an empty `filter` already meant.
			foreach (($node['clauses'] ?? []) as $clause) {
				if ($this->nodeMatches(node: $clause, objectData: $objectData, now: $now) === false) {
					return false;
				}
			}

			return true;
		}

		if ($type === 'any') {
			// An empty disjunction does NOT match: "any of nothing" is false,
			// and treating it as true would silently widen a rule to the whole
			// table.
			foreach (($node['clauses'] ?? []) as $clause) {
				if ($this->nodeMatches(node: $clause, objectData: $objectData, now: $now) === true) {
					return true;
				}
			}

			return false;
		}

		return $this->leafMatches(node: $node, objectData: $objectData, now: $now);
	}//end nodeMatches()

	/**
	 * Evaluate one leaf clause against an object.
	 *
	 * @param array<string, mixed> $node The leaf node.
	 * @param array<string, mixed> $objectData The full object data map.
	 * @param DateTimeImmutable $now Logical "now" for the clause.
	 *
	 * @return bool True when the clause matches.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  one arm per operator; the
	 * grammar has eight, and a dispatch table would hide which operators exist
	 * from anyone reading the evaluator.
	 */
	private function leafMatches(array $node, array $objectData, DateTimeImmutable $now): bool {
		$field    = (string)($node['field'] ?? '');
		$operator = (string)($node['operator'] ?? '');
		$operand  = ($node['operand'] ?? null);
		$actual   = ($objectData[$field] ?? null);

		switch ($operator) {
			case 'equals':
				return $actual === $operand;
			case 'notEquals':
				// Missing/null field satisfies notEquals for any non-null target.
				if ($actual === null && $operand !== null) {
					return true;
				}

				return $actual !== $operand;
			case 'in':
				return $this->membershipMatches(actual: $actual, values: (array)$operand);
			case 'notIn':
				// Missing/null field is not a member of anything, so it
				// satisfies notIn — the mirror of the notEquals rule above.
				if ($actual === null) {
					return true;
				}

				return ($this->membershipMatches(actual: $actual, values: (array)$operand) === false);
			case 'before':
			case 'after':
				$instant = $this->resolveInstant(value: (string)$operand, field: $field, now: $now);
				if ($instant === null) {
					return false;
				}

				$fieldDate = $this->parseDate(value: $actual, field: $field);
				if ($fieldDate === null) {
					return false;
				}

				if ($operator === 'before') {
					return ($fieldDate < $instant);
				}

				return ($fieldDate > $instant);
			case 'withinNext':
			case 'olderThan':
				return $this->durationMatches(
					operator: $operator,
					operand: $operand,
					actual: $actual,
					field: $field,
					now: $now
				);
			default:
				// Unreachable: the parser rejects unknown operators before the
				// evaluator ever sees them. Fail closed regardless.
				return false;
		}//end switch
	}//end leafMatches()

	/**
	 * Test strict membership, treating an array-valued field as an intersection.
	 *
	 * A multi-select field holding ["a","b"] is "in" ["b","c"] because the sets
	 * overlap — the useful reading for tags and multi-value enums.
	 *
	 * @param mixed $actual The object's value.
	 * @param array<int, mixed> $values The candidate values.
	 *
	 * @return bool True when the value is a member, or overlaps the list.
	 */
	private function membershipMatches($actual, array $values): bool {
		if (is_array($actual) === true) {
			foreach ($actual as $item) {
				if (in_array($item, $values, true) === true) {
					return true;
				}
			}

			return false;
		}

		return in_array($actual, $values, true);
	}//end membershipMatches()

	/**
	 * Evaluate the two duration-relative operators.
	 *
	 * @param string $operator Either `withinNext` or `olderThan`.
	 * @param mixed $operand The ISO-8601 duration.
	 * @param mixed $actual The object's value.
	 * @param string $field The field name, for diagnostics.
	 * @param DateTimeImmutable $now Logical "now".
	 *
	 * @return bool True when the clause matches.
	 */
	private function durationMatches(string $operator, $operand, $actual, string $field, DateTimeImmutable $now): bool {
		$interval = $this->parseDuration(duration: (string)$operand, field: $field, operator: $operator);
		if ($interval === null) {
			return false;
		}

		$fieldDate = $this->parseDate(value: $actual, field: $field);
		if ($fieldDate === null) {
			return false;
		}

		if ($operator === 'withinNext') {
			// Half-open window: (now, now + duration].
			$upper = $now->add($interval);

			return ($fieldDate > $now && $fieldDate <= $upper);
		}

		$threshold = $now->sub($interval);

		return ($fieldDate < $threshold);
	}//end durationMatches()

	/**
	 * Resolve a reference instant for `before` / `after`.
	 *
	 * Accepts `now`, an absolute ISO-8601 date/date-time, or a signed ISO-8601
	 * duration measured from now (`P7D` future, `-P7D` past). The sign is
	 * mandatory for the past direction: `before "P7D"` reads equally naturally
	 * as "a week from now" and "a week ago", and an ambiguous date operator in a
	 * reminder engine is how a thousand wrong emails get sent.
	 *
	 * @param string $value The instant as written.
	 * @param string $field The field name, for diagnostics.
	 * @param DateTimeImmutable $now Logical "now".
	 *
	 * @return DateTimeImmutable|null The instant, or null when unresolvable.
	 */
	private function resolveInstant(string $value, string $field, DateTimeImmutable $now): ?DateTimeImmutable {
		if ($value === 'now') {
			return $now;
		}

		$negative = false;
		$duration = $value;
		if (str_starts_with($duration, '-') === true) {
			$negative = true;
			$duration = substr($duration, 1);
		}

		try {
			$interval = new DateInterval($duration);

			if ($negative === true) {
				return $now->sub($interval);
			}

			return $now->add($interval);
		} catch (Exception $e) {
			// Not a duration — fall through to absolute-date parsing.
		}

		return $this->parseDate(value: $value, field: $field);
	}//end resolveInstant()


	/**
	 * Parse an ISO-8601 DateInterval string. Logs + returns null on failure.
	 *
	 * @param string $duration ISO-8601 duration ("PT24H", "P30D", ...).
	 * @param string $field Field name for diagnostics only.
	 * @param string $operator Operator label for diagnostics only.
	 *
	 * @return DateInterval|null
	 */
	private function parseDuration(string $duration, string $field, string $operator): ?DateInterval {
		if ($duration === '') {
			$this->logger->debug(
				'ScheduledFilterEvaluator: empty duration (fail-closed)',
				['field' => $field, 'operator' => $operator]
			);
			return null;
		}

		try {
			return new DateInterval($duration);
		} catch (Exception $e) {
			$this->logger->debug(
				'ScheduledFilterEvaluator: unparsable duration (fail-closed)',
				[
					'field' => $field,
					'operator' => $operator,
					'value' => $duration,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}

	}//end parseDuration()

	/**
	 * Parse an object-data date value. Accepts strings only; non-string
	 * (null, bool, array, etc.) → null + debug log. Empty string → null.
	 *
	 * @param mixed $value Raw value from object data.
	 * @param string $field Field name for diagnostics only.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseDate($value, string $field): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			$this->logger->debug(
				'ScheduledFilterEvaluator: missing or non-string date (fail-closed)',
				['field' => $field]
			);
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception $e) {
			$this->logger->debug(
				'ScheduledFilterEvaluator: unparsable object date (fail-closed)',
				[
					'field' => $field,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}

	}//end parseDate()
}//end class
