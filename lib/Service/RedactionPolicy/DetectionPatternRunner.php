<?php

/**
 * OpenRegister DetectionPatternRunner
 *
 * Runs the organisation's own detection patterns over a text, beside whatever
 * the model found (REQ-RPD-003, REQ-RPD-004).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RedactionPolicy
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RedactionPolicy;

/**
 * Turns `detectionPattern` objects into detections.
 *
 * An inactive pattern does not run, nor does a base pattern a local object
 * switches off (`overrides: <base slug>` with `active: false`), so an
 * organisation's override keeps holding after the base layer is updated. A
 * regex is run under the validator's backtrack limit; one that fails at run
 * time (it was stored before the check existed, or the document is worse than
 * the test texts) is reported as failed rather than silently finding nothing.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
 */
final class DetectionPatternRunner {

	/**
	 * The method prefix every pattern detection carries.
	 *
	 * @var string
	 */
	public const METHOD_PREFIX = 'pattern:';

	/**
	 * Run every applicable pattern over the text.
	 *
	 * @param array<int, array<string, mixed>> $patterns The detectionPattern objects, each with its `slug`.
	 * @param string                           $text     The document text.
	 *
	 * @return array{detections: array<int, array{entityType: string, text: string, start: int, end: int, confidence: float, detectionMethod: string, bases: array<int, string>}>, failed: array<string, string>}
	 *         The detections in pattern then text order, and the slugs that could not run with why.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
	 */
	public function run(array $patterns, string $text): array {
		$detections = [];
		$failed = [];
		foreach ($this->applicable(patterns: $patterns) as $pattern) {
			$slug = (string)($pattern['slug'] ?? '');
			$found = $this->matchesOf(pattern: $pattern, text: $text);
			if ($found === null) {
				$failed[$slug] = 'the pattern exceeded the backtrack limit or did not compile, so it found nothing';
				continue;
			}

			foreach ($found as [$value, $start]) {
				$detections[] = $this->detection(pattern: $pattern, value: $value, start: $start);
			}
		}

		return ['detections' => $detections, 'failed' => $failed];
	}//end run()

	/**
	 * The patterns that apply: active, complete, and not a base pattern switched off locally.
	 *
	 * @param array<int, array<string, mixed>> $patterns The objects.
	 *
	 * @return array<int, array<string, mixed>> The applicable ones, in the order given.
	 */
	private function applicable(array $patterns): array {
		$switchedOff = [];
		foreach ($patterns as $pattern) {
			$overrides = (string)($pattern['overrides'] ?? '');
			if ($overrides !== '' && ($pattern['layer'] ?? 'local') === 'local' && ($pattern['active'] ?? true) === false) {
				$switchedOff[] = $overrides;
			}
		}

		return array_values(
			array_filter(
				$patterns,
				static fn (array $pattern): bool => ($pattern['active'] ?? true) !== false
					&& is_string($pattern['pattern'] ?? null) === true && $pattern['pattern'] !== ''
					&& is_string($pattern['entityType'] ?? null) === true && $pattern['entityType'] !== ''
					&& (($pattern['layer'] ?? 'local') !== 'base' || in_array((string)($pattern['slug'] ?? ''), $switchedOff, true) === false)
			)
		);
	}//end applicable()

	/**
	 * Every match of one pattern, as value and byte offset, or null when it could not run.
	 *
	 * @param array<string, mixed> $pattern The pattern.
	 * @param string               $text    The text.
	 *
	 * @return array<int, array{0: string, 1: int}>|null The matches.
	 */
	private function matchesOf(array $pattern, string $text): ?array {
		$regex = DetectionPatternValidator::compiled(pattern: (string)$pattern['pattern']);
		if (($pattern['kind'] ?? 'regex') === 'term') {
			$regex = DetectionPatternValidator::DELIMITER.preg_quote((string)$pattern['pattern'], DetectionPatternValidator::DELIMITER).DetectionPatternValidator::DELIMITER.'iu';
		}

		[$result, , $matches] = DetectionPatternValidator::matchAllBounded(regex: $regex, text: $text);
		if ($result === false) {
			return null;
		}

		return array_values(array_filter($matches[0] ?? [], static fn (array $match): bool => $match[0] !== ''));
	}//end matchesOf()

	/**
	 * One detection.
	 *
	 * @param array<string, mixed> $pattern The pattern.
	 * @param string               $value   The matched text.
	 * @param int                  $start   Its byte offset.
	 *
	 * @return array{entityType: string, text: string, start: int, end: int, confidence: float, detectionMethod: string, bases: array<int, string>} The detection.
	 */
	private function detection(array $pattern, string $value, int $start): array {
		$bases = $pattern['bases'] ?? [];
		if (is_array($bases) === false) {
			$bases = [];
		}

		return [
			'entityType' => (string)$pattern['entityType'],
			'text' => $value,
			'start' => $start,
			'end' => $start + strlen($value),
			'confidence' => (float)($pattern['confidence'] ?? 1.0),
			'detectionMethod' => self::METHOD_PREFIX.(string)($pattern['slug'] ?? ''),
			'bases' => array_values(array_filter($bases, 'is_string')),
		];
	}//end detection()
}//end class
