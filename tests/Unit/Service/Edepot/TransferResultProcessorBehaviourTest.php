<?php

/**
 * Behaviour tests for TransferResultProcessor: how transport results become
 * per-object outcomes and the transfer list's terminal status.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferAuditLog;
use OCA\OpenRegister\Service\Edepot\TransferContext;
use OCA\OpenRegister\Service\Edepot\TransferDelivery;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferOutcomeRecorder;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\TransferResultProcessor;
use OCA\OpenRegister\Service\Edepot\TransferSettings;
use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behaviour of the result processor of an e-Depot transfer.
 */
class TransferResultProcessorBehaviourTest extends TestCase {

	private const AT = '2026-01-02T03:04:05+00:00';

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function processor(): TransferResultProcessor {
		$auditLog = new TransferAuditLog(new \OCA\OpenRegister\Service\Edepot\AuditRecording($this->createMock(AuditTrailMapperInterface::class), $this->logger), new TransferSettings($this->createMock(IAppConfig::class), $this->logger), $this->createMock(INotificationManager::class));
		$records  = $this->createMock(TransferRecordService::class);
		$recorder = new TransferOutcomeRecorder(new \OCA\OpenRegister\Service\Edepot\TransferStores(objects: $this->createMock(MagicMapperInterface::class), records: $records, settings: $this->createMock(\OCP\IAppConfig::class), logger: $this->logger), $auditLog);
		return new TransferResultProcessor($recorder, $auditLog);
	}//end processor()

	private static function item(string $uuid): array {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRetention([]);
		return ['object' => $object, 'files' => []];
	}//end item()

	private static function context(): TransferContext {
		return new TransferContext('tl-1', 'zip', 'rest', self::AT);
	}//end context()

	private static function list(): array {
		return ['uuid' => 'tl-1', 'objectReferences' => [['uuid' => 'a'], ['uuid' => 'b']], 'status' => 'in_progress'];
	}//end list()

	public function testPerObjectResultsDecideAPartialFailure(): void {
		$a       = self::item('a');
		$b       = self::item('b');
		$results = [
			new TransportResult('T1', ['a' => ['accepted' => true, 'reference' => 'R-a', 'error' => null]]),
			new TransportResult('T2', ['b' => ['accepted' => false, 'reference' => null, 'error' => 'bad b']]),
		];
		$this->logger->expects($this->never())->method('error');

		$out = $this->processor()->process(self::list(), new TransferDelivery($results, [$a, $b], self::context()));

		$this->assertSame(TransferListService::STATUS_PARTIALLY_FAILED, $out['status']);
		$this->assertSame(self::AT, $out['transferResult']['completedAt']);
		$this->assertSame(['a', 'b'], array_keys($out['transferResult']['objectResults']));
		$this->assertSame('transferred', $a['object']->getRetention()['archiefstatus']);
		$this->assertSame([['error' => 'bad b', 'timestamp' => self::AT]], $b['object']->getRetention()['transferErrors']);
	}

	public function testAnObjectWithoutAResultIsRefusedAsNoResult(): void {
		$a       = self::item('a');
		$b       = self::item('b');
		$results = [new TransportResult('T1', ['a' => ['accepted' => true, 'reference' => 'R-a']])];

		$out = $this->processor()->process(self::list(), new TransferDelivery($results, [$a, $b], self::context()));

		$this->assertSame(TransferListService::STATUS_PARTIALLY_FAILED, $out['status']);
		$this->assertSame('No result', $b['object']->getRetention()['transferErrors'][0]['error']);
	}

	public function testARefusalWithoutErrorTextIsRecordedAsUnknownError(): void {
		$a       = self::item('a');
		$results = [new TransportResult('T1', ['a' => ['accepted' => false]])];

		$out = $this->processor()->process(self::list(), new TransferDelivery($results, [$a], self::context()));

		$this->assertSame(TransferListService::STATUS_FAILED, $out['status']);
		$this->assertSame('Unknown error', $a['object']->getRetention()['transferErrors'][0]['error']);
	}

	public function testAnOverallSuccessAcceptsEveryObjectWithTheTransferReference(): void {
		$a = self::item('a');
		$b = self::item('b');

		$out = $this->processor()->process(self::list(), new TransferDelivery([new TransportResult('PKG-1')], [$a, $b], self::context()));

		$this->assertSame(TransferListService::STATUS_COMPLETED, $out['status']);
		$this->assertSame(
			[
				'a' => ['accepted' => true, 'reference' => 'PKG-1', 'error' => null],
				'b' => ['accepted' => true, 'reference' => 'PKG-1', 'error' => null],
			],
			$out['transferResult']['objectResults']
		);
		$this->assertSame('PKG-1', $b['object']->getRetention()['eDepotReferentie']);
	}

	public function testAnOverallSuccessWithoutReferenceUsesAnEmptyOne(): void {
		$a = self::item('a');

		$out = $this->processor()->process(self::list(), new TransferDelivery([new TransportResult()], [$a], self::context()));

		$this->assertSame('', $out['transferResult']['objectResults']['a']['reference']);
	}

	public function testAnOverallFailureRefusesEveryObjectAndAuditsTheJoinedErrors(): void {
		$a       = self::item('a');
		$results = [(new TransportResult('X'))->failed('first'), (new TransportResult())->failed('second')];
		$this->logger->expects($this->once())->method('error')->with(
			'[EdepotTransferService] Audit: archival.transfer_failed',
			$this->callback(static fn (array $ctx): bool => $ctx['error'] === 'first; second' && $ctx['transferUuid'] === 'tl-1' && $ctx['failedCount'] === 2)
		);

		$out = $this->processor()->process(self::list(), new TransferDelivery($results, [$a], self::context()));

		$this->assertSame(TransferListService::STATUS_FAILED, $out['status']);
		$this->assertSame(['accepted' => false, 'reference' => null, 'error' => 'first'], $out['transferResult']['objectResults']['a']);
	}

	public function testOneFailedPackageAmongSuccessesFailsTheWholeOverallTransfer(): void {
		$a       = self::item('a');
		$results = [new TransportResult('OK'), (new TransportResult('NO'))->failed('late')];

		$out = $this->processor()->process(self::list(), new TransferDelivery($results, [$a], self::context()));

		$this->assertSame(TransferListService::STATUS_FAILED, $out['status']);
	}

	public function testNoObjectsCompleteTrivially(): void {
		$out = $this->processor()->process(self::list(), new TransferDelivery([new TransportResult('P')], [], self::context()));

		$this->assertSame(TransferListService::STATUS_COMPLETED, $out['status']);
		$this->assertSame([], $out['transferResult']['objectResults']);
	}

	public function testFirstErrorSkipsResultsWithoutAnError(): void {
		$this->assertNull(TransferResultProcessor::firstError([]));
		$this->assertNull(TransferResultProcessor::firstError([new TransportResult('A')]));
		$this->assertSame(
			'b',
			TransferResultProcessor::firstError([new TransportResult('A'), (new TransportResult())->failed('b'), (new TransportResult())->failed('c')])
		);
	}
}//end class
