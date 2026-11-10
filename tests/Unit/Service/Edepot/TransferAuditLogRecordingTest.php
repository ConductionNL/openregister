<?php

/**
 * The e-Depot transfer audit log on its success path.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferAuditLog;
use OCA\OpenRegister\Service\Edepot\TransferFailure;
use OCA\OpenRegister\Service\Edepot\TransferSettings;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Each transfer step leaves the audit row, log line or notification it promises, and a
 * failing audit or notification never breaks the transfer.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\TransferAuditLog
 * @uses \OCA\OpenRegister\Service\Edepot\TransferSettings
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class TransferAuditLogRecordingTest extends TestCase {

	private AuditTrailMapperInterface&MockObject $audit;

	private INotificationManager&MockObject $notifications;

	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->audit = $this->createMock(AuditTrailMapperInterface::class);
		$this->notifications = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The log under test, with the target archive configured.
	 *
	 * @return TransferAuditLog The log.
	 */
	private function log(): TransferAuditLog {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'edepot_target_archive' ? 'nationaal-archief' : $default
		);

		return new TransferAuditLog(new \OCA\OpenRegister\Service\Edepot\AuditRecording($this->audit, $this->logger), new TransferSettings($config, new NullLogger()), $this->notifications);
	}//end log()

	/**
	 * Initiation logs the list, its size, transport and archive.
	 *
	 * @return void
	 */
	public function testInitiationLogsTheListSizeTransportAndArchive(): void {
		$this->logger->expects($this->once())->method('info')->with(
			'[EdepotTransferService] Audit: archival.transfer_initiated',
			[
				'action' => 'archival.transfer_initiated',
				'transferUuid' => 'list-1',
				'objectCount' => 3,
				'transport' => 'rest',
				'targetArchive' => 'nationaal-archief',
			]
		);

		$this->log()->initiated(transferList: ['uuid' => 'list-1', 'objectReferences' => ['a', 'b', 'c']], transport: 'rest');
	}//end testInitiationLogsTheListSizeTransportAndArchive()

	/**
	 * A transferred object gets an audit row with the transfer action and an info line.
	 *
	 * @return void
	 */
	public function testATransferredObjectIsAuditedAndLogged(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-7');
		$this->audit->expects($this->once())->method('createAuditTrail')->with($object, $object, 'archival.transferred');
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->once())->method('info')->with(
			'[EdepotTransferService] Audit: archival.transferred',
			[
				'action' => 'archival.transferred',
				'objectUuid' => 'obj-7',
				'transferUuid' => 'list-1',
				'eDepotReference' => 'NA-123',
			]
		);

		$this->log()->objectTransferred(object: $object, transferUuid: 'list-1', reference: 'NA-123');
	}//end testATransferredObjectIsAuditedAndLogged()

	/**
	 * A failing audit write is logged as a warning naming the object, and the info line still follows.
	 *
	 * @return void
	 */
	public function testAFailingAuditWriteIsWarnedAndTheTransferStillLogged(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-7');
		$this->audit->method('createAuditTrail')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[EdepotTransferService] Failed to create audit trail for transfer',
			['uuid' => 'obj-7', 'error' => 'db down']
		);
		$this->logger->expects($this->once())->method('info');

		$this->log()->objectTransferred(object: $object, transferUuid: 'list-1', reference: 'NA-123');
	}//end testAFailingAuditWriteIsWarnedAndTheTransferStillLogged()

	/**
	 * A failure logs an error with the failed count.
	 *
	 * @return void
	 */
	public function testAFailureLogsAnErrorWithTheFailedCount(): void {
		$this->logger->expects($this->once())->method('error')->with(
			'[EdepotTransferService] Audit: archival.transfer_failed',
			[
				'action' => 'archival.transfer_failed',
				'transferUuid' => 'list-1',
				'error' => 'refused',
				'transport' => 'sftp',
				'failedCount' => 2,
			]
		);

		$this->log()->failed(transferList: ['uuid' => 'list-1', 'objectReferences' => [1, 2]], failure: new TransferFailure(error: 'refused', transport: 'sftp'));
	}//end testAFailureLogsAnErrorWithTheFailedCount()

	/**
	 * Completion sends the admin a notification about the transfer result.
	 *
	 * @return void
	 */
	public function testCompletionNotifiesTheAdminOfTheResult(): void {
		$notification = $this->createMock(INotification::class);
		$notification->expects($this->once())->method('setApp')->with('openregister')->willReturnSelf();
		$notification->expects($this->once())->method('setUser')->with('admin')->willReturnSelf();
		$notification->expects($this->once())->method('setDateTime')->willReturnSelf();
		$notification->expects($this->once())->method('setObject')->with('transfer_result', 'list-1')->willReturnSelf();
		$notification->expects($this->once())->method('setSubject')
			->with('edepot_transfer_completed', ['uuid' => 'list-1', 'status' => 'completed'])->willReturnSelf();
		$this->notifications->method('createNotification')->willReturn($notification);
		$this->notifications->expects($this->once())->method('notify')->with($notification);
		$this->logger->expects($this->never())->method('warning');

		$this->log()->notifyCompletion(transferList: ['uuid' => 'list-1', 'status' => 'completed']);
	}//end testCompletionNotifiesTheAdminOfTheResult()

	/**
	 * A notification that cannot be sent is warned about, not thrown.
	 *
	 * @return void
	 */
	public function testAFailedNotificationIsWarnedNotThrown(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method($this->anything())->willReturnSelf();
		$this->notifications->method('createNotification')->willReturn($notification);
		$this->notifications->method('notify')->willThrowException(new RuntimeException('push down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[EdepotTransferService] Failed to send completion notification',
			['error' => 'push down']
		);

		$this->log()->notifyCompletion(transferList: ['uuid' => 'list-1', 'status' => 'failed']);
	}//end testAFailedNotificationIsWarnedNotThrown()
}//end class
