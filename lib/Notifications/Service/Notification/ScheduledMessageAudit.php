<?php

/**
 * Write what the sweep did with a scheduled message on its bound object's audit trail.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The sending and failing entries of a scheduled message, on the object it is
 * bound to. Scheduling and cancelling are written by ScheduledMessageScheduler,
 * which has the object in hand; the sweep runs without a user and knows only
 * the object's uuid, so the object is read here in the system context.
 *
 * An entry is a record of what happened, never a condition for it: a message
 * that was sent stays sent when its entry cannot be written, and the failure
 * is logged instead.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */
class ScheduledMessageAudit {

	/**
	 * The audit action of a scheduled message that was sent.
	 */
	public const AUDIT_SENT = 'message.sent';

	/**
	 * The audit action of a scheduled message whose send failed.
	 */
	public const AUDIT_FAILED = 'message.failed';

	/**
	 * Constructor.
	 *
	 * @param MagicObjectFinderInterface $objects    Reads the bound object, without a user.
	 * @param AuditTrailMapperInterface  $auditTrail Writes on the bound object's audit trail.
	 * @param LoggerInterface            $logger     Logs an entry that could not be written.
	 */
	public function __construct(
		private readonly MagicObjectFinderInterface $objects,
		private readonly AuditTrailMapperInterface $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record that a scheduled message was sent.
	 *
	 * @param array<string,mixed> $message The stored message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	public function sent(array $message): void {
		$this->record(
			message: $message,
			action: self::AUDIT_SENT,
			context: ['messageId' => (string)($message['messageId'] ?? '')]
		);
	}//end sent()

	/**
	 * Record that a scheduled message's send failed: with the cause, the
	 * attempt it was, and whether it is parked (no further attempt) or will be
	 * tried again.
	 *
	 * @param array<string,mixed> $message The stored message, as claimed.
	 * @param array<string,mixed> $outcome What was written after the failure: its state and last error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	public function failed(array $message, array $outcome): void {
		$this->record(
			message: $message,
			action: self::AUDIT_FAILED,
			context: [
				'error' => (string)($outcome['lastError'] ?? ''),
				'attempt' => (int)($message['attempts'] ?? 0),
				'parked' => (($outcome['state'] ?? null) === ScheduledMessagePolicy::PARKED),
			]
		);
	}//end failed()

	/**
	 * Write one entry on the bound object; nothing for an unbound message.
	 *
	 * @param array<string,mixed> $message The stored message.
	 * @param string              $action  The audit action.
	 * @param array<string,mixed> $context What the entry says beside the message.
	 *
	 * @return void
	 */
	private function record(array $message, string $action, array $context): void {
		$objectUuid = (string)($message['objectUuid'] ?? '');
		if ($objectUuid === '') {
			return;
		}

		try {
			$object = $this->objects->find(identifier: $objectUuid, _rbac: false, _multitenancy: false);
			$this->auditTrail->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $object,
					action: $action,
					context: ['message' => (string)($message['id'] ?? ''), 'channel' => (string)($message['channel'] ?? '')] + $context
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ScheduledMessageAudit] audit entry failed',
				['action' => $action, 'message' => (string)($message['id'] ?? ''), 'error' => $e->getMessage()]
			);
		}
	}//end record()
}//end class
