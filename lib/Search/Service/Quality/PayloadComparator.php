<?php

/**
 * Compares object payloads on declarative match rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\Quality
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

/**
 * The one rule engine behind both duplicate detection entry points.
 *
 * A warning shown at intake and a duplicate found by a later sweep have to
 * agree on what a duplicate is. They agree because both reach this one
 * comparator through {@see DuplicateDetectionService}: path resolution,
 * blocking tokens, fingerprints and scoring each have one definition here.
 */
class PayloadComparator {
	/**
	 * Per-field similarity at or above which the field itself counts as matched.
	 *
	 * Two readers depend on it: the pair list's `matchedOn` and the check's
	 * `matchedRules`.
	 *
	 * @var float
	 */
	private const FIELD_MATCH_SIMILARITY = 0.9;

	/**
	 * Constructor.
	 *
	 * @param SimilarityCalculator $similarity Pure field-similarity primitives.
	 */
	public function __construct(
		private readonly SimilarityCalculator $similarity,
	) {
	}//end __construct()

	/**
	 * Resolve a dotted-path field value from an object payload.
	 *
	 * A plain, dot-free field resolves exactly as a direct top-level array
	 * read. A dotted path (e.g. `goldenRecord.email`) traverses each
	 * segment in order and yields `null` — never throws — as soon as any
	 * segment is missing or its container is not an array. Mirrors the
	 * dot-path idiom used by {@see \OCA\OpenRegister\Service\Quality\QualityScorer::fieldValue()}.
	 *
	 * @param array<string, mixed> $data Object payload.
	 * @param string               $path Field name or dotted path.
	 *
	 * @return mixed The resolved value, or null when the path is missing.
	 *
	 * @spec openspec/changes/mdm-dedup-nested-paths/tasks.md#task-1
	 */
	public function valueAt(array $data, string $path): mixed {
		if ($path === '') {
			return null;
		}

		// Once a segment misses, the cursor is null and every later step stays null.
		return array_reduce(
			explode('.', $path),
			fn (mixed $cursor, string $segment): mixed => $this->child(container: $cursor, key: $segment),
			$data
		);
	}//end valueAt()

	/**
	 * One path step: the value under a key, or null when the container is not an array or lacks the key.
	 *
	 * @param mixed  $container The current value.
	 * @param string $key       The path segment.
	 *
	 * @return mixed
	 */
	private function child(mixed $container, string $key): mixed {
		if (is_array($container) === false || array_key_exists($key, $container) === false) {
			return null;
		}

		return $container[$key];
	}//end child()

	/**
	 * Build a composite blocking token across the declared keys.
	 *
	 * @param array<string, mixed> $data Object payload.
	 * @param array<int, string>   $keys Blocking field names (plain or dotted paths).
	 *
	 * @return string Composite token, or empty when any key is absent.
	 *
	 * @spec openspec/changes/mdm-dedup-nested-paths/tasks.md#task-2
	 */
	public function blockingToken(array $data, array $keys): string {
		$parts = [];
		foreach ($keys as $key) {
			$token = $this->similarity->blockingToken('normalized', $this->valueAt(data: $data, path: $key));
			if ($token === '') {
				return '';
			}

			$parts[] = $token;
		}

		return implode('|', $parts);
	}//end blockingToken()

