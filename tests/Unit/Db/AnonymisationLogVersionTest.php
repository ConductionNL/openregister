<?php

/**
 * An anonymisation run stores the version record of the detection backend
 * that ran on its AnonymisationLog row (REQ-ADI-001).
 *
 * Drives the real DocumentProcessingHandler::anonymize() call path, not the
 * entity alone: the record has to reach the row the handler inserts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlPatternSet;
use OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendServiceInterface;
use OCA\OpenRegister\Service\Anonymisation\BackendInfo;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\Anonymisation\DetectorVersion;
use OCA\OpenRegister\Service\Anonymisation\DetectorVersionReader;
use OCA\OpenRegister\Service\Anonymisation\ProbeResult;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 * @covers \OCA\OpenRegister\Files\Db\AnonymisationLog
 * @uses \OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform
 * @uses \OCA\OpenRegister\Files\Service\File\DocumentRedactionServices
 * @uses \OCA\OpenRegister\Files\Service\File\DocumentReplacement
 * @uses \OCA\OpenRegister\Files\Service\File\PlaceholderIdTranslator
 * @uses \OCA\OpenRegister\Files\Service\File\PlaceholderLookup
 * @uses \OCA\OpenRegister\Files\Service\File\MaskForm
 * @uses \OCA\OpenRegister\Files\Service\File\PlaceholderText
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\WordReplacementRequest
 * @uses \OCA\OpenRegister\Service\Anonymisation\PipelineSteps
 * @uses \OCA\OpenRegister\Service\Anonymisation\BackendInfo
 * @uses \OCA\OpenRegister\Service\Anonymisation\BackendState
 * @uses \OCA\OpenRegister\Service\Anonymisation\DetectorVersion
 * @uses \OCA\OpenRegister\Service\Anonymisation\DetectorVersionReader
 * @uses \OCA\OpenRegister\Service\Anonymisation\ProbeResult
 * @uses \OCA\OpenRegister\Db\TypedFieldsJsonTrait
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 */
class AnonymisationLogVersionTest extends TestCase {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $warnings = [];

	public function testARunStoresTheBackendVersionRecord(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.2.150');
		$regex = (new DetectorVersionReader(appManager: $appManager))->forMethod(method: BackendState::METHOD_REGEX);

		$rows = $this->anonymise(state: $this->state(effective: BackendState::METHOD_REGEX, detector: $regex));

		$this->assertCount(1, $rows);
		$this->assertSame(
			[
				'backend' => 'regex',
				'engine' => DetectorVersionReader::PATTERN_ENGINE,
				'engineVersion' => '0.2.150',
				'model' => 'unknown',
				'modelVersion' => 'unknown',
				'patternSetVersion' => NlPatternSet::VERSION,
				'datasets' => [],
				'changedAt' => NlPatternSet::CHANGED_AT,
			],
			$rows[0]->getDetectorArray()
		);
		$this->assertSame($rows[0]->getDetectorArray(), $rows[0]->jsonSerialize()['detector']);
		$this->assertSame([], $this->warnings);
	}//end testARunStoresTheBackendVersionRecord()

	public function testTheRecordIsTheEffectiveBackendsNotTheActiveOnes(): void {
		$presidio = new DetectorVersion(fields: ['engine' => 'presidio', 'modelVersion' => '1.5', 'changedAt' => '2026-11-01T00:00:00+00:00']);
		$state = new BackendState(
			entityRecognitionEnabled: true,
			activeMethod: BackendState::METHOD_OPENANONYMISER,
			effectiveMethod: BackendState::METHOD_PRESIDIO,
			backends: [
				BackendState::METHOD_OPENANONYMISER => $this->info(method: BackendState::METHOD_OPENANONYMISER, detector: new DetectorVersion(fields: ['engine' => 'other'])),
				BackendState::METHOD_PRESIDIO => $this->info(method: BackendState::METHOD_PRESIDIO, detector: $presidio),
			]
		);

		$record = $this->anonymise(state: $state)[0]->getDetectorArray();

		$this->assertSame('presidio', $record['backend']);
		$this->assertSame('presidio', $record['engine']);
		$this->assertSame('1.5', $record['modelVersion']);
		$this->assertSame('2026-11-01T00:00:00+00:00', $record['changedAt']);
	}//end testTheRecordIsTheEffectiveBackendsNotTheActiveOnes()

