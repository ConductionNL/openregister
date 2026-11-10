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

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;
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
	 * Resolves instants, durations and object dates, failing closed.
	 *
	 * @var ScheduledFilterDates
	 */
	private ScheduledFilterDates $dates;

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
		$this->dates  = new ScheduledFilterDates(logger: $this->logger);

	}//end __construct()

	/**
	 * Evaluate the filter against one object's data at one moment.
	 *
	 * @param array<string, mixed>  $filter Filter map per the class docblock.
	 * @param ScheduledFilterTarget $target The object's flat field map and the scan's logical "now".
	 *
	 * @return bool True when every entry matches, false otherwise.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function matchesTarget(array $filter, ScheduledFilterTarget $target): bool {
		if (count($filter) === 0) {
			return true;
		}

		$ast = $this->parsedAst(filter: $filter);
		if ($ast === null) {
			return false;
		}

		return $this->nodeMatches(node: $ast, target: $target);
	}//end matchesTarget()

	/**
	 * The filter's AST, or null (logged) when it does not parse.
	 *
	 * An unexecutable filter is not normal data the way an unparsable date on
	 * one object is — it is a rule that can never fire, so it is reported at
	 * warning level rather than debug, and it matches nothing rather than
	 * everything.
	 *
	 * @param array<string, mixed> $filter Filter map per the class docblock.
	 *
	 * @return array<string, mixed>|null
	 */
	private function parsedAst(array $filter): ?array {
		$parsed = $this->parser->parse(filter: $filter);
		if ($parsed['ast'] === null) {
			$this->logger->warning(
				'ScheduledFilterEvaluator: filter does not parse; the rule matches nothing',
				['errors' => array_column($parsed['errors'], 'message')]
			);
		}

		return $parsed['ast'];
	}//end parsedAst()

	/**
	 * Evaluate one AST node against an object.
	 *
	 * @param array<string, mixed>  $node   The AST node.
	 * @param ScheduledFilterTarget $target The object and the scan moment.
	 *
	 * @return bool True when the node matches.
	 */
	private function nodeMatches(array $node, ScheduledFilterTarget $target): bool {
		$type = (string)($node['type'] ?? '');
		if ($type === 'all') {
			return $this->allMatch(clauses: ($node['clauses'] ?? []), target: $target);
		}

		if ($type === 'any') {
			return $this->anyMatches(clauses: ($node['clauses'] ?? []), target: $target);
		}

		return $this->leafMatches(clause: new ScheduledFilterClause(node: $node, target: $target));
	}//end nodeMatches()

	/**
	 * Whether every clause matches. An empty conjunction matches — an
	 * unconstrained filter selects everything, which is what an empty
	 * `filter` already meant.
	 *
	 * @param array<int, array<string, mixed>> $clauses The clauses.
	 * @param ScheduledFilterTarget            $target  The object and the scan moment.
	 *
	 * @return bool
	 */
	private function allMatch(array $clauses, ScheduledFilterTarget $target): bool {
		foreach ($clauses as $clause) {
			if ($this->nodeMatches(node: $clause, target: $target) === false) {
				return false;
			}
		}

		return true;
	}//end allMatch()

	/**
	 * Whether any clause matches. An empty disjunction does NOT match: "any of
	 * nothing" is false, and treating it as true would silently widen a rule to
	 * the whole table.
	 *
	 * @param array<int, array<string, mixed>> $clauses The clauses.
	 * @param ScheduledFilterTarget            $target  The object and the scan moment.
	 *
	 * @return bool
	 */
	private function anyMatches(array $clauses, ScheduledFilterTarget $target): bool {
		foreach ($clauses as $clause) {
			if ($this->nodeMatches(node: $clause, target: $target) === true) {
				return true;
			}
		}

		return false;
	}//end anyMatches()

	/**
	 * Evaluate one leaf clause, by operator family. Unknown operators are
	 * unreachable (the parser rejects them) and fail closed regardless.
	 *
	 * @param ScheduledFilterClause $clause The leaf clause.
	 *
	 * @return bool True when the clause matches.
	 */
	private function leafMatches(ScheduledFilterClause $clause): bool {
		return match ($clause->operator) {
			'equals', 'notEquals' => $this->equalityMatches(clause: $clause),
			'in', 'notIn' => $this->inMatches(clause: $clause),
			'before', 'after', 'withinNext', 'olderThan' => $this->temporalMatches(clause: $clause),
			default => false,
		};
	}//end leafMatches()

	/**
	 * `equals` / `notEquals`: strict comparison. A missing (null) field
	 * satisfies notEquals for any non-null target.
	 *
	 * @param ScheduledFilterClause $clause The leaf clause.
	 *
	 * @return bool
	 */
	private function equalityMatches(ScheduledFilterClause $clause): bool {
		$equal = ($clause->actual === $clause->operand);
		if ($clause->operator === 'equals') {
			return $equal;
		}

		return ($equal === false);
	}//end equalityMatches()

	/**
	 * `in` / `notIn`. A missing (null) field is not a member of anything, so
	 * it satisfies notIn — the mirror of the notEquals rule.
	 *
	 * @param ScheduledFilterClause $clause The leaf clause.
	 *
	 * @return bool
	 */
	private function inMatches(ScheduledFilterClause $clause): bool {
		if ($clause->operator === 'in') {
			return $this->membershipMatches(actual: $clause->actual, values: (array)$clause->operand);
		}

		return ($clause->actual === null || $this->membershipMatches(actual: $clause->actual, values: (array)$clause->operand) === false);
	}//end inMatches()

	/**
	 * The date operators: instants (`before` / `after`) and durations.
	 *
	 * @param ScheduledFilterClause $clause The leaf clause.
	 *
	 * @return bool
	 */
	private function temporalMatches(ScheduledFilterClause $clause): bool {
		if (in_array($clause->operator, ScheduledFilterGrammar::INSTANT_OPERATORS, true) === true) {
			return $this->instantMatches(clause: $clause);
		}

		return $this->durationMatches(clause: $clause);
	}//end temporalMatches()

	/**
	 * `before` / `after` a resolved instant.
	 *
	 * @param ScheduledFilterClause $clause The leaf clause.
	 *
	 * @return bool
	 */
	private function instantMatches(ScheduledFilterClause $clause): bool {
		$instant   = $this->dates->resolveInstant(clause: $clause);
		$fieldDate = $this->fieldDateIf(ready: $instant !== null, clause: $clause);
		if ($instant === null || $fieldDate === null) {
			return false;
		}

		if ($clause->operator === 'before') {
			return ($fieldDate < $instant);
		}

		return ($fieldDate > $instant);
	}//end instantMatches()

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
	 * Evaluate the two duration-relative operators, `withinNext` and `olderThan`.
	 *
	 * @param ScheduledFilterClause $clause The leaf clause; its operand is the ISO-8601 duration.
	 *
	 * @return bool True when the clause matches.
	 */
	private function durationMatches(ScheduledFilterClause $clause): bool {
		$interval  = $this->dates->parseDuration(clause: $clause);
		$fieldDate = $this->fieldDateIf(ready: $interval !== null, clause: $clause);
		if ($interval === null || $fieldDate === null) {
			return false;
		}

		if ($clause->operator === 'withinNext') {
			// Half-open window: (now, now + duration].
			return ($fieldDate > $clause->now && $fieldDate <= $clause->now->add($interval));
		}

		return ($fieldDate < $clause->now->sub($interval));
	}//end durationMatches()

	/**
	 * The field's date, parsed only once the reference it is compared with
	 * resolved, so a clause logs at most the first thing that failed.
	 *
	 * @param bool                  $ready  Whether the reference resolved.
	 * @param ScheduledFilterClause $clause The leaf clause.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function fieldDateIf(bool $ready, ScheduledFilterClause $clause): ?DateTimeImmutable {
		if ($ready === false) {
			return null;
		}

		return $this->dates->parseDate(value: $clause->actual, field: $clause->field);
	}//end fieldDateIf()
}//end class