	/**
	 * One object's contribution to a pair fingerprint.
	 *
	 * @param array<string, mixed>             $data  The object's payload.
	 * @param array<int, array<string, mixed>> $rules The sanitised match rules.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function fingerprintSide(array $data, array $rules): string {
		$parts = [];
		foreach ($rules as $rule) {
			$field = (string) ($rule['field'] ?? '');
			if ($field !== '') {
				$parts[] = $field . '=' . $this->similarity->blockingToken('normalized', $this->valueAt(data: $data, path: $field));
			}
		}

		sort($parts, SORT_STRING);

		return implode('|', $parts);
	}//end fingerprintSide()

	/**
	 * Score two payloads against the match rules.
	 *
	 * @param array{0: array<string, mixed>, 1: array<string, mixed>} $payloads The two payloads.
	 * @param array<int, array<string, mixed>>                        $rules    Match rules.
	 *
	 * @return array{
	 *   score: float,
	 *   matchedOn: array<int, string>,
	 *   matchedRules: array<int, array{field: string, method: string, similarity: float}>
	 * }
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function score(array $payloads, array $rules): array {
		$weightedSum = 0.0;
		$totalWeight = 0.0;
		$scoredRules = array_values(array_filter(array_map(fn (array $rule): ?array => $this->scoreRule(rule: $rule, payloads: $payloads), $rules)));
		foreach ($scoredRules as $scored) {
			$weightedSum += ($scored['similarity'] * $scored['weight']);
			$totalWeight += $scored['weight'];
		}

		$matchedRules = $this->matchedRules(scoredRules: $scoredRules);

		return [
			'score' => $this->weightedScore(weightedSum: $weightedSum, totalWeight: $totalWeight),
			'matchedOn' => array_values(array_unique(array_column($matchedRules, 'field'))),
			'matchedRules' => $matchedRules,
		];
	}//end score()

	/**
	 * The scored rules whose own similarity counts as a field match.
	 *
	 * @param array<int, array{field: string, method: string, weight: float, similarity: float}> $scoredRules Scored rules.
	 *
	 * @return array<int, array{field: string, method: string, similarity: float}>
	 */
	private function matchedRules(array $scoredRules): array {
		$matched = [];
		foreach ($scoredRules as $scored) {
			if ($scored['similarity'] >= self::FIELD_MATCH_SIMILARITY) {
				$matched[] = ['field' => $scored['field'], 'method' => $scored['method'], 'similarity' => round($scored['similarity'], 4)];
			}
		}

		return $matched;
	}//end matchedRules()

	/**
	 * Compare two payloads on one rule.
	 *
	 * A rule without positive weight, or a field neither side holds, says
	 * nothing about the pair and stays out of the denominator (a permit
	 * reference on two Woo requests that never have one).
	 *
	 * @param array<string, mixed>                                    $rule     The match rule.
	 * @param array{0: array<string, mixed>, 1: array<string, mixed>} $payloads The two payloads.
	 *
	 * @return array{field: string, method: string, weight: float, similarity: float}|null Null when the rule does not count.
	 */
	private function scoreRule(array $rule, array $payloads): ?array {
		$field = (string) ($rule['field'] ?? '');
		$method = (string) ($rule['method'] ?? '');
		$weight = $this->ruleWeight(rule: $rule);
		$valueA = $this->valueAt(data: $payloads[0], path: $field);
		$valueB = $this->valueAt(data: $payloads[1], path: $field);
		if ($weight <= 0.0 || ($this->isBlank(value: $valueA) === true && $this->isBlank(value: $valueB) === true)) {
			return null;
		}

		$similarity = $this->similarity->similarity($method, $valueA, $valueB);

		return ['field' => $field, 'method' => $method, 'weight' => $weight, 'similarity' => $similarity];
	}//end scoreRule()

	/**
	 * A rule's numeric weight, 1.0 when it declares none.
	 *
	 * @param array<string, mixed> $rule The match rule.
	 *
	 * @return float
	 */
	private function ruleWeight(array $rule): float {
		if (is_numeric($rule['weight'] ?? null) === true) {
			return (float) $rule['weight'];
		}

		return 1.0;
	}//end ruleWeight()

	/**
	 * The weighted mean rounded to four decimals, 0.0 when nothing carried weight.
	 *
	 * @param float $weightedSum Sum of similarity times weight.
	 * @param float $totalWeight Sum of the weights.
	 *
	 * @return float
	 */
	private function weightedScore(float $weightedSum, float $totalWeight): float {
		if ($totalWeight > 0.0) {
			return round(($weightedSum / $totalWeight), 4);
		}

		return 0.0;
	}//end weightedScore()

	/**
	 * Whether a field value is absent: null, an empty or blank string, or an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when there is nothing to compare.
	 */
	private function isBlank(mixed $value): bool {
		if ($value === null) {
			return true;
		}

		if (is_string($value) === true) {
			return trim($value) === '';
		}

		return is_array($value) === true && $value === [];
	}//end isBlank()
}//end class
