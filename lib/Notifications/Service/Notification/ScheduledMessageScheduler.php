<?php

/**
 * Schedule a composed message, list an object's messages and cancel one.
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

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The scheduling side of the send leaf: a send with `sendAt` is stored as
 * pending for the sweep, bound to an object when one is given, and every
 * scheduling and cancellation is written on that object's audit trail.
 *
 * Only the author, or a user with `update` on the bound object, cancels; a
 * message that already left, was parked or was cancelled cannot be cancelled.
 * The cancel is a compare-and-set on state and attempts, so it cannot cross a
 * sweep that claims the same row.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */
class ScheduledMessageScheduler {

	/**
	 * The audit action of a scheduling.
	 */
	public const AUDIT_SCHEDULED = 'message.scheduled';

	/**
	 * The audit action of a cancellation.
	 */
	public const AUDIT_CANCELLED = 'message.cancelled';

	/**
	 * Constructor.
	 *
	 * @param ScheduledMessageStoreInterface $store      The scheduled messages.
	 * @param TimelineVisibilityService      $visibility Answers whether the caller holds `update` on an object.
	 * @param AuditTrailMapperInterface      $auditTrail Writes on the bound object's audit trail.
	 * @param IUserSession                   $session    The caller.
	 * @param LoggerInterface                $logger     Logs an audit failure, which never fails the call.
	 */
	public function __construct(
		private readonly ScheduledMessageStoreInterface $store,
		private readonly TimelineVisibilityService $visibility,
		private readonly AuditTrailMapperInterface $auditTrail,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Store a send for later and answer the stored message.
	 *
	 * @param array<string, mixed> $message channel, source, path, body, headers, sendAt (ISO 8601).
	 * @param ObjectEntity|null    $object  The object the message is bound to, if any.
	 * @param DateTimeImmutable    $now     The moment of the request.
	 *
	 * @return array<string, mixed> The stored message.
	 *
	 * @throws InvalidArgumentException When sendAt is not a date or not in the future.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	public function schedule(array $message, ?ObjectEntity $object, DateTimeImmutable $now): array {
		$sendAt = self::futureMoment(value: (string)($message['sendAt'] ?? ''), now: $now);
		$row = [
			'channel' => (string)($message['channel'] ?? ''),
			'source' => (string)($message['source'] ?? ''),
			'path' => (string)($message['path'] ?? ''),
			'body' => ($message['body'] ?? []),
			'headers' => ($message['headers'] ?? []),
			'objectUuid' => $object?->getUuid(),
			'author' => $this->callerUid(),
			'sendAt' => $sendAt->format(DateTimeInterface::ATOM),
		];
		$id = $this->store->insert(message: $row);
		$this->audit(object: $object, action: self::AUDIT_SCHEDULED, context: ['message' => $id, 'channel' => $row['channel'], 'sendAt' => $row['sendAt']]);

		return ['id' => $id, 'state' => ScheduledMessagePolicy::PENDING, 'sendAt' => $row['sendAt'], 'channel' => $row['channel']];
	}//end schedule()

	/**
	 * The messages bound to an object the caller may read.
	 *
	 * @param ObjectEntity $object The object, already resolved under the caller's read access.
	 * @param int          $limit  The most messages to return.
	 *
	 * @return array<int, array<string, mixed>> The messages, newest first, without their bodies.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	public function forObject(ObjectEntity $object, int $limit = 50): array {
		$listed = [];
		foreach ($this->store->forObject(objectUuid: (string)$object->getUuid(), limit: $limit) as $row) {
			$listed[] = [
				'id' => $row['id'],
				'channel' => $row['channel'],
				'state' => $row['state'],
				'sendAt' => $row['sendAt'],
				'author' => $row['author'],
				'attempts' => $row['attempts'],
				'messageId' => $row['messageId'],
				'lastError' => $row['lastError'],
			];
		}

		return $listed;
	}//end forObject()

	/**
	 * Cancel a pending or claimed message bound to the object.
	 *
	 * @param ObjectEntity $object The object, already resolved under the caller's read access.
	 * @param string       $id     The message.
	 *
	 * @return array<string, mixed> The message as it now reads.
	 *
	 * @throws ScheduledMessageRefusal When it is not on this object, the caller may not cancel it, or it can no longer be cancelled.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	public function cancel(ObjectEntity $object, string $id): array {
		$row = $this->store->find(id: $id);
		if ($row === null || ($row['objectUuid'] ?? '') !== (string)$object->getUuid()) {
			throw new ScheduledMessageRefusal(message: 'No such scheduled message on this object', status: 404);
		}

		$caller = $this->callerUid();
		$isAuthor = ($caller !== null && $caller === ($row['author'] ?? null));
		if ($isAuthor === false && $this->visibility->mayManage(object: $object) === false) {
			throw new ScheduledMessageRefusal(message: 'Only the author or a user who may update the object can cancel this message', status: 403);
		}

		$open = [ScheduledMessagePolicy::PENDING, ScheduledMessagePolicy::CLAIMED];
		$cancelled = in_array($row['state'], $open, true) === true
			&& $this->store->compareAndSet(
				expect: ['id' => (string)$row['id'], 'state' => (string)$row['state'], 'attempts' => (int)$row['attempts']],
				set: ['state' => ScheduledMessagePolicy::CANCELLED]
			);
		if ($cancelled === false) {
			$state = $this->currentState(id: $id, fallback: (string)$row['state']);
			throw new ScheduledMessageRefusal(message: 'This message can no longer be cancelled: it reads '.$state, status: 409);
		}

		$this->audit(object: $object, action: self::AUDIT_CANCELLED, context: ['message' => (string)$row['id'], 'channel' => ($row['channel'] ?? '')]);

		return ['id' => (string)$row['id'], 'state' => ScheduledMessagePolicy::CANCELLED];
	}//end cancel()

	/**
	 * The stored state of a message, re-read after a lost compare-and-set.
	 *
	 * @param string $id       The message.
	 * @param string $fallback The state read before.
	 *
	 * @return string The state.
	 */
	private function currentState(string $id, string $fallback): string {
		return (string)($this->store->find(id: $id)['state'] ?? $fallback);
	}//end currentState()

	/**
	 * A sendAt that parses and lies after now.
	 *
	 * @param string            $value The requested moment.
	 * @param DateTimeImmutable $now   Now.
	 *
	 * @return DateTimeImmutable The moment, in UTC.
	 *
	 * @throws InvalidArgumentException When it does not parse or is not in the future.
	 */
	private static function futureMoment(string $value, DateTimeImmutable $now): DateTimeImmutable {
		if (trim($value) === '') {
			throw new InvalidArgumentException('sendAt must be a date and time, such as 2026-11-16T08:00:00+01:00');
		}

		try {
			$moment = new DateTimeImmutable($value);
		} catch (Throwable) {
			throw new InvalidArgumentException('sendAt must be a date and time, such as 2026-11-16T08:00:00+01:00');
		}

		if ($moment <= $now) {
			throw new InvalidArgumentException('sendAt must be in the future');
		}

		return $moment->setTimezone(new DateTimeZone('UTC'));
	}//end futureMoment()

	/**
	 * Write an entry on the bound object's audit trail; nothing for an unbound message.
	 *
	 * @param ObjectEntity|null    $object  The object.
	 * @param string               $action  The audit action.
	 * @param array<string, mixed> $context What the entry says.
	 *
	 * @return void
	 */
	private function audit(?ObjectEntity $object, string $action, array $context): void {
		if ($object === null) {
			return;
		}

		try {
			$this->auditTrail->recordObjectEvent(event: new ObjectAuditEvent(object: $object, action: $action, context: $context));
		} catch (Throwable $e) {
			$this->logger->warning('[ScheduledMessageScheduler] audit entry failed', ['action' => $action, 'error' => $e->getMessage()]);
		}
	}//end audit()

	/**
	 * The caller's user id, or null.
	 *
	 * @return string|null
	 */
	private function callerUid(): ?string {
		return $this->session->getUser()?->getUID();
	}//end callerUid()
}//end class
