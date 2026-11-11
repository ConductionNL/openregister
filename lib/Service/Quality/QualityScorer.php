<?php

/**
 * OpenRegister QualityScorer
 *
 * Pure-function data-quality scorer. Given an object payload and a list of
 * declarative quality rules, computes a single weighted score in [0, 1].
 * No I/O, no DB access, no HTTP, never fatal: an unknown rule type or a
 * malformed rule contributes a zero sub-score rather than throwing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
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

namespace OCA\OpenRegister\Service\Quality;

use DateTimeImmutable;
use Throwable;

/**
 * Computes a per-object data-quality score from declarative rules.
 *
 * Each rule yields a sub-score in [0, 1]; the object score is the
 * weight-normalised mean of all sub-scores. Mirrors the pure-function,
 * null-safe contract of {@see \OCA\OpenRegister\Service\Calculation\CalculationEvaluator}.
 *
 * Supported rule types:
 * - required:  1.0 when the field is present and non-empty, else 0.0.
 * - format:    1.0 when the field value matches a named format or regex
 *              `pattern`; an absent field scores 0.0. Named formats: email,
 *              url, date.
 * - freshness: exponential decay exp(-ageDays / halfLifeDays) of a date
 *              field against now; absent / unparseable date scores 0.0.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-1
 */
class QualityScorer implements QualityScorerInterface {
	/**
	 * Default weight applied when a rule omits an explicit weight.
	 *
	 * @var float
	 */
	private const DEFAULT_WEIGHT = 1.0;

	/**
	 * Default half-life (days) for a freshness rule that omits one.
	 *
	 * @var float
	 */
	private const DEFAULT_HALF_LIFE_DAYS = 180.0;

	/**
	 * Built-in named formats mapped to PCRE patterns.
	 *
	 * @var array<string, string>
	 */
	private const NAMED_FORMATS = [
		'email' => '/^[^@\s]+@[^@\s]+\.[^@\s]+$/',
		'url' => '#^https?://[^\s]+$#i',
		'date' => '/^\d{4}-\d{2}-\d{2}([T ].*)?$/',
	];

	/**
	 * Score an object payload against a list of quality rules.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param array<int, mixed> $rules Declarative rule list.
	 * @param DateTimeImmutable $now Reference instant for freshness rules.
	 *
	 * @return float Quality score in [0, 1]. Returns 1.0 when no usable rule
	 *               applies (an object with no declared quality constraints is
	 *               trivially compliant).
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-1
	 */
	public function score(array $object, array $rules, DateTimeImmutable $now): float {
		return $this->scoreAll(run: new QualityScoreRun(object: $object, now: $now), rules: $rules);
	}//end score()

	/**
	 * Score one object against its rules: the weighted mean of the usable
	 * rules' sub-scores, 1.0 when no rule is usable.
	 *
	 * @param QualityScoreRun   $run   The object and the moment freshness is measured from.
	 * @param array<int, mixed> $rules The rules; a non-array or typeless rule is skipped.
	 *
	 * @return float The score in [0.0, 1.0], rounded to 4 decimals.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-1
	 */
	private function scoreAll(QualityScoreRun $run, array $rules): float {
		$weightedSum = 0.0;
		$totalWeight = 0.0;

		foreach ($rules as $rule) {
			$weight = $this->usableWeight(rule: $rule);
			if ($weight <= 0.0) {
				continue;
			}

			$sub = $this->scoreRule(run: $run, rule: $rule);

			$weightedSum += ($sub * $weight);
			$totalWeight += $weight;
		}//end foreach

		if ($totalWeight <= 0.0) {
			return 1.0;
		}

		$score = ($weightedSum / $totalWeight);

		// Clamp defensively; sub-scores are already bounded but float math drifts.
		return max(0.0, min(1.0, round($score, 4)));
	}//end scoreAll()

	/**
	 * The weight a rule counts with, or 0.0 when the rule cannot be scored.
	 *
	 * A rule that is not an object or names no type is skipped, exactly as a
	 * rule with a zero or negative weight is.
	 *
	 * @param mixed $rule Rule definition.
	 *
	 * @return float Weight (>= 0); 0.0 means skip the rule.
	 */
	private function usableWeight($rule): float {
		if (is_array($rule) === false) {
			return 0.0;
		}

		if ((string)($rule['type'] ?? '') === '') {
			return 0.0;
		}

		return $this->ruleWeight(rule: $rule);
	}//end usableWeight()

