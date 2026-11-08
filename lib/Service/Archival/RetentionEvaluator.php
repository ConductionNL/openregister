<?php

/**
 * OpenRegister RetentionEvaluator
 *
 * Orchestrator that maps a `(annotation, row, createdAt)` triple to the
 * row's effective retention duration, matched rule index, and absolute
 * expiry instant. First-match-wins across `retention.rules[]`, falling
 * back to `retention.default` when no rule matches.
 *
 * Used by:
 *   - `ArchivalRetentionTask` (cron) to decide which rows are past
 *     retention and queue them for deletion.
 *   - `ObjectEntity::jsonSerialize()` (via the renderer) to surface the
 *     `_retention` block on read so the UI can show *why* a row is kept.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Maps an archival annotation + a row + its creation timestamp to the
 * effective retention metadata for that row.
 *
 * Stateless apart from the injected logger; safe to instantiate per-call
 * in hot paths if needed (the cron re-uses one instance per run).
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
 */
final class RetentionEvaluator {

	/**
	 * Condition DSL evaluator used to match rules against a row.
	 *
	 * @var RetentionConditionEvaluator
	 */
	private RetentionConditionEvaluator $conditionEvaluator;

	/**
	 * Logger used for malformed-condition warnings.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param RetentionConditionEvaluator|null $conditionEvaluator Condition DSL evaluator (defaults to a fresh instance).
	 * @param LoggerInterface|null $logger Logger for malformed-condition warnings (defaults to NullLogger).
	 */
	public function __construct(
		?RetentionConditionEvaluator $conditionEvaluator = null,
		?LoggerInterface $logger = null,
	) {
		$this->conditionEvaluator = ($conditionEvaluator ?? new RetentionConditionEvaluator());
		$this->logger = ($logger ?? new NullLogger());

	}//end __construct()

	/**
	 * Compute the effective retention for a row.
	 *
	 * @param array<string, mixed> $annotation Full `x-openregister-archival` block from the schema's configuration.
	 * @param array<string, mixed> $row Row data keyed by field name (output of the row's hydration).
	 * @param DateTimeInterface $createdAt The row's `_created` timestamp.
	 *
	 * @return array{
	 *     effectiveRetention: string,
	 *     matchedRule: int|null,
	 *     expiresAt: string,
	 *     aggregationLevel?: string,
	 *     useRestriction?: array{type: string, description?: string},
	 *     temporalCoverage?: array{type: string, start: string, end?: string}
	 * } Effective retention duration (ISO-8601), matched rule index (or null
	 *   when fallback to default), absolute expiry as ATOM-formatted string,
	 *   and the archival facts the schema declares, each present only when it
	 *   is established for THIS row.
	 *
	 * @throws InvalidArgumentException When the annotation has no usable retention.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
	 */
	public function evaluate(array $annotation, array $row, DateTimeInterface $createdAt): array {
		$retention = $this->retentionBlock(annotation: $annotation);
		$matched = $this->firstMatchingRule(rules: ($retention['rules'] ?? []), row: $row);

		$matchedRule = null;
		$effectiveDur = $retention['default'];
		if ($matched !== null) {
			$matchedRule = $matched['index'];
			$effectiveDur = $matched['retention'];
		}

		$expiresAt = $this->addDuration(createdAt: $createdAt, duration: $effectiveDur)->format(DateTimeInterface::ATOM);
		$evaluation = ['effectiveRetention' => $effectiveDur, 'matchedRule' => $matchedRule, 'expiresAt' => $expiresAt];

		return array_merge($evaluation, $this->declaredFacts(annotation: $annotation, row: $row));
	}//end evaluate()

	/**
	 * The annotation's retention block, refused when it has no usable default.
	 *
	 * @param array<string, mixed> $annotation Full `x-openregister-archival` block.
	 *
	 * @return array<string, mixed> The retention block, its `default` a non-empty string.
	 *
	 * @throws InvalidArgumentException When the annotation has no usable retention.
	 */
	private function retentionBlock(array $annotation): array {
		$retention = ($annotation['retention'] ?? null);
		if (is_array($retention) === false) {
			throw new InvalidArgumentException(
				'RetentionEvaluator: annotation has no `retention` block; validate at schema-save time.'
			);
		}

		$default = ($retention['default'] ?? null);
		if (is_string($default) === false || $default === '') {
			throw new InvalidArgumentException(
				'RetentionEvaluator: annotation.retention.default is missing or not a string.'
			);
		}

		return $retention;
	}//end retentionBlock()