	public function testAnEffectiveBackendWithoutARecordStoresNone(): void {
		$rows = $this->anonymise(state: $this->state(effective: BackendState::METHOD_REGEX, detector: null));

		$this->assertCount(1, $rows);
		$this->assertNull($rows[0]->getDetector());
		$this->assertNull($rows[0]->jsonSerialize()['detector']);
	}//end testAnEffectiveBackendWithoutARecordStoresNone()

	public function testAnUnreadableStateIsLoggedAndTheRunStillLogged(): void {
		$rows = $this->anonymise(state: new RuntimeException('probe cache down'));

		$this->assertCount(1, $rows);
		$this->assertSame(2, $rows[0]->getReplacements());
		$this->assertNull($rows[0]->getDetector());
		$this->assertSame(
			[['message' => 'DocumentProcessingHandler: detector version record unavailable', 'context' => ['error' => 'probe cache down']]],
			$this->warnings
		);
	}//end testAnUnreadableStateIsLoggedAndTheRunStillLogged()

	public function testWithoutTheBackendStateNoRecordIsStored(): void {
		$rows = $this->anonymise(state: null);

		$this->assertCount(1, $rows);
		$this->assertNull($rows[0]->getDetector());
	}//end testWithoutTheBackendStateNoRecordIsStored()

	public function testAStoredRecordThatIsNotAnObjectDecodesAsNone(): void {
		$log = new AnonymisationLog();
		$log->setDetector('"regex"');

		$this->assertNull($log->getDetectorArray());
	}//end testAStoredRecordThatIsNotAnObjectDecodesAsNone()

	private function state(string $effective, ?DetectorVersion $detector): BackendState {
		return new BackendState(
			entityRecognitionEnabled: false,
			activeMethod: $effective,
			effectiveMethod: $effective,
			backends: [$effective => $this->info(method: $effective, detector: $detector)]
		);
	}//end state()

	private function info(string $method, ?DetectorVersion $detector): BackendInfo {
		return new BackendInfo(
			name: $method,
			configured: true,
			probe: new ProbeResult(reachable: true, latencyMs: 3, error: null, probedAt: '2026-11-14T08:00:00+00:00'),
			detector: $detector
		);
	}//end info()

	/**
	 * Anonymise a small text file through the real handler.
	 *
	 * @param BackendState|RuntimeException|null $state The backend state the run reads, what reading it throws, or no state service.
	 *
	 * @return array<int, AnonymisationLog> The rows the handler inserted.
	 */
	private function anonymise(BackendState|RuntimeException|null $state): array {
		$this->warnings = [];
		$warnings = &$this->warnings;
		$logger = new class($warnings) extends AbstractLogger {
			/**
			 * @param array<int, array<string, mixed>> $warnings
			 */
			public function __construct(private array &$warnings) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				if ($level === 'warning') {
					$this->warnings[] = ['message' => (string)$message, 'context' => $context];
				}
			}
		};

		$rows = [];
		$logMapper = $this->createMock(AnonymisationLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(
			function (AnonymisationLog $entity) use (&$rows): AnonymisationLog {
				$rows[] = $entity;
				return $entity;
			}
		);

		$detectors = null;
		if ($state !== null) {
			$detectors = $this->createMock(AnonymisationBackendServiceInterface::class);
			if ($state instanceof RuntimeException) {
				$detectors->method('getState')->willThrowException($state);
			} else {
				$detectors->expects($this->once())->method('getState')->willReturn($state);
			}
		}

		$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$sanitizer->method('isSanitizable')->willReturn(false);

		$handler = new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(
				rootFolder: $this->createMock(IRootFolder::class),
				userSession: $this->createMock(IUserSession::class),
				l10n: null
			),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				sanitizer: $sanitizer,
				anonymisationLogMapper: $logMapper,
				detectors: $detectors
			),
			logger: $logger
		);

		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturn(false);
		$parent->method('newFile')->willReturnCallback(
			function (string $path): File {
				$out = $this->createMock(File::class);
				$out->method('getPath')->willReturn('/files/' . $path);
				return $out;
			}
		);
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(42);
		$node->method('getName')->willReturn('letter.txt');
		$node->method('getPath')->willReturn('/files/letter.txt');
		$node->method('getMimeType')->willReturn('text/plain');
		$node->method('getContent')->willReturn('Jan Jansen woont in Amsterdam.');
		$node->method('getParent')->willReturn($parent);

		$handler->anonymize(new DocumentAnonymisationRequest(
			node: $node,
			entities: [
				['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1'],
				['text' => 'Amsterdam', 'entityType' => 'LOCATION', 'key' => 'l1'],
			]
		));

		return $rows;
	}//end anonymise()
}//end class
