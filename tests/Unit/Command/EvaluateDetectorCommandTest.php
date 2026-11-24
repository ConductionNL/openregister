<?php

/**
 * Unit tests for `occ openregister:anonymisation:evaluate` (anonymisation-discloses-itself, REQ-ADI-002).
 *
 * The command drives the real regex detection (the handler and its pattern
 * sets) over a three-document corpus; only the stores, the settings, the
 * backend state and the app config are stand-ins.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use DateTime;
use OCA\OpenRegister\Command\EvaluateDetectorCommand;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityDetectionQuery;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionStores;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\JurisdictionPatternSets;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlPatternSet;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\BackendInfo;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\Anonymisation\DetectorEvaluation;
use OCA\OpenRegister\Service\Anonymisation\DetectorEvaluationService;
use OCA\OpenRegister\Service\Anonymisation\DetectorEvaluationStore;
use OCA\OpenRegister\Service\Anonymisation\DetectorVersion;
use OCA\OpenRegister\Service\Anonymisation\EvaluationCorpus;
use OCA\OpenRegister\Service\Anonymisation\ProbeResult;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionBackends;
use OCA\OpenRegister\Tests\Unit\Service\Anonymisation\InMemoryAppConfigTrait;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command measures the regex backend and keeps the figures.
 */
#[CoversClass(EvaluateDetectorCommand::class)]
#[CoversClass(DetectorEvaluationService::class)]
#[UsesClass(EvaluationCorpus::class)]
#[UsesClass(DetectorEvaluation::class)]
#[UsesClass(DetectorEvaluationStore::class)]
#[UsesClass(DetectorVersion::class)]
#[UsesClass(EntityRecognitionHandler::class)]
#[UsesClass(EntityDetectionQuery::class)]
#[UsesClass(EntityRecognitionStores::class)]
#[UsesClass(EntityRecognitionBackends::class)]
#[UsesClass(JurisdictionPatternSets::class)]
#[UsesClass(NlPatternSet::class)]
#[UsesClass(BackendState::class)]
#[UsesClass(BackendInfo::class)]
#[UsesClass(ProbeResult::class)]
final class EvaluateDetectorCommandTest extends TestCase {
	use InMemoryAppConfigTrait;

	private const CORPUS = __DIR__ . '/../../Fixtures/anonymisation-eval/three-documents';

	/**
	 * The pattern set record the regex backend reports.
	 *
	 * @param string $version The pattern set version.
	 *
	 * @return DetectorVersion
	 */
	private static function regexRecord(string $version): DetectorVersion {
		return new DetectorVersion(fields: ['engine' => 'regex', 'patternSetVersion' => $version]);
	}//end regexRecord()