	/**
	 * The first rule whose condition matches the row.
	 *
	 * @param mixed                $rules The annotation's `retention.rules`.
	 * @param array<string, mixed> $row   Row data keyed by field name.
	 *
	 * @return array{index: int, retention: string}|null The matching rule, or null when none matches.
	 */
	private function firstMatchingRule(mixed $rules, array $row): ?array {
		if (is_array($rules) === false) {
			return null;
		}

		foreach ($rules as $index => $rule) {
			$ruleRet = $this->retentionIfMatches(index: $index, rule: $rule, row: $row);
			if ($ruleRet !== null) {
				return ['index' => (int)$index, 'retention' => $ruleRet];
			}
		}

		return null;
	}//end firstMatchingRule()

	/**
	 * A rule's retention when its condition matches the row, or null.
	 *
	 * A rule that is not an array, or lacks a string condition or retention, is
	 * skipped. A malformed condition is skipped with a warning: the cron must not
	 * crash on a single malformed rule (spec D-Scenario "Malformed condition" of
	 * the RetentionConditionEvaluator requirement).
	 *
	 * @param int|string           $index The rule's index, for the warning.
	 * @param mixed                $rule  The rule.
	 * @param array<string, mixed> $row   Row data keyed by field name.
	 *
	 * @return string|null The rule's retention, or null when it does not match.
	 */
	private function retentionIfMatches(int|string $index, mixed $rule, array $row): string|null {
		$condition = $this->ruleField(rule: $rule, field: 'condition');
		$ruleRet = $this->ruleField(rule: $rule, field: 'retention');
		if ($condition === null || $ruleRet === null) {
			return null;
		}

		if ($this->conditionMatches(index: $index, condition: $condition, row: $row) === true) {
			return $ruleRet;
		}

		return null;
	}//end retentionIfMatches()

	/**
	 * Whether a rule's condition matches the row; a malformed one is logged and does not.
	 *
	 * @param int|string           $index     The rule's index, for the warning.
	 * @param string               $condition The condition.
	 * @param array<string, mixed> $row       Row data keyed by field name.
	 *
	 * @return bool True when it matches.
	 */
	private function conditionMatches(int|string $index, string $condition, array $row): bool {
		try {
			return $this->conditionEvaluator->evaluate($condition, $row);
		} catch (InvalidArgumentException $error) {
			$this->logger->warning('Skipping malformed retention condition: ' . $error->getMessage(), ['rule_index' => $index]);
			return false;
		}
	}//end conditionMatches()

	/**
	 * One string field of a rule, or null when the rule or the field is not usable.
	 *
	 * @param mixed  $rule  The rule.
	 * @param string $field The field name.
	 *
	 * @return string|null The field's value.
	 */
	private function ruleField(mixed $rule, string $field): string|null {
		if (is_array($rule) === false || is_string(($rule[$field] ?? null)) === false) {
			return null;
		}

		return $rule[$field];
	}//end ruleField()

