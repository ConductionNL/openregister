<?php

/**
 * Behaviour tests for TransferOutcomeRecorder: what an accepted or refused
 * object writes, and how write and proof failures are absorbed.
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
use OCA\OpenRegister\Service\Edepot\TransferOutcomeRecorder;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\TransferSettings;
use OCA\OpenRegister\Tests\Support\AuditChangeCalls;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behaviour of the outcome recorder of an e-Depot transfer.
 */
class TransferOutcomeRecorderBehaviourTest extends TestCase {

	private const AT = '2026-01-02T03:04:05+00:00';

	/** @var MagicMapperInterface&MockObject */
	private MagicMapperInterface $mapper;

	/** @var TransferRecordService&MockObject */
	private TransferRecordService $records;

	/** @var AuditTrailMapperInterface&MockObject */
	private AuditTrailMapperInterface $auditTrail;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->mapper     = $this->createMock(MagicMapperInterface::class);
		$this->records    = $this->createMock(TransferRecordService::class);
		$this->auditTrail = $this->createMock(AuditTrailMapperInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function recorder(): TransferOutcomeRecorder {
		$auditLog = new TransferAuditLog(new \OCA\OpenRegister\Service\Edepot\AuditRecording($this->auditTrail, $this->logger), new TransferSettings($this->createMock(IAppConfig::class), $this->logger), $this->createMock(INotificationManager::class));
		return new TransferOutcomeRecorder(new \OCA\OpenRegister\Service\Edepot\TransferStores(objects: $this->mapper, records: $this->records, settings: $this->createMock(\OCP\IAppConfig::class), logger: $this->logger), $auditLog);
	}//end recorder()

	private static function object(array $retention=[]): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRetention($retention);
		return $object;
	}//end object()

	private static function context(mixed $transferUuid='tl-1'): TransferContext {
		return new TransferContext($transferUuid, 'bagit', 'sftp', self::AT);
	}//end context()

	public function testRecordRefusedStartsAnErrorListAndSaves(): void {
		$object = self::object(['archiefstatus' => 'active']);
		$this->mapper->expects($this->once())->method('update')->with($object);

		$this->recorder()->recordRefused($object, 'nope', self::AT);

		$this->assertSame(
			['archiefstatus' => 'active', 'transferErrors' => [['error' => 'nope', 'timestamp' => self::AT]]],
			$object->getRetention()
		);
	}

	public function testRecordRefusedAppendsToAnExistingErrorList(): void {
		$object = self::object(['transferErrors' => [['error' => 'old', 'timestamp' => 't0']]]);

		$this->recorder()->recordRefused($object, 'new', self::AT);

		$this->assertSame(
			[['error' => 'old', 'timestamp' => 't0'], ['error' => 'new', 'timestamp' => self::AT]],
			$object->getRetention()['transferErrors']
		);
	}

	public function testRecordRefusedReplacesAScalarErrorField(): void {
		$object = self::object(['transferErrors' => 'corrupt']);

		$this->recorder()->recordRefused($object, 'new', self::AT);

		$this->assertSame([['error' => 'new', 'timestamp' => self::AT]], $object->getRetention()['transferErrors']);
	}

	public function testRecordRefusedLogsAFailedSaveInsteadOfThrowing(): void {
		$this->mapper->method('update')->willThrowException(new \RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'[EdepotTransferService] Failed to update object transfer error',
			['uuid' => 'obj-1', 'error' => 'db down']
		);

		$this->recorder()->recordRefused(self::object(), 'nope', self::AT);
	}

	public function testRecordAcceptedMarksTransferredAuditsAndLinksTheProof(): void {
		$object = self::object(['keep' => 1]);
		$files  = [
			['name' => 'a.pdf', 'checksum' => 'abc'],
			['name' => '', 'checksum' => 'skipped'],
			'junk',
			['name' => 'b.txt'],
		];
		$expectedChecksums = [['name' => 'a.pdf', 'sha256' => 'abc'], ['name' => 'b.txt', 'sha256' => '']];

		$this->auditTrail->expects($this->once())->method('recordChange')
			->with(AuditChangeCalls::matching([$object, $object, 'archival.transferred']));
		$this->records->expects($this->once())->method('createProof')->with(
			[
				'objectUuid' => 'obj-1',
				'transferUuid' => 'tl-1',
				'eDepotReference' => 'REF-9',
				'transportReceipt' => 'RCPT',
				'transportName' => 'sftp',
				'packageId' => 'tl-1',
				'packageFormat' => 'bagit',
				'packageManifestSha256' => hash('sha256', (string)json_encode($expectedChecksums)),
				'fileChecksums' => $expectedChecksums,
				'confirmedAt' => self::AT,
			]
		)->willReturn(['uuid' => 'proof-7']);
		$this->mapper->expects($this->exactly(2))->method('update')->with($object);

		$this->recorder()->recordAccepted(
			['object' => $object, 'files' => $files],
			['reference' => 'REF-9', 'receipt' => 'RCPT'],
			self::context()
		);

		$this->assertSame(
			[
				'keep' => 1,
				'archiefstatus' => 'transferred',
				'eDepotReferentie' => 'REF-9',
				'transferDate' => self::AT,
				'transferProof' => 'proof-7',
			],
			$object->getRetention()
		);
	}

	public function testReceiptFallsBackToReferenceAndAProofWithoutUuidIsNotLinked(): void {
		$object = self::object();
		$this->records->expects($this->once())->method('createProof')
			->with($this->callback(static fn (array $proof): bool => $proof['transportReceipt'] === 'REF-1' && $proof['fileChecksums'] === []))
			->willReturn([]);
		$this->mapper->expects($this->once())->method('update');

		$this->recorder()->recordAccepted(['object' => $object], ['reference' => 'REF-1'], self::context());

		$this->assertArrayNotHasKey('transferProof', $object->getRetention());
	}

	public function testAFailingProofIsLoggedAndTheObjectStaysTransferred(): void {
		$object = self::object();
		$this->records->method('createProof')->willThrowException(new \RuntimeException('proof store down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[EdepotTransferService] proof-of-transfer creation failed for obj-1: proof store down',
			$this->anything()
		);

		$this->recorder()->recordAccepted(['object' => $object, 'files' => []], [], self::context());

		$this->assertSame('transferred', $object->getRetention()['archiefstatus']);
		$this->assertSame('', $object->getRetention()['eDepotReferentie']);
	}

	public function testAFailedStatusSaveIsLoggedAndTheProofIsStillCreated(): void {
		$object = self::object();
		$this->mapper->method('update')->willThrowException(new \RuntimeException('locked'));
		$this->logger->expects($this->once())->method('error')->with(
			'[EdepotTransferService] Failed to update object status to overgebracht',
			['uuid' => 'obj-1', 'error' => 'locked']
		);
		$this->records->expects($this->once())->method('createProof')->willReturn(['uuid' => '']);

		$this->recorder()->recordAccepted(['object' => $object], ['reference' => 'R'], self::context());
	}
}//end class
