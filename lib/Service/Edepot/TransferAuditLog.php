<?php

/**
 * OpenRegister e-Depot Transfer Audit Log
 *
 * The audit trail entries, audit log lines and completion notification of an
 * e-Depot transfer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Records what a transfer did: initiated, transferred per object, failed, completed.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
 */
final class TransferAuditLog {

	/**
	 * The audit trail mapper.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param AuditRecording       $audit               The audit trail mapper and the logger.
	 * @param TransferSettings     $settings            The transport settings, for the target archive.
	 * @param INotificationManager $notificationManager The notification manager.
	 */
	public function __construct(
		AuditRecording $audit,
		private readonly TransferSettings $settings,
		private readonly INotificationManager $notificationManager,
	) {
		$this->auditTrailMapper = $audit->auditTrailMapper;
		$this->logger           = $audit->logger;
	}//end __construct()

	/**
	 * Log audit trail: transfer initiated.
	 *
	 * @param array<string,mixed> $transferList The transfer list.
	 * @param string              $transport    The transport protocol name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
	 */
	public function initiated(array $transferList, string $transport): void {
		$this->logger->info(
			message: '[EdepotTransferService] Audit: archival.transfer_initiated',
			context: [
				'action' => 'archival.transfer_initiated',
				'transferUuid' => $transferList['uuid'],
				'objectCount' => count($transferList['objectReferences']),
				'transport' => $transport,
				'targetArchive' => $this->settings->targetArchive(),
			]
		);
	}//end initiated()

	/**
	 * Audit trail entry and log line: object transferred.
	 *
	 * @param ObjectEntity $object       The transferred object.
	 * @param string       $transferUuid The transfer list UUID.
	 * @param string       $reference    The e-Depot reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
	 */
	public function objectTransferred(ObjectEntity $object, string $transferUuid, string $reference): void {
		$this->auditTransferred(object: $object);

		$this->logger->info(
			message: '[EdepotTransferService] Audit: archival.transferred',
			context: [
				'action' => 'archival.transferred',
				'objectUuid' => $object->getUuid(),
				'transferUuid' => $transferUuid,
				'eDepotReference' => $reference,
			]
		);
	}//end objectTransferred()

	/**
	 * Log audit trail: transfer failed.
	 *
	 * @param array<string,mixed> $transferList The transfer list.
	 * @param TransferFailure     $failure      Why it failed, and over which transport.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
	 */
	public function failed(array $transferList, TransferFailure $failure): void {
		$this->logger->error(
			message: '[EdepotTransferService] Audit: archival.transfer_failed',
			context: [
				'action' => 'archival.transfer_failed',
				'transferUuid' => $transferList['uuid'],
				'error' => $failure->error,
				'transport' => $failure->transport,
				'failedCount' => count($transferList['objectReferences']),
			]
		);
	}//end failed()

	/**
	 * Send notification on transfer completion; a failure to notify is logged, never thrown.
	 *
	 * @param array<string,mixed> $transferList The completed transfer list.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-multiple-transport-protocols-for-sip-delivery
	 */
	public function notifyCompletion(array $transferList): void {
		try {
			$this->notificationManager->notify($this->completionNotification(transferList: $transferList));
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[EdepotTransferService] Failed to send completion notification',
				context: ['error' => $e->getMessage()]
			);
		}
	}//end notifyCompletion()

	/**
	 * The audit trail entry for a transferred object; a failure is logged, never thrown.
	 *
	 * @param ObjectEntity $object The transferred object.
	 *
	 * @return void
	 */
	private function auditTransferred(ObjectEntity $object): void {
		try {
			$this->auditTrailMapper->recordChange(change: new AuditedChange(old: $object, new: $object, action: 'archival.transferred'));
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[EdepotTransferService] Failed to create audit trail for transfer',
				context: ['uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);
		}
	}//end auditTransferred()

	/**
	 * The completion notification for a transfer list.
	 *
	 * @param array<string,mixed> $transferList The completed transfer list.
	 *
	 * @return INotification The notification.
	 */
	private function completionNotification(array $transferList): INotification {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('openregister');
		$notification->setUser('admin');
		$notification->setDateTime(new DateTime());
		$notification->setObject('transfer_result', $transferList['uuid']);
		$notification->setSubject(
			'edepot_transfer_completed',
			['uuid' => $transferList['uuid'], 'status' => $transferList['status']]
		);

		return $notification;
	}//end completionNotification()
}//end class