	/**
	 * The archival facts the schema declares, resolved for this row.
	 *
	 * `aggregationLevel` and `useRestriction` are literals: the same for every
	 * row of the schema, which is what they are. `temporalCoverage` is not: it
	 * names date PROPERTIES, and the dates come off the row, so a schema says
	 * WHERE its records carry their period rather than asserting one period for
	 * all of them.
	 *
	 * A fact is returned only when it is established for this row. A declared
	 * temporalCoverage whose start property is missing or empty on this record
	 * yields nothing, so the export omits the element rather than emitting a
	 * half one. That matches how UnestablishedValues treats the rest of the
	 * block: present when established, absent otherwise.
	 *
	 * @param array<string, mixed> $annotation Full `x-openregister-archival` block.
	 * @param array<string, mixed> $row Row data keyed by field name.
	 *
	 * @return array<string, mixed> The established facts, keyed in the abstract English vocabulary.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	private function declaredFacts(array $annotation, array $row): array {
		$level = ($annotation['aggregationLevel'] ?? null);
		if ($this->isText(value: $level) === false) {
			$level = null;
		}

		$facts = [
			'aggregationLevel' => $level,
			'useRestriction' => $this->useRestriction(restriction: ($annotation['useRestriction'] ?? null)),
			'temporalCoverage' => $this->coverageForRow(coverage: ($annotation['temporalCoverage'] ?? null), row: $row),
		];

		return array_filter($facts, static fn (mixed $fact): bool => $fact !== null);
	}//end declaredFacts()

	/**
	 * The declared use restriction, its description kept only when it says something.
	 *
	 * @param mixed $restriction The annotation's `useRestriction` block.
	 *
	 * @return array{type: string, description?: string}|null The restriction, or null when none is declared.
	 */
	private function useRestriction(mixed $restriction): array|null {
		if (is_array($restriction) === false || is_string(($restriction['type'] ?? null)) === false) {
			return null;
		}

		$resolved = ['type' => $restriction['type']];
		if (is_string(($restriction['description'] ?? null)) === true && $restriction['description'] !== '') {
			$resolved['description'] = $restriction['description'];
		}

		return $resolved;
	}//end useRestriction()

	/**
	 * Read the declared temporal coverage off this row.
	 *
	 * @param mixed $coverage The annotation's `temporalCoverage` block.
	 * @param array<string, mixed> $row Row data keyed by field name.
	 *
	 * @return array{type: string, start: string, end?: string}|null The coverage, or null when this row does not carry it.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	private function coverageForRow(mixed $coverage, array $row): array|null {
		if (is_array($coverage) === false) {
			return null;
		}

		$type = ($coverage['type'] ?? null);
		$start = $this->rowDate(row: $row, property: ($coverage['startProperty'] ?? null));
		if ($this->isText(value: $type) === false || $start === null) {
			return null;
		}

		$end = $this->rowDate(row: $row, property: ($coverage['endProperty'] ?? null));

		return array_filter(['type' => $type, 'start' => $start, 'end' => $end], static fn (mixed $value): bool => $value !== null);
	}//end coverageForRow()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a non-empty string.
	 */
	private function isText(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isText()

	/**
	 * Read a named property off the row as a date MDTO accepts.
	 *
	 * MDTO types both dekkingInTijd dates as a union of `gYear`, `gYearMonth`
	 * and `date`, so a timestamp is truncated to its date and anything else is
	 * refused rather than passed on to fail schema validation later.
	 *
	 * @param array<string, mixed> $row Row data keyed by field name.
	 * @param mixed $property The configured property name, if any.
	 *
	 * @return string|null The date, or null when the row does not carry a usable one.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	private function rowDate(array $row, mixed $property): ?string {
		if ($this->isText(value: $property) === false) {
			return null;
		}

		$value = ($row[$property] ?? null);
		if ($this->isText(value: $value) === false) {
			return null;
		}

		if (preg_match('/^(\d{4}(?:-\d{2}(?:-\d{2})?)?)/', $value, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}//end rowDate()

	/**
	 * Add an ISO-8601 duration to a datetime, returning a new immutable instance.
	 *
	 * @param DateTimeInterface $createdAt Source instant (typically the row's `_created`).
	 * @param string $duration ISO-8601 duration string.
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws InvalidArgumentException When the duration cannot be parsed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function addDuration(DateTimeInterface $createdAt, string $duration): DateTimeImmutable {
		try {
			$interval = new DateInterval($duration);
		} catch (Exception $error) {
			throw new InvalidArgumentException(
				sprintf('RetentionEvaluator: cannot parse duration "%s": %s', $duration, $error->getMessage())
			);
		}

		// Convert any DateTimeInterface to DateTimeImmutable for `->add()`.
		$immutable = DateTimeImmutable::createFromInterface($createdAt);

		return $immutable->add($interval);
	}//end addDuration()
}//end class
