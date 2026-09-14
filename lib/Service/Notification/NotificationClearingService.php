<?php

/**
 * The bell empties because the work was done.
 *
 * A bell that only empties by being read is a bell people stop looking at: the
 * caseworker opens the case, does the thing the alert asked for, and the alert
 * is still sitting there afterwards waiting to be dismissed by hand. Nothing
 * else in the corpus clears an alert by the work being done; dimpact-zac does,
 * and this is that.
 *
 * ONE WRITER. Every path that marks a dispatched notice read comes through here:
 * opening an object, opening a sub-resource, marking a thread, and the archive
 * of a notice whose subject has been deleted. Keeping them in one class is what
 * stops "read" meaning one thing to the object page and another to the bell.
 *
 * ARCHIVING IS NOT READING, and the two are never collapsed. A notice whose
 * subject is gone is archived: nobody read it, and nobody can, because the thing
 * it points at no longer exists. Marking it read would be a claim about a person.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use DateTime;
use OCA\OpenRegister\Db\NotificationHistory;
use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Clears, snoozes and archives a user's own notices.
 */
class NotificationClearingService {

	/**
	 * Constructor.
	 *
	 * @param NotificationHistoryMapper $mapper The dispatched notices.
	 * @param IUserSession $userSession Resolves the calling user.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly NotificationHistoryMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Opening an object clears every notice this user holds about it.
	 *
	 * Best-effort by design: a failure here must never take out the object read
	 * that triggered it. The cost of the failure is a notice that stays in the
	 * bell, which the user can still clear by hand.
	 *
	 * @param string $objectUuid The object that was opened.
	 * @param string|null $userId The reader, or null for the caller.
	 *
	 * @return integer How many notices were cleared.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function clearForObject(string $objectUuid, ?string $userId = null): int {
		return $this->clear(objectUuid: $objectUuid, subjectId: null, userId: $userId);

	}//end clearForObject()

	/**
	 * Opening a sub-resource clears the notices about that sub-resource only.
	 *
	 * Narrower than `clearForObject()` on purpose: opening the documents tab
	 * says nothing about the messages nobody has read.
	 *
	 * @param string $objectUuid The object the sub-resource hangs off.
	 * @param string $subjectId The sub-resource's own id.
	 * @param string|null $userId The reader, or null for the caller.
	 *
	 * @return integer How many notices were cleared.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function clearForSubResource(string $objectUuid, string $subjectId, ?string $userId = null): int {
		if ($subjectId === '') {
			return 0;
		}

		return $this->clear(objectUuid: $objectUuid, subjectId: $subjectId, userId: $userId);

	}//end clearForSubResource()

	/**
	 * Mark a whole thread read: every notice this user holds about one subject.
	 *
	 * The same statement as opening the work, reached from the bell instead.
	 * They are deliberately the same write, so a thread marked read from the
	 * bell and a thread cleared by opening the case leave the same state.
	 *
	 * @param string $objectUuid The object the thread is about.
	 * @param string|null $subjectId The sub-resource, when the thread is narrower.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return integer How many notices were cleared.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function markThreadRead(string $objectUuid, ?string $subjectId = null): int {
		$uid = $this->requireCaller();

		return $this->mapper->markReadForSubject(
			recipient: $uid,
			objectUuid: $objectUuid,
			subjectId: $subjectId
		);

	}//end markThreadRead()

	/**
	 * Snooze one of the caller's own notices until a moment.
	 *
	 * @param int $id The notice's id.
	 * @param DateTime $until When it returns to the unread list.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous or the notice is not theirs.
	 *
	 * @return NotificationHistory The notice as it now stands.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function snooze(int $id, DateTime $until): NotificationHistory {
		$uid = $this->requireCaller();
		$this->requireOwn(id: $id, userId: $uid);

		$this->mapper->snooze(id: $id, recipient: $uid, until: $until);

		return $this->requireOwn(id: $id, userId: $uid);

	}//end snooze()

	/**
	 * Archive one of the caller's own notices, without reading it.
	 *
	 * @param int $id The notice's id.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous or the notice is not theirs.
	 *
	 * @return NotificationHistory The notice as it now stands, read state untouched.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function archive(int $id): NotificationHistory {
		$uid = $this->requireCaller();
		$this->requireOwn(id: $id, userId: $uid);

		$this->mapper->archive(id: $id, recipient: $uid);

		return $this->requireOwn(id: $id, userId: $uid);

	}//end archive()

	/**
	 * Archive every notice about an object that has been deleted.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many notices were archived.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function archiveOrphaned(string $objectUuid): int {
		if ($objectUuid === '') {
			return 0;
		}

		try {
			return $this->mapper->archiveByObject(objectUuid: $objectUuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationClearingService] orphan archive failed for %s: %s', $objectUuid, $e->getMessage())
			);
			return 0;
		}

	}//end archiveOrphaned()

	/**
	 * The shared body of the two clear paths.
	 *
	 * @param string $objectUuid The object that was opened.
	 * @param string|null $subjectId The sub-resource, when one was opened.
	 * @param string|null $userId The reader, or null for the caller.
	 *
	 * @return integer How many notices were cleared.
	 */
	private function clear(string $objectUuid, ?string $subjectId, ?string $userId): int {
		$uid = ($userId ?? $this->userSession->getUser()?->getUID());
		if ($objectUuid === '' || $uid === null || $uid === '') {
			return 0;
		}

		try {
			return $this->mapper->markReadForSubject(
				recipient: $uid,
				objectUuid: $objectUuid,
				subjectId: $subjectId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationClearingService] clearing failed for %s: %s', $objectUuid, $e->getMessage())
			);
			return 0;
		}

	}//end clear()

	/**
	 * One of the caller's own notices, or a refusal.
	 *
	 * The mapper scopes by recipient, so a notice addressed to somebody else
	 * simply does not come back and the caller learns nothing about it.
	 *
	 * @param int $id The notice's id.
	 * @param string $userId The caller's uid.
	 *
	 * @throws NotAuthorizedException When the notice is not the caller's.
	 *
	 * @return NotificationHistory The notice.
	 */
	private function requireOwn(int $id, string $userId): NotificationHistory {
		$row = $this->mapper->findOwn(id: $id, recipient: $userId);
		if ($row === null) {
			throw new NotAuthorizedException(message: 'That notification is not yours');
		}

		return $row;

	}//end requireOwn()

	/**
	 * The calling user's uid, or a refusal.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return string The uid.
	 */
	private function requireCaller(): string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			throw new NotAuthorizedException(message: 'Clearing a notification needs a signed-in user');
		}

		return $uid;

	}//end requireCaller()
}//end class
