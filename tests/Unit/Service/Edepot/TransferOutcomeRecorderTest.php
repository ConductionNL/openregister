<?php

/**
 * Tests for TransferOutcomeRecorder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
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
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferOutcomeRecorder
 */
class TransferOutcomeRecorderTest extends TestCase {

	/**
	 * Logged messages by level.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $logged = [];

	/**
	 * A recorder whose mapper and proof store fail as asked.
	 *
	 * @param bool $updateFails Whether every update throws.
	 * @param bool $proofFails  Whether proof creation throws.
	 *
	 * @return TransferOutcomeRecorder
	 */
	private function recorder(bool $updateFails, bool $proofFails): TransferOutcomeRecorder {
		$mapper = $this->createMock(MagicMapperInterface::class);
		if ($updateFails === true) {
			$mapper->method('update')->willThrowException(new \RuntimeException('db down'));
		}

		$records = $this->createMock(TransferRecordService::class);
		if ($proofFails === true) {
			$records->method('createProof')->willThrowException(new \RuntimeException('store down'));
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = [$level, $message];
				}
			);
		}

		$auditLog = new TransferAuditLog(
			$this->createMock(AuditTrailMapperInterface::class),
			new TransferSettings($this->createMock(IAppConfig::class), new NullLogger()),
			$this->createMock(INotificationManager::class),
			new NullLogger()
		);

		return new TransferOutcomeRecorder($mapper, $records, $auditLog, $logger);
	}//end recorder()

	public function testAFailingWriteIsLoggedAndNeverThrown(): void {
		$object = new ObjectEntity();
		$object->setUuid('a');
		$object->setRetention(['transferErrors' => 'malformed']);

		$recorder = $this->recorder(updateFails: true, proofFails: true);
		$recorder->recordRefused(object: $object, error: 'no', timestamp: 'T');
		$recorder->recordAccepted(
			item: ['object' => $object],
			objResult: ['accepted' => true, 'reference' => 'r'],
			context: new TransferContext(transferUuid: 'l', packageFormat: 'zip', transportName: 'x', confirmedAt: 'T')
		);

		$this->assertSame(
			[
				['error', '[EdepotTransferService] Failed to update object transfer error'],
				['error', '[EdepotTransferService] Failed to update object status to overgebracht'],
				['warning', '[EdepotTransferService] proof-of-transfer creation failed for a: store down'],
			],
			$this->logged
		);
		$this->assertSame('transferred', $object->getRetention()['archiefstatus']);
		$this->assertSame([['error' => 'no', 'timestamp' => 'T']], $object->getRetention()['transferErrors']);
	}

	public function testAProofWithoutUuidIsNotLinked(): void {
		$object = new ObjectEntity();
		$object->setUuid('a');
		$object->setRetention([]);

		$this->recorder(updateFails: false, proofFails: false)->recordAccepted(
			item: ['object' => $object, 'files' => []],
			objResult: ['accepted' => true],
			context: new TransferContext(transferUuid: 'l', packageFormat: 'zip', transportName: 'x', confirmedAt: 'T')
		);

		$this->assertArrayNotHasKey('transferProof', $object->getRetention());
		$this->assertSame('', $object->getRetention()['eDepotReferentie']);
		$this->assertSame([], $this->logged);
	}
}//end class