	/**
	 * Map a numeric score to a status label using optional thresholds.
	 *
	 * @param float $score Score in [0, 1].
	 * @param array<string, mixed> $thresholds Map with optional `good` / `fair` cut-offs.
	 *
	 * @return string One of `good`, `fair`, `poor`.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-1
	 */
	public function status(float $score, array $thresholds): string {
		$good = $this->floatOr(value: ($thresholds['good'] ?? null), fallback: 0.8);
		$fair = $this->floatOr(value: ($thresholds['fair'] ?? null), fallback: 0.5);

		if ($score >= $good) {
			return 'good';
		}

		if ($score >= $fair) {
			return 'fair';
		}

		return 'poor';
	}//end status()

	/**
	 * Dispatch a single rule to its scoring routine.
	 *
	 * @param QualityScoreRun      $run  The object and the reference instant.
	 * @param array<string, mixed> $rule Rule definition; its `type` picks the routine.
	 *
	 * @return float Sub-score in [0, 1].
	 */
	private function scoreRule(QualityScoreRun $run, array $rule): float {
		return match ((string)$rule['type']) {
			'required' => $this->scoreRequired(object: $run->object, rule: $rule),
			'format' => $this->scoreFormat(object: $run->object, rule: $rule),
			'freshness' => $this->scoreFreshness(run: $run, rule: $rule),
			default => 0.0,
		};
	}//end scoreRule()

	/**
	 * Score a required-field rule.
	 *
	 * @param array<string, mixed> $object Object payload.
	 * @param array<string, mixed> $rule Rule definition.
	 *
	 * @return float 1.0 when present and non-empty, else 0.0.
	 */
	private function scoreRequired(array $object, array $rule): float {
		$value = $this->fieldValue(object: $object, field: (string)($rule['field'] ?? ''));
		if ($this->isPresent(value: $value) === true) {
			return 1.0;
		}

		return 0.0;
	}//end scoreRequired()

	/**
	 * Score a format rule against a named format or a custom regex.
	 *
	 * An absent value scores 0.0 (a format rule presumes presence). When both
	 * `format` and `pattern` are absent the rule cannot be evaluated and scores 0.0.
	 *
	 * @param array<string, mixed> $object Object payload.
	 * @param array<string, mixed> $rule Rule definition.
	 *
	 * @return float 1.0 on match, else 0.0.
	 */
	private function scoreFormat(array $object, array $rule): float {
		$subject = $this->scalarSubject(object: $object, rule: $rule);
		if ($subject === null) {
			return 0.0;
		}

		$pattern = $this->formatPattern(rule: $rule);
		if ($pattern === null) {
			return 0.0;
		}

		return $this->matchScore(pattern: $pattern, subject: $subject);
	}//end scoreFormat()

