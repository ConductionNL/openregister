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
class QualityScorer
{
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
        'url'   => '#^https?://[^\s]+$#i',
        'date'  => '/^\d{4}-\d{2}-\d{2}([T ].*)?$/',
    ];

    /**
     * Score an object payload against a list of quality rules.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param array<int, mixed>    $rules  Declarative rule list.
     * @param DateTimeImmutable    $now    Reference instant for freshness rules.
     *
     * @return float Quality score in [0, 1]. Returns 1.0 when no usable rule
     *               applies (an object with no declared quality constraints is
     *               trivially compliant).
     *
     * @spec openspec/changes/mdm-foundation/tasks.md#task-1
     */
    public function score(array $object, array $rules, DateTimeImmutable $now): float
    {
        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($rules as $rule) {
            if (is_array($rule) === false) {
                continue;
            }

            $type = (string) ($rule['type'] ?? '');
            if ($type === '') {
                continue;
            }

            $weight = $this->ruleWeight(rule: $rule);
            if ($weight <= 0.0) {
                continue;
            }

            $sub = $this->scoreRule(object: $object, rule: $rule, type: $type, now: $now);

            $weightedSum += ($sub * $weight);
            $totalWeight += $weight;
        }//end foreach

        if ($totalWeight <= 0.0) {
            return 1.0;
        }

        $score = ($weightedSum / $totalWeight);

        // Clamp defensively; sub-scores are already bounded but float math drifts.
        return max(0.0, min(1.0, round($score, 4)));
    }//end score()

    /**
     * Map a numeric score to a status label using optional thresholds.
     *
     * @param float                $score      Score in [0, 1].
     * @param array<string, mixed> $thresholds Map with optional `good` / `fair` cut-offs.
     *
     * @return string One of `good`, `fair`, `poor`.
     *
     * @spec openspec/changes/mdm-foundation/tasks.md#task-1
     */
    public function status(float $score, array $thresholds): string
    {
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
     * @param array<string, mixed> $object Object payload.
     * @param array<string, mixed> $rule   Rule definition.
     * @param string               $type   Rule type token.
     * @param DateTimeImmutable    $now    Reference instant.
     *
     * @return float Sub-score in [0, 1].
     */
    private function scoreRule(array $object, array $rule, string $type, DateTimeImmutable $now): float
    {
        return match ($type) {
            'required'  => $this->scoreRequired(object: $object, rule: $rule),
            'format'    => $this->scoreFormat(object: $object, rule: $rule),
            'freshness' => $this->scoreFreshness(object: $object, rule: $rule, now: $now),
            default     => 0.0,
        };
    }//end scoreRule()

    /**
     * Score a required-field rule.
     *
     * @param array<string, mixed> $object Object payload.
     * @param array<string, mixed> $rule   Rule definition.
     *
     * @return float 1.0 when present and non-empty, else 0.0.
     */
    private function scoreRequired(array $object, array $rule): float
    {
        $value = $this->fieldValue(object: $object, field: (string) ($rule['field'] ?? ''));
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
     * @param array<string, mixed> $rule   Rule definition.
     *
     * @return float 1.0 on match, else 0.0.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The branch count tracks the rule's optional
     *   inputs (presence, named format, custom pattern, regex outcome); each is a distinct,
     *   independently-testable guard and folding them would obscure the format-resolution flow.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same rationale — sequential optional guards.
     * @SuppressWarnings(PHPMD.ErrorControlOperator) The @ on preg_match() suppresses a warning
     *   for a user-supplied pattern that fails to compile; the false return is handled as a
     *   non-match, so the operator is the guard, not a swallowed error.
     */
    private function scoreFormat(array $object, array $rule): float
    {
        $value = $this->fieldValue(object: $object, field: (string) ($rule['field'] ?? ''));
        if ($this->isPresent(value: $value) === false || is_scalar($value) === false) {
            return 0.0;
        }

        $subject = (string) $value;

        $pattern = null;
        $named   = (string) ($rule['format'] ?? '');
        if ($named !== '' && isset(self::NAMED_FORMATS[$named]) === true) {
            $pattern = self::NAMED_FORMATS[$named];
        }

        $custom = ($rule['pattern'] ?? null);
        if (is_string($custom) === true && $custom !== '') {
            $pattern = '/'.str_replace('/', '\\/', $custom).'/';
        }

        if ($pattern === null) {
            return 0.0;
        }

        try {
            $matched = @preg_match($pattern, $subject);
        } catch (Throwable $e) {
            return 0.0;
        }

        if ($matched === 1) {
            return 1.0;
        }

        return 0.0;
    }//end scoreFormat()

    /**
     * Score a freshness rule via exponential decay against now.
     *
     * @param array<string, mixed> $object Object payload.
     * @param array<string, mixed> $rule   Rule definition.
     * @param DateTimeImmutable    $now    Reference instant.
     *
     * @return float exp(-ageDays / halfLifeDays) clamped to [0, 1]; 0.0 when
     *               the date is absent or unparseable.
     */
    private function scoreFreshness(array $object, array $rule, DateTimeImmutable $now): float
    {
        $value = $this->fieldValue(object: $object, field: (string) ($rule['field'] ?? ''));
        if ($this->isPresent(value: $value) === false || is_scalar($value) === false) {
            return 0.0;
        }

        try {
            $when = new DateTimeImmutable((string) $value);
        } catch (Throwable $e) {
            return 0.0;
        }

        $ageSeconds = ($now->getTimestamp() - $when->getTimestamp());
        if ($ageSeconds <= 0) {
            return 1.0;
        }

        $ageDays  = ($ageSeconds / 86400.0);
        $halfLife = $this->floatOr(value: ($rule['halfLifeDays'] ?? null), fallback: self::DEFAULT_HALF_LIFE_DAYS);
        if ($halfLife <= 0.0) {
            return 0.0;
        }

        // Half-life decay: value drops to 0.5 every halfLife days.
        $decay = exp((-1.0 * M_LN2 * $ageDays) / $halfLife);

        return max(0.0, min(1.0, $decay));
    }//end scoreFreshness()

    /**
     * Resolve a dotted-path field value from the payload.
     *
     * @param array<string, mixed> $object Object payload.
     * @param string               $field  Field name (dotted for nesting).
     *
     * @return mixed The value, or null when the path is missing.
     */
    private function fieldValue(array $object, string $field)
    {
        if ($field === '') {
            return null;
        }

        $segments = explode('.', $field);
        $cursor   = $object;
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
    private function isPresent($value): bool
    {
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
    private function ruleWeight(array $rule): float
    {
        return max(0.0, $this->floatOr(value: ($rule['weight'] ?? null), fallback: self::DEFAULT_WEIGHT));
    }//end ruleWeight()

    /**
     * Coerce a value to float, falling back when not numeric.
     *
     * @param mixed $value    Candidate value.
     * @param float $fallback Fallback when not numeric.
     *
     * @return float
     */
    private function floatOr($value, float $fallback): float
    {
        if (is_int($value) === true || is_float($value) === true) {
            return (float) $value;
        }

        if (is_string($value) === true && is_numeric($value) === true) {
            return (float) $value;
        }

        return $fallback;
    }//end floatOr()
}//end class
