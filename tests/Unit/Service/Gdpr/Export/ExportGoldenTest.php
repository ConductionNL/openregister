<?php

/**
 * Golden test for the DSAR export bundle, its one-time token and the subject export.
 *
 * Pins, on the code as it stood before the A15 maintainability refactor, every
 * allowed and every refused outcome: the case is loaded (RBAC) before anything
 * is assembled or minted; a download checks the case BEFORE it redeems (so a
 * refused case does not burn a token) and redeems BEFORE it assembles; a token
 * is burned on first sight whatever the verdict; the exact PDF bytes, the
 * dossier shape, and the subject export's reach and expiry refusals.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Export
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dsar-export-bundle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Gdpr\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SubjectExport;
use OCA\OpenRegister\Db\SubjectExportMapper;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportDossierSources;
use OCA\OpenRegister\Service\Gdpr\Export\OneTimeDownloadTokenStore;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportRequest;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportQueue;
use OCA\OpenRegister\Service\Gdpr\Export\UnsignedPadesSigner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class ExportGoldenTest extends TestCase {
	/** @var array<int, string> Ordered log of collaborator calls. */
	private array $calls = [];

	/** @var array<int, array{0: string, 1: string, 2: array}> Every log line written. */
	private array $logs = [];

	/** @var array<string, string> In-memory app config. */
	private array $config = [];

	/** @var int The fixed clock. */
	private int $now = 1000000;

	protected function setUp(): void {
		$this->calls = [];
		$this->logs = [];
		$this->config = [];
		$this->now = 1000000;
	}//end setUp()

	private function logger(): AbstractLogger {
		$logs = &$this->logs;

		return new class ($logs) extends AbstractLogger {
			/** @var array<int, array> */
			private array $sink;

			public function __construct(array &$sink) {
				$this->sink = &$sink;
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->sink[] = [(string)$level, (string)$message, $context];
			}
		};
	}//end logger()

	private function caseEntity(array $data): ObjectEntity {
		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$case->setObject($data);

		return $case;
	}//end caseEntity()

	private function bundleService(?ObjectEntity $case, bool $redeems = true, ?array $trail = []): ExportBundleService {
		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturnCallback(
			function (string $caseUuid) use ($case): ?ObjectEntity {
				$this->calls[] = 'load:' . $caseUuid;

				return $case;
			}
		);
		$accessor->method('save')->willReturnCallback(
			function (ObjectEntity $saved, array $data): ObjectEntity {
				$this->calls[] = 'save:' . json_encode($data);

				return $saved;
			}
		);

		$dsr = $this->createMock(DataSubjectRequestService::class);
		$dsr->method('assembleAccessExport')->willReturnCallback(
			function (string $subjectId, ?string $type = null): array {
				$this->calls[] = 'assemble:' . $subjectId . ':' . var_export($type, true);

				return ['subject' => $subjectId . ' (a\\b)', 'objectCount' => 7, 'generatedAt' => '2026-10-01T00:00:00+00:00'];
			}
		);

		$tokens = $this->createMock(OneTimeDownloadTokenStore::class);
		$tokens->method('mint')->willReturnCallback(
			function (string $caseUuid): string {
				$this->calls[] = 'mint:' . $caseUuid;

				return 'RAW-TOKEN';
			}
		);
		$tokens->method('redeem')->willReturnCallback(
			function (string $token, string $caseUuid) use ($redeems): bool {
				$this->calls[] = 'redeem:' . $caseUuid;

				return $redeems;
			}
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		if ($trail === null) {
			$audit->method('findByObjectUntil')->willThrowException(new RuntimeException('trail down'));
		} else {
			$audit->method('findByObjectUntil')->willReturn($trail);
		}

		return new ExportBundleService(new ExportDossierSources($dsr, $accessor, $audit), new UnsignedPadesSigner(), $tokens, $this->logger());
	}//end bundleService()

	public function testGenerateRefusesAnUnreachableCaseBeforeAnything(): void {
		foreach (['generate', 'assembleRegulatorDossier'] as $method) {
			$this->calls = [];
			try {
				$this->bundleService(null)->$method('case-x');
				self::fail('An unreachable case must refuse.');
			} catch (RuntimeException $e) {
				self::assertSame('Case "case-x" not found or not authorised.', $e->getMessage());
			}

			self::assertSame(['load:case-x'], $this->calls);
		}
	}//end testGenerateRefusesAnUnreachableCaseBeforeAnything()

	public function testGenerateAssemblesSignsRecordsAndMints(): void {
		$data = ['subjectId' => 'jan@example.org', 'subjectType' => 'email', 'status' => 'open'];
		$result = $this->bundleService($this->caseEntity($data))->generate('case-1');

		$hash = 'sha256:b7c2cb5888c4a992df75a8321e1bed517d10e54d281d33f5549d903a8cc5bd67';
		self::assertSame(
			[
				'caseUuid' => 'case-1',
				'contentHash' => $hash,
				'signed' => false,
				'signatureState' => UnsignedPadesSigner::STATE_PENDING_LIBRARY,
				'downloadToken' => 'RAW-TOKEN',
			],
			$result
		);
		$saved = array_merge(
			['id' => 'case-1'],
			$data,
			['lastBundle' => ['contentHash' => $hash, 'signed' => false, 'signatureState' => UnsignedPadesSigner::STATE_PENDING_LIBRARY]]
		);
		self::assertSame(
			['load:case-1', "assemble:jan@example.org:'email'", 'save:' . json_encode($saved), 'mint:case-1'],
			$this->calls
		);
	}//end testGenerateAssemblesSignsRecordsAndMints()

	public function testSubjectTypeIsOptional(): void {
		$cases = [
			'assemble::NULL' => ['subjectType' => ''],
			'assemble::NULL ' => [],
			"assemble:12:'5'" => ['subjectType' => 5, 'subjectId' => 12],
		];
		foreach ($cases as $expected => $data) {
			$this->calls = [];
			$this->bundleService($this->caseEntity($data))->generate('case-1');
			self::assertSame(trim($expected), $this->calls[1]);
		}
	}//end testSubjectTypeIsOptional()

	public function testDownloadChecksCaseThenTokenThenAssembles(): void {
		$case = $this->caseEntity(['subjectId' => 'jan@example.org']);

		self::assertNull($this->bundleService(null)->download('case-1', 'RAW-TOKEN'));
		// A case the caller cannot reach does not burn the token.
		self::assertSame(['load:case-1'], $this->calls);

		$this->calls = [];
		self::assertNull($this->bundleService($case, false)->download('case-1', 'RAW-TOKEN'));
		self::assertSame(['load:case-1', 'redeem:case-1'], $this->calls);

		$this->calls = [];
		$bundle = $this->bundleService($case)->download('case-1', 'RAW-TOKEN');
		self::assertSame(['load:case-1', 'redeem:case-1', 'assemble:jan@example.org:NULL'], $this->calls);
		self::assertSame(
			"%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
			. "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
			. "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n"
			. "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n"
			. "5 0 obj\n<< /Length 156 >>\nstream\nBT /F1 12 Tf 40 720 Td (GDPR Access Disclosure - case case-1 - subject "
			. "jan@example.org \\(a\\\\b\\) - 7 object\\(s\\) - generated 2026-10-01T00:00:00+00:00) Tj ET\nendstream\nendobj\n"
			. "xref\n0 6\n0000000000 65535 f \n"
			. "0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000241 00000 n \n0000000311 00000 n \n"
			. "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n518\n%%EOF",
			$bundle->getBytes()
		);
		self::assertSame('sha256:b7c2cb5888c4a992df75a8321e1bed517d10e54d281d33f5549d903a8cc5bd67', $bundle->getContentHash());
	}//end testDownloadChecksCaseThenTokenThenAssembles()

	public function testDossierReflectsTheCaseAndSurvivesAnUnreadableTrail(): void {
		$entry = new AuditTrail();
		$entry->setAction('update');
		$data = [
			'subjectId' => 'jan@example.org',
			'status' => 'closed',
			'evidence' => ['a' => ['id' => 1], 'b' => ['id' => 2]],
			'redactions' => 'not-a-list',
		];

		$dossier = $this->bundleService($this->caseEntity($data), true, [$entry])->assembleRegulatorDossier('case-1');
		self::assertSame(['caseUuid', 'subjectId', 'status', 'evidence', 'redactions', 'history'], array_keys($dossier));
		self::assertSame('case-1', $dossier['caseUuid']);
		self::assertSame('jan@example.org', $dossier['subjectId']);
		self::assertSame('closed', $dossier['status']);
		self::assertSame([['id' => 1], ['id' => 2]], $dossier['evidence']);
		self::assertSame([], $dossier['redactions']);
		self::assertSame([$entry->jsonSerialize()], $dossier['history']);
		self::assertSame([], $this->logs);

		$dossier = $this->bundleService($this->caseEntity([]), true, null)->assembleRegulatorDossier('case-1');
		self::assertSame(
			['caseUuid' => 'case-1', 'subjectId' => '', 'status' => '', 'evidence' => [], 'redactions' => [], 'history' => []],
			$dossier
		);
		self::assertSame(
			[['warning', '[ExportBundleService] dossier history read failed for case "case-1": trail down', []]],
			$this->logs
		);
	}//end testDossierReflectsTheCaseAndSurvivesAnUnreadableTrail()

	private function tokenStore(): OneTimeDownloadTokenStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->calls[] = 'set:' . $app . ':' . $key;
				$this->config[$key] = $value;

				return true;
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->calls[] = 'get:' . $app;

				return ($this->config[$key] ?? $default);
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				$this->calls[] = 'delete:' . $app;
				unset($this->config[$key]);
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length, string $chars): string {
				$this->calls[] = 'random:' . $length . ':' . str_replace(ISecureRandom::CHAR_ALPHANUMERIC, 'alnum', $chars);

				return 'T' . count($this->calls);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new OneTimeDownloadTokenStore($appConfig, $random, $time);
	}//end tokenStore()

	public function testMintStoresOnlyTheHashBoundToCaseAndExpiry(): void {
		$store = $this->tokenStore();
		$token = $store->mint('case-1');
		$key = 'dsar_bundle_token_' . hash('sha256', $token);

		self::assertSame(['random:64:alnum', 'set:openregister:' . $key], $this->calls);
		self::assertSame([$key => '{"caseUuid":"case-1","expiry":1000900}'], $this->config);

		$store->mint('case-2', 10);
		self::assertSame('{"caseUuid":"case-2","expiry":1000010}', end($this->config));
	}//end testMintStoresOnlyTheHashBoundToCaseAndExpiry()

	public function testRedeemBurnsOnFirstSightAndFailsClosed(): void {
		$store = $this->tokenStore();

		self::assertFalse($store->redeem('', 'case-1'));
		self::assertSame([], $this->calls);

		self::assertFalse($store->redeem('unknown', 'case-1'));
		self::assertSame(['get:openregister'], $this->calls);

		$cases = [
			'wrong case' => ['{"caseUuid":"case-2","expiry":2000000}', false],
			'no case' => ['{"expiry":2000000}', false],
			'malformed' => ['not json', false],
			'scalar json' => ['42', false],
			'expired' => ['{"caseUuid":"case-1","expiry":999999}', false],
			'expires now' => ['{"caseUuid":"case-1","expiry":1000000}', true],
			'valid' => ['{"caseUuid":"case-1","expiry":1000900}', true],
			'no expiry' => ['{"caseUuid":"case-1"}', false],
		];
		foreach ($cases as $label => [$record, $granted]) {
			$this->calls = [];
			$this->config = ['dsar_bundle_token_' . hash('sha256', 'tok') => $record];
			self::assertSame($granted, $store->redeem('tok', 'case-1'), $label);
			// Burned before the verdict, whatever it was.
			self::assertSame(['get:openregister', 'delete:openregister'], $this->calls, $label);
			self::assertSame([], $this->config, $label);
			self::assertFalse($store->redeem('tok', 'case-1'), $label . ' replay');
		}
	}//end testRedeemBurnsOnFirstSightAndFailsClosed()

	private function export(string $status, ?DateTime $expiresAt, string $owner = 'handler'): SubjectExport {
		$export = new SubjectExport();
		$export->setUuid('export-1');
		$export->setSubject('jan@example.org');
		$export->setSubjectType('email');
		$export->setRequestId('DSR-1');
		$export->setStatus($status);
		$export->setRequestedBy($owner);
		$export->setExpiresAt($expiresAt);

		return $export;
	}//end export()

	private function subjectService(?SubjectExport $row, ?string $uid, bool $admin = false, bool $throwing = false): SubjectExportService {
		$mapper = $this->createMock(SubjectExportMapper::class);
		if ($row === null) {
			$mapper->method('findByUuid')->willThrowException(new DoesNotExistException('no such row'));
		} else {
			$mapper->method('findByUuid')->willReturn($row);
		}

		$mapper->method('createFromArray')->willReturnCallback(
			function (array $data): SubjectExport {
				$this->calls[] = 'create:' . json_encode($data);
				$created = new SubjectExport();
				$created->hydrate($data);
				$created->setUuid('export-new');

				return $created;
			}
		);
		$mapper->method('save')->willReturnCallback(
			function (SubjectExport $export): SubjectExport {
				$this->calls[] = 'save:' . $export->getStatus();

				return $export;
			}
		);

		$subjects = $this->createMock(DataSubjectRequestService::class);
		$subjects->method('assembleAccessExport')->willReturnCallback(
			function (string $subjectId, ?string $type = null) use ($throwing): array {
				$this->calls[] = 'assemble:' . $subjectId . ':' . $type;
				if ($throwing === true) {
					throw new RuntimeException('register unreadable');
				}

				return ['subject' => $subjectId, 'objectCount' => 3, 'objects' => [['a' => 'é/x']], 'generatedAt' => 'now'];
			}
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				$this->calls[] = 'queue:' . $job . ':' . json_encode($argument);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $id) use ($admin): bool {
				$this->calls[] = 'isAdmin:' . $id;

				return $admin;
			}
		);

		return new SubjectExportService(new SubjectExportQueue($mapper, $subjects, $jobList), $session, $groups, $this->logger());
	}//end subjectService()

	public function testRequestQueuesAndAuditsBothNames(): void {
		$export = $this->subjectService(null, null)->requestFor(new SubjectExportRequest('  jan@example.org ', 'email', 'DSR-1'));

		self::assertSame('export-new', $export->getUuid());
		self::assertSame(
			[
				'create:{"subject":"jan@example.org","subjectType":"email","requestId":"DSR-1","status":"pending","requestedBy":"system"}',
				'queue:OCA\\OpenRegister\\BackgroundJob\\SubjectExportJob:{"uuid":"export-new"}',
			],
			$this->calls
		);
		self::assertSame('info', $this->logs[0][0]);
		self::assertSame('[SubjectExport] requested', $this->logs[0][1]);
		self::assertSame(
			['action', 'event', 'export', 'requestedBy', 'dataSubject', 'dataSubjectRequest', 'objectCount', 'expiresAt'],
			array_keys($this->logs[0][2])
		);
	}//end testRequestQueuesAndAuditsBothNames()

	public function testAssembleMarksReadyOrFailedAndSkipsAMissingRow(): void {
		self::assertNull($this->subjectService(null, 'handler')->assemble('gone'));
		self::assertSame([], $this->calls);
		self::assertSame('warning', $this->logs[0][0]);
		self::assertSame('gone', $this->logs[0][2]['uuid']);

		$this->calls = [];
		$this->logs = [];
		$ready = $this->subjectService($this->export(SubjectExport::STATUS_PENDING, null), 'handler')->assemble('export-1');
		self::assertSame(SubjectExport::STATUS_READY, $ready->getStatus());
		self::assertSame(3, $ready->getObjectCount());
		self::assertSame(
			hash('sha256', (string)json_encode(['subject' => 'jan@example.org', 'objectCount' => 3, 'objects' => [['a' => 'é/x']]])),
			$ready->getContentHash()
		);
		self::assertSame(
			SubjectExport::DEFAULT_TTL_SECONDS,
			($ready->getExpiresAt()->getTimestamp() - $ready->getReadyAt()->getTimestamp())
		);
		self::assertNull($ready->getError());
		self::assertSame(['assemble:jan@example.org:email', 'save:ready'], $this->calls);
		self::assertSame('[SubjectExport] ready', $this->logs[0][1]);

		$this->calls = [];
		$failedRow = $this->export(SubjectExport::STATUS_PENDING, null);
		$failed = $this->subjectService($failedRow, 'handler', false, true)->assemble('export-1');
		self::assertSame(SubjectExport::STATUS_FAILED, $failed->getStatus());
		self::assertSame('register unreadable', $failed->getError());
		self::assertNull($failed->getReadyAt());
		self::assertSame(['assemble:jan@example.org:email', 'save:failed'], $this->calls);
	}//end testAssembleMarksReadyOrFailedAndSkipsAMissingRow()

	public function testLoadAnswersAbsentForStrangers(): void {
		$future = new DateTime('2999-01-01T00:00:00+00:00');
		self::assertNull($this->subjectService(null, 'handler')->load('x'));
		self::assertNull($this->subjectService($this->export(SubjectExport::STATUS_READY, $future), null)->load('export-1'));
		self::assertNull($this->subjectService($this->export(SubjectExport::STATUS_READY, $future), 'stranger')->load('export-1'));
		self::assertNotNull($this->subjectService($this->export(SubjectExport::STATUS_READY, $future), 'boss', true)->load('export-1'));
		self::assertNotNull($this->subjectService($this->export(SubjectExport::STATUS_READY, $future), 'handler')->load('export-1'));
		self::assertSame(['isAdmin:stranger', 'isAdmin:boss', 'isAdmin:handler'], $this->calls);
	}//end testLoadAnswersAbsentForStrangers()

	public function testDownloadRefusesBeforeAssemblingAndAuditsDelivery(): void {
		$at = new DateTime('2026-10-01T00:00:00+00:00');
		$refused = [
			$this->export(SubjectExport::STATUS_READY, new DateTime('2026-10-01T00:00:00+00:00')),
			$this->export(SubjectExport::STATUS_READY, null),
			$this->export(SubjectExport::STATUS_PENDING, new DateTime('2026-11-01T00:00:00+00:00')),
		];
		foreach ($refused as $row) {
			$this->calls = [];
			self::assertNull($this->subjectService($row, 'handler')->download('export-1', $at));
			self::assertSame(['isAdmin:handler'], $this->calls);
		}

		$this->calls = [];
		$this->logs = [];
		$row = $this->export(SubjectExport::STATUS_READY, new DateTime('2026-11-01T00:00:00+00:00'));
		$bytes = $this->subjectService($row, 'handler')->download('export-1', $at);
		self::assertSame(
			"{\n    \"subject\": \"jan@example.org\",\n    \"objectCount\": 3,\n"
			. "    \"objects\": [\n        {\n            \"a\": \"é/x\"\n        }\n    ],\n    \"generatedAt\": \"now\"\n}",
			$bytes
		);
		self::assertNotNull($row->getDeliveredAt());
		self::assertSame(['isAdmin:handler', 'assemble:jan@example.org:email', 'save:ready'], $this->calls);
		self::assertSame('[SubjectExport] delivered', $this->logs[0][1]);
		self::assertSame('2026-11-01T00:00:00+00:00', $this->logs[0][2]['expiresAt']);
	}//end testDownloadRefusesBeforeAssemblingAndAuditsDelivery()
}//end class
