<?php

/**
 * Unit tests for scoring a detector over a corpus and for what backend-state
 * reports of it (anonymisation-discloses-itself, REQ-ADI-002).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCA\OpenRegister\Service\Anonymisation\BackendInfo;
use OCA\OpenRegister\Service\Anonymisation\DetectorEvaluation;
use OCA\OpenRegister\Service\Anonymisation\DetectorEvaluationStore;
use OCA\OpenRegister\Service\Anonymisation\DetectorVersion;
use OCA\OpenRegister\Service\Anonymisation\EvaluationCorpus;
use OCA\OpenRegister\Service\Anonymisation\ProbeResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Overlap scoring, the corpus vocabulary, and figures that no longer describe the detector.
 */
#[CoversClass(DetectorEvaluation::class)]
#[CoversClass(DetectorEvaluationStore::class)]
#[CoversClass(BackendInfo::class)]
#[UsesClass(EvaluationCorpus::class)]
#[UsesClass(DetectorVersion::class)]
#[UsesClass(ProbeResult::class)]
final class DetectorEvaluationServiceTest extends TestCase {
	use InMemoryAppConfigTrait;

	/**
	 * Two documents: two names and a BSN, then one e-mail address.
	 *
	 * @return EvaluationCorpus
	 */
	private static function corpus(): EvaluationCorpus {
		return EvaluationCorpus::fromArray(
			data: [
				'name' => 'tiny',
				'version' => '3',
				'types' => ['PERSON', 'BSN', 'EMAIL'],
				'documents' => [
					['id' => 'a', 'text' => 'Jan Smit en Piet Kok, BSN 999990007.', 'gold' => [
						['type' => 'PERSON', 'value' => 'Jan Smit', 'start' => 0, 'end' => 8],
						['type' => 'PERSON', 'value' => 'Piet Kok', 'start' => 12, 'end' => 20],
						['type' => 'BSN', 'value' => '999990007', 'start' => 26, 'end' => 35],
					]],
					['id' => 'b', 'text' => 'Mail a@example.org.', 'gold' => [
						['type' => 'EMAIL', 'value' => 'a@example.org', 'start' => 5, 'end' => 18],
					]],
				],
			]
		);
	}//end corpus()

	/**
	 * A detection as the handler reports it.
	 *
	 * @param string $type  The type.
	 * @param int    $start The start offset.
	 * @param int    $end   The end offset.
	 *
	 * @return array<string, mixed>
	 */
	private static function found(string $type, int $start, int $end): array {
		return ['type' => $type, 'position_start' => $start, 'position_end' => $end, 'confidence' => 0.9];
	}//end found()

	public function testAnOverlappingDetectionCountsOnceAndTheSsnTypeIsTheCorpusBsn(): void {
		$evaluation = DetectorEvaluation::score(
			corpus: self::corpus(),
			detections: [
				// One detection covering "Jan Smit en Piet" overlaps both names but finds one;
				// a second one on "Smit" has no name left to find.
				'a' => [self::found('PERSON', 0, 16), self::found('PERSON', 4, 8), self::found('SSN', 26, 35), self::found('PHONE', 26, 35)],
			],
			method: 'presidio',
			detector: new DetectorVersion(fields: ['engine' => 'presidio', 'modelVersion' => '1.4']),
			measuredAt: '2026-11-14T10:00:00+00:00'
		);

		$this->assertSame(['precision' => 0.5, 'recall' => 0.5, 'support' => 2, 'truePositives' => 1, 'falsePositives' => 1, 'falseNegatives' => 1], $evaluation->types['PERSON']);
		$this->assertSame(['precision' => 1.0, 'recall' => 1.0, 'support' => 1, 'truePositives' => 1, 'falsePositives' => 0, 'falseNegatives' => 0], $evaluation->types['BSN']);
		// Document b got no detections at all: its address is missed, and nothing was claimed.
		$this->assertSame(['precision' => null, 'recall' => 0.0, 'support' => 1, 'truePositives' => 0, 'falsePositives' => 0, 'falseNegatives' => 1], $evaluation->types['EMAIL']);
		// A type the corpus does not annotate is not scored.
		$this->assertSame(['PERSON', 'BSN', 'EMAIL'], array_keys($evaluation->types));
		$this->assertSame('tiny', $evaluation->corpus);
		$this->assertSame('3', $evaluation->corpusVersion);
		$this->assertSame('1.4', $evaluation->detector['modelVersion']);
	}

	public function testADetectionNextToAGoldSpanIsNotAHit(): void {
		$evaluation = DetectorEvaluation::score(
			corpus: self::corpus(),
			detections: ['b' => [self::found('EMAIL', 0, 5)]],
			method: 'regex',
			detector: new DetectorVersion(fields: []),
			measuredAt: '2026-11-14T10:00:00+00:00'
		);

		$this->assertSame(0, $evaluation->types['EMAIL']['truePositives']);
		$this->assertSame(1, $evaluation->types['EMAIL']['falsePositives']);
	}

	public function testFiguresForAnotherDetectorVersionAreNotCurrent(): void {
		$store = new DetectorEvaluationStore($this->inMemoryAppConfig());
		$measuredOn = new DetectorVersion(fields: ['engine' => 'regex', 'patternSetVersion' => '1', 'changedAt' => '2026-11-01']);
		$store->save(DetectorEvaluation::score(corpus: self::corpus(), detections: [], method: 'regex', detector: $measuredOn, measuredAt: '2026-11-14T10:00:00+00:00'));

		$current = $store->reportFor('regex', new DetectorVersion(fields: ['engine' => 'regex', 'patternSetVersion' => '1', 'changedAt' => '2026-11-13']));
		$stale = $store->reportFor('regex', new DetectorVersion(fields: ['engine' => 'regex', 'patternSetVersion' => '2']));

		$this->assertTrue($current['current']);
		$this->assertSame(1, $current['types']['BSN']['support']);
		$this->assertFalse($stale['current']);
		$this->assertArrayNotHasKey('types', $stale, 'Old figures are not shown as current.');
		$this->assertSame('1', $stale['detector']['patternSetVersion']);
		$this->assertSame('2026-11-14T10:00:00+00:00', $stale['measuredAt']);
	}

	public function testANeverMeasuredOrUnreadableBackendHasNoEvaluation(): void {
		$store = new DetectorEvaluationStore($this->inMemoryAppConfig());
		$this->configValues['openregister/' . DetectorEvaluationStore::CONFIG_PREFIX . 'llm'] = '{"not":"an evaluation"}';

		$this->assertNull($store->reportFor('presidio', new DetectorVersion(fields: [])));
		$this->assertNull($store->latest('llm'));
	}

	public function testBackendStateCarriesTheEvaluationOnlyWhenMeasured(): void {
		$probe = new ProbeResult(reachable: true, latencyMs: 3, error: null, probedAt: '2026-11-14T09:00:00+00:00');
		$report = ['corpus' => 'tiny', 'current' => false];

		$this->assertSame($report, (new BackendInfo(name: 'regex', configured: true, probe: $probe, evaluation: $report))->jsonSerialize()['evaluation']);
		$this->assertArrayNotHasKey('evaluation', (new BackendInfo(name: 'regex', configured: true, probe: $probe))->jsonSerialize());
	}
}
