<?php

/**
 * OpenRegister Anonymisation Detector Evaluation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use JsonSerializable;

/**
 * Precision, recall and support per entity type of one backend over one
 * corpus, with the corpus name and version, the detector version record it was
 * measured on, and when.
 *
 * A detection counts for a gold annotation when it has the annotation's type
 * and its span overlaps the annotation's; each annotation is counted at most
 * once. Detections of a type the corpus does not annotate are left out: the
 * corpus says nothing about them. A type with no detections has no precision
 * (null), never a precision of 0 or 1.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */
final class DetectorEvaluation implements JsonSerializable {

	/**
	 * Detector entity types written in the corpus vocabulary.
	 */
	private const TYPE_ALIASES = ['SSN' => 'BSN'];

	/**
	 * Constructor.
	 *
	 * @param string $method        The backend method that was measured.
	 * @param string $corpus        The corpus name.
	 * @param string $corpusVersion The corpus version.
	 * @param array<string, mixed> $detector      The detector version record it was measured on.
	 * @param string $measuredAt    When, as ISO 8601.
	 * @param array<string, array<string, mixed>> $types The figures per entity type: precision and recall (null
	 *                                                   when undefined), support and the three counts.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $corpus,
		public readonly string $corpusVersion,
		public readonly array $detector,
		public readonly string $measuredAt,
		public readonly array $types,
	) {
	}//end __construct()

	/**
	 * Score the detections of each corpus document against its gold annotations.
	 *
	 * @param EvaluationCorpus $corpus     The corpus.
	 * @param array<string, array<int, array>>       $detections Per document id, the detected entities (type, position_start, position_end).
	 * @param string $method     The backend method.
	 * @param DetectorVersion $detector   The detector version record.
	 * @param string $measuredAt When, as ISO 8601.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public static function score(
		EvaluationCorpus $corpus,
		array $detections,
		string $method,
		DetectorVersion $detector,
		string $measuredAt
	): self {
		$counts = array_fill_keys($corpus->types, ['tp' => 0, 'fp' => 0, 'fn' => 0, 'support' => 0]);
		foreach ($corpus->documents as $document) {
			$found = self::spansOf(entities: ($detections[$document['id']] ?? []), types: $corpus->types);
			foreach ($corpus->types as $type) {
				$gold = array_values(array_filter($document['gold'], static fn (array $span): bool => $span['type'] === $type));
				$hits = self::matched(gold: $gold, found: ($found[$type] ?? []));
				$counts[$type]['support'] += count($gold);
				$counts[$type]['tp'] += $hits;
				$counts[$type]['fn'] += (count($gold) - $hits);
				$counts[$type]['fp'] += (count($found[$type] ?? []) - $hits);
			}
		}

		$types = [];
		foreach ($counts as $type => $count) {
			$types[$type] = [
				'precision' => self::ratio(part: $count['tp'], whole: ($count['tp'] + $count['fp'])),
				'recall' => self::ratio(part: $count['tp'], whole: $count['support']),
				'support' => $count['support'],
				'truePositives' => $count['tp'],
				'falsePositives' => $count['fp'],
				'falseNegatives' => $count['fn'],
			];
		}

		return new self(
			method: $method,
			corpus: $corpus->name,
			corpusVersion: $corpus->version,
			detector: $detector->jsonSerialize(),
			measuredAt: $measuredAt,
			types: $types
		);
	}//end score()

	/**
	 * A stored evaluation, or null when the stored value is not one.
	 *
	 * @param mixed $stored The decoded stored value.
	 *
	 * @return self|null
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public static function fromStored(mixed $stored): ?self {
		if (is_array($stored) === false || is_array($stored['types'] ?? null) === false
			|| is_array($stored['detector'] ?? null) === false
		) {
			return null;
		}

		return new self(
			method: (string) ($stored['method'] ?? ''),
			corpus: (string) ($stored['corpus'] ?? ''),
			corpusVersion: (string) ($stored['corpusVersion'] ?? ''),
			detector: $stored['detector'],
			measuredAt: (string) ($stored['measuredAt'] ?? ''),
			types: $stored['types']
		);
	}//end fromStored()

	/**
	 * Whether these figures describe the given detector: the same record, whatever its changedAt.
	 *
	 * @param DetectorVersion $current The backend's current record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function describes(DetectorVersion $current): bool {
		return (new DetectorVersion(fields: $this->detector))->sameDetectorAs(other: $current);
	}//end describes()

	/**
	 * The evaluation as backend-state reports it for the current detector:
	 * with `current`, and without figures when it was measured on another version.
	 *
	 * @param DetectorVersion $current The backend's current record.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function reportFor(DetectorVersion $current): array {
		$report = $this->jsonSerialize();
		$report['current'] = $this->describes(current: $current);
		if ($report['current'] === false) {
			unset($report['types']);
		}

		return $report;
	}//end reportFor()

	/**
	 * The evaluation as stored.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function jsonSerialize(): array {
		return [
			'method' => $this->method,
			'corpus' => $this->corpus,
			'corpusVersion' => $this->corpusVersion,
			'detector' => $this->detector,
			'measuredAt' => $this->measuredAt,
			'types' => $this->types,
		];
	}//end jsonSerialize()

	/**
	 * The detected spans per corpus type.
	 *
	 * @param array<int, array> $entities The detected entities.
	 * @param array<int, string> $types    The corpus types.
	 *
	 * @return array<string, array<int, array{start: int, end: int}>>
	 */
	private static function spansOf(array $entities, array $types): array {
		$spans = [];
		foreach ($entities as $entity) {
			$type = (string) ($entity['type'] ?? '');
			$type = (self::TYPE_ALIASES[$type] ?? $type);
			if (in_array($type, $types, true) === false) {
				continue;
			}

			$spans[$type][] = ['start' => (int) ($entity['position_start'] ?? 0), 'end' => (int) ($entity['position_end'] ?? 0)];
		}

		return $spans;
	}//end spansOf()

	/**
	 * How many gold spans a detection overlaps, each detection used once.
	 *
	 * @param array<int, array{start: int, end: int}> $gold  The gold spans of one type.
	 * @param array<int, array{start: int, end: int}> $found The detected spans of that type.
	 *
	 * @return int
	 */
	private static function matched(array $gold, array $found): int {
		$hits = 0;
		foreach ($gold as $span) {
			foreach ($found as $index => $detected) {
				if ($detected['start'] < $span['end'] && $span['start'] < $detected['end']) {
					$hits++;
					unset($found[$index]);
					break;
				}
			}
		}

		return $hits;
	}//end matched()

	/**
	 * A share rounded to four decimals, or null when there is nothing to share.
	 *
	 * @param int $part  The part.
	 * @param int $whole The whole.
	 *
	 * @return float|null
	 */
	private static function ratio(int $part, int $whole): ?float {
		if ($whole === 0) {
			return null;
		}

		return round($part / $whole, 4);
	}//end ratio()
}//end class
