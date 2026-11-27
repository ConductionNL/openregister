<?php

/**
 * OpenRegister EntryClosureService
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\EntriesClosedException;
use OCA\OpenRegister\Kernel\Contracts\EntryClosureCheckInterface;
use OCA\OpenRegister\Notifications\Db\EntryClosure;
use OCA\OpenRegister\Notifications\Db\EntryClosureMapper;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\IUserSession;

/**
 * Closes an object to new timeline entries and notes, and reopens it (REQ-OAS-006, C29.4).
 *
 * A closed object stays readable and its existing entries stay as they are; only a new
 * entry or note is refused, naming who closed it, when and why. Closing and reopening
 * need the right to manage the object's timeline and are written on its audit trail.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
class EntryClosureService implements EntryClosureCheckInterface {

	/**
	 * The audit action of a closure.
	 */
	public const CLOSE_AUDIT_ACTION = 'timeline.entries.closed';

	/**
	 * The audit action of a reopening.
	 */
	public const REOPEN_AUDIT_ACTION = 'timeline.entries.reopened';

	/**
	 * Constructor.
	 *
	 * @param EntryClosureMapper        $closures    The closures.
	 * @param TimelineVisibilityService $visibility  Who may manage the timeline, and its audit facts.
	 * @param IUserSession              $userSession The caller.
	 */
	public function __construct(
		private readonly EntryClosureMapper $closures,
		private readonly TimelineVisibilityService $visibility,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The closure of an object, or null when it accepts new entries.
	 *
	 * @param string $objectUuid The object uuid.
	 *
	 * @return array{by: string, at: string|null, reason: string}|null The closure.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function closureOf(string $objectUuid): ?array {
		return $this->closures->findForObject(objectUuid: $objectUuid)?->closure();
	}//end closureOf()

	/**
	 * Refuse a new entry on a closed object.
	 *
	 * @param string $objectUuid The object the entry would hang on.
	 *
	 * @return void
	 *
	 * @throws EntriesClosedException When the object is closed.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function assertOpen(string $objectUuid): void {
		$closure = $this->closureOf(objectUuid: $objectUuid);
		if ($closure !== null) {
			throw new EntriesClosedException(closure: $closure);
		}
	}//end assertOpen()

	/**
	 * Close an object to new entries; closing a closed object answers its closure unchanged.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $reason Why it is closed.
	 *
	 * @return array{by: string, at: string|null, reason: string} The closure.
	 *
	 * @throws TimelinePermissionException When the caller may not manage the object's timeline.
	 * @throws TimelineValidationException When no reason is given.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function close(ObjectEntity $object, string $reason): array {
		$this->assertMayManage(object: $object);
		$reason = trim($reason);
		if ($reason === '') {
			throw new TimelineValidationException(['reason' => 'Closing a record to new entries needs a reason']);
		}

		$uuid = (string)$object->getUuid();
		$existing = $this->closures->findForObject(objectUuid: $uuid);
		if ($existing !== null) {
			return $existing->closure();
		}

		$closure = new EntryClosure();
		$closure->setObjectUuid($uuid);
		$closure->setClosedBy($this->callerUid());
		$closure->setClosedAt(new DateTime());
		$closure->setReason(mb_substr($reason, 0, 1024));
		$written = $this->closures->insert(entity: $closure);

		$this->visibility->auditTimelineEvent(object: $object, action: self::CLOSE_AUDIT_ACTION, context: $written->closure());

		return $written->closure();
	}//end close()

	/**
	 * Reopen an object to new entries; reopening an open object changes nothing and writes no audit entry.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when a closure was removed.
	 *
	 * @throws TimelinePermissionException When the caller may not manage the object's timeline.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function reopen(ObjectEntity $object): bool {
		$this->assertMayManage(object: $object);

		$uuid = (string)$object->getUuid();
		$existing = $this->closures->findForObject(objectUuid: $uuid);
		if ($existing === null) {
			return false;
		}

		$this->closures->deleteForObject(objectUuid: $uuid);
		$this->visibility->auditTimelineEvent(
			object: $object,
			action: self::REOPEN_AUDIT_ACTION,
			context: ['closed' => $existing->closure(), 'by' => $this->callerUid()]
		);

		return true;
	}//end reopen()

	/**
	 * Refuse a caller who may not manage the object's timeline.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return void
	 *
	 * @throws TimelinePermissionException When the caller may not.
	 */
	private function assertMayManage(ObjectEntity $object): void {
		if ($this->visibility->mayManage(object: $object) === false) {
			throw new TimelinePermissionException('You do not have permission to close or reopen this record to new entries');
		}
	}//end assertMayManage()

	/**
	 * The caller's uid, '' without one.
	 *
	 * @return string The uid.
	 */
	private function callerUid(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end callerUid()
}//end class