	/**
	 * A command over the real regex detection, the backend reporting the given record.
	 *
	 * @param DetectorVersion $record The regex backend's record.
	 *
	 * @return CommandTester
	 */
	private function tester(DetectorVersion $record): CommandTester {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileSettingsOnly')->willReturn(['entityPatternSets' => ['nl']]);
		$handler = new EntityRecognitionHandler(
			stores: new EntityRecognitionStores(
				chunkMapper: $this->createMock(ChunkMapper::class),
				entityMapper: $this->createMock(GdprEntityMapper::class),
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				db: $this->createMock(IDBConnection::class)
			),
			backends: new EntityRecognitionBackends(
				settingsService: $settings,
				anonymisationBackendService: $this->createMock(AnonymisationBackendService::class)
			),
			logger: $this->createMock(LoggerInterface::class)
		);

		$probe = new ProbeResult(reachable: true, latencyMs: 1, error: null, probedAt: '2026-11-14T09:00:00+00:00');
		$backends = $this->createMock(AnonymisationBackendService::class);
		$backends->method('getState')->willReturn(
			new BackendState(
				entityRecognitionEnabled: true,
				activeMethod: 'regex',
				effectiveMethod: 'regex',
				backends: ['regex' => new BackendInfo(name: 'regex', configured: true, probe: $probe, detector: $record)]
			)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00+00:00'));

		$service = new DetectorEvaluationService($handler, $backends, $this->inMemoryAppConfig(), $time);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(DetectorEvaluationService::class)->willReturn($service);

		return new CommandTester(new EvaluateDetectorCommand($container));
	}//end tester()

	/**
	 * The stored evaluation of the regex backend.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return json_decode($this->configValues['openregister/' . DetectorEvaluationStore::CONFIG_PREFIX . 'regex'], true);
	}//end stored()

	public function testTheRegexBackendIsMeasuredWithKnownFigures(): void {
		$tester = $this->tester(record: self::regexRecord('1.0.0'));

		$this->assertSame(0, $tester->execute(['--corpus' => self::CORPUS, '--json' => true]));

		$stored = $this->stored();
		$this->assertSame('three-documents', $stored['corpus']);
		$this->assertSame('1', $stored['corpusVersion']);
		$this->assertSame('regex', $stored['method']);
		$this->assertSame('1.0.0', $stored['detector']['patternSetVersion']);
		$this->assertSame('2026-11-14T10:00:00+00:00', $stored['measuredAt']);
		// One gold address found, plus an unannotated one: half of what it found is right.
		$this->assertSame(['precision' => 0.5, 'recall' => 1.0, 'support' => 1, 'truePositives' => 1, 'falsePositives' => 1, 'falseNegatives' => 0], $stored['types']['EMAIL']);
		$this->assertSame(['precision' => 1.0, 'recall' => 1.0, 'support' => 1, 'truePositives' => 1, 'falsePositives' => 0, 'falseNegatives' => 0], $stored['types']['IBAN']);
		$this->assertSame(['precision' => 1.0, 'recall' => 1.0, 'support' => 2, 'truePositives' => 2, 'falsePositives' => 0, 'falseNegatives' => 0], $stored['types']['BSN']);
		// The regex backend finds no names: no precision at all, and nothing recalled.
		$this->assertSame(['precision' => null, 'recall' => 0.0, 'support' => 3, 'truePositives' => 0, 'falsePositives' => 0, 'falseNegatives' => 3], $stored['types']['PERSON']);
		$this->assertSame(json_encode($stored, JSON_PRESERVE_ZERO_FRACTION), trim($tester->getDisplay()));
	}

	public function testTheTableNamesTheCorpusAndEachType(): void {
		$tester = $this->tester(record: self::regexRecord('1.0.0'));

		$this->assertSame(0, $tester->execute(['--corpus' => self::CORPUS, '--method' => 'regex']));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('regex over three-documents version 1', $display);
		$this->assertMatchesRegularExpression('/EMAIL\s+precision 50\.0%\s+recall 100\.0%\s+support 1/', $display);
		$this->assertMatchesRegularExpression('/PERSON\s+precision n\/a\s+recall 0\.0%\s+support 3/', $display);
	}

	public function testFiguresForAnotherDetectorVersionAreNotCurrent(): void {
		$this->tester(record: self::regexRecord('1.0.0'))->execute(['--corpus' => self::CORPUS]);
		$store = new DetectorEvaluationStore($this->inMemoryAppConfig());

		$same = $store->reportFor('regex', self::regexRecord('1.0.0')->withChangedAt('2026-11-20'));
		$changed = $store->reportFor('regex', self::regexRecord('2.0.0'));

		$this->assertTrue($same['current']);
		$this->assertArrayHasKey('types', $same);
		$this->assertFalse($changed['current']);
		$this->assertArrayNotHasKey('types', $changed);
		$this->assertSame('1.0.0', $changed['detector']['patternSetVersion']);
		$this->assertNull($store->reportFor('presidio', self::regexRecord('1.0.0')));
	}

	public function testAMissingCorpusFailsAndStoresNothing(): void {
		$tester = $this->tester(record: self::regexRecord('1.0.0'));

		$this->assertSame(1, $tester->execute(['--corpus' => '/nonexistent-corpus']));
		$this->assertStringContainsString('No corpus at', $tester->getDisplay());
		$this->assertSame([], $this->configValues);
	}

	public function testAnUnknownBackendIsRefused(): void {
		$tester = $this->tester(record: self::regexRecord('1.0.0'));

		$this->assertSame(1, $tester->execute(['--corpus' => self::CORPUS, '--method' => 'nope']));
		$this->assertStringContainsString('No backend is called "nope".', $tester->getDisplay());
		$this->assertSame([], $this->configValues);
	}
}