	/**
	 * The rule's field value as a string, or null when absent or not scalar.
	 *
	 * @param array<string, mixed> $object Object payload.
	 * @param array<string, mixed> $rule Rule definition.
	 *
	 * @return string|null
	 */
	private function scalarSubject(array $object, array $rule): ?string {
		$value = $this->fieldValue(object: $object, field: (string)($rule['field'] ?? ''));
		if ($this->isPresent(value: $value) === false || is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end scalarSubject()

	/**
	 * The PCRE pattern a format rule checks against.
	 *
	 * A custom `pattern` wins over a named `format`; an unknown name with no
	 * pattern yields null, and the rule cannot be evaluated.
	 *
	 * @param array<string, mixed> $rule Rule definition.
	 *
	 * @return string|null
	 */
	private function formatPattern(array $rule): ?string {
		$named = (string)($rule['format'] ?? '');

		$custom = ($rule['pattern'] ?? null);
		if (is_string($custom) === true && $custom !== '') {
			return '/' . str_replace('/', '\\/', $custom) . '/';
		}

		return (self::NAMED_FORMATS[$named] ?? null);
	}//end formatPattern()

	/**
	 * Match a subject against a pattern; a pattern that fails to compile is a non-match.
	 *
	 * The compile warning of a user-supplied pattern is swallowed for the
	 * duration of the call only.
	 *
	 * @param string $pattern PCRE pattern.
	 * @param string $subject Value to test.
	 *
	 * @return float 1.0 on match, else 0.0.
	 */
	private function matchScore(string $pattern, string $subject): float {
		set_error_handler(callback: static fn (): bool => true);
		try {
			$matched = preg_match($pattern, $subject);
		} catch (Throwable $e) {
			return 0.0;
		} finally {
			restore_error_handler();
		}

		if ($matched === 1) {
			return 1.0;
		}

		return 0.0;
	}//end matchScore()

	/**
	 * Score a freshness rule via exponential decay against now.
	 *
	 * @param QualityScoreRun      $run  The object and the reference instant.
	 * @param array<string, mixed> $rule Rule definition.
	 *
	 * @return float exp(-ageDays / halfLifeDays) clamped to [0, 1]; 0.0 when
	 *               the date is absent or unparseable.
	 */
	private function scoreFreshness(QualityScoreRun $run, array $rule): float {
		$when = $this->instantOf(run: $run, rule: $rule);
		if ($when === null) {
			return 0.0;
		}

		$ageSeconds = ($run->now->getTimestamp() - $when->getTimestamp());
		if ($ageSeconds <= 0) {
			return 1.0;
		}

		return $this->decay(ageDays: ($ageSeconds / 86400.0), rule: $rule);
	}//end scoreFreshness()

	/**
	 * The instant the rule's field holds, or null when it is absent or will not parse.
	 *
	 * @param QualityScoreRun      $run  The object and the reference instant.
	 * @param array<string, mixed> $rule Rule definition.
	 *
	 * @return DateTimeImmutable|null The instant.
	 */
	private function instantOf(QualityScoreRun $run, array $rule): ?DateTimeImmutable {
		$value = $this->scalarSubject(object: $run->object, rule: $rule);
		if ($value === null) {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end instantOf()

	/**
	 * Half-life decay of an age, clamped to [0, 1]; 0.0 for a half-life that is not positive.
	 *
	 * @param float                $ageDays The age in days.
	 * @param array<string, mixed> $rule    Rule definition, with its optional `halfLifeDays`.
	 *
	 * @return float The score.
	 */
	private function decay(float $ageDays, array $rule): float {
		$halfLife = $this->floatOr(value: ($rule['halfLifeDays'] ?? null), fallback: self::DEFAULT_HALF_LIFE_DAYS);
		if ($halfLife <= 0.0) {
			return 0.0;
		}

		// Half-life decay: value drops to 0.5 every halfLife days.
		$decay = exp((-1.0 * M_LN2 * $ageDays) / $halfLife);

		return max(0.0, min(1.0, $decay));
	}//end decay()

	/**
	 * Resolve a dotted-path field value from the payload.
	 *
	 * @param array<string, mixed> $object Object payload.
	 * @param string $field Field name (dotted for nesting).
	 *
	 * @return mixed The value, or null when the path is missing.
	 */
	private function fieldValue(array $object, string $field) {
		if ($field === '') {
			return null;
		}

		$segments = explode('.', $field);
		$cursor = $object;
		foreach ($segments as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end fieldValue()

	/**
	 * Determine whether a value counts as present (non-empty).
	 *
	 * @param mixed $value Value to test.
	 *
	 * @return bool True when the value is meaningfully populated.
	 */
	private function isPresent($value): bool {
		if ($value === null) {
			return false;
		}

		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		if (is_array($value) === true) {
			return count($value) > 0;
		}

		return true;
	}//end isPresent()

	/**
	 * Resolve a rule weight, defaulting when absent or non-numeric.
	 *
	 * @param array<string, mixed> $rule Rule definition.
	 *
	 * @return float Weight (>= 0).
	 */
	private function ruleWeight(array $rule): float {
		return max(0.0, $this->floatOr(value: ($rule['weight'] ?? null), fallback: self::DEFAULT_WEIGHT));
	}//end ruleWeight()

	/**
	 * Coerce a value to float, falling back when not numeric.
	 *
	 * @param mixed $value Candidate value.
	 * @param float $fallback Fallback when not numeric.
	 *
	 * @return float
	 */
	private function floatOr($value, float $fallback): float {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric($value) === true) {
			return (float)$value;
		}

		return $fallback;
	}//end floatOr()
}//end class
