<?php

/**
 * OpenRegister FrozenObjectDeleteGuardListener
 *
 * Refuses to delete an object that carries the `@self.frozen` marker.
 *
 * Freezing already refuses every data write (SaveObject); this is the delete
 * half. Every delete path dispatches the stoppable object deleting event, so
 * the single delete, the bulk delete and a cascade all meet this guard. A
 * cascade that reaches a frozen child is refused as a whole.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTime;
use Exception;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Listener\Support\ConflictVeto;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;

/**
 * Refuses the delete of a frozen object.
 *
 * @template-implements IEventListener<ObjectDeletingEvent>
 */
class FrozenObjectDeleteGuardListener implements IEventListener {
	use ConflictVeto;

	/**
	 * The error code a refused delete of a frozen object carries.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'object-frozen';

	/**
	 * Constructor.
	 *
	 * @param IUserManager $users Resolves the display name of whoever froze the object.
	 */
	public function __construct(
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * Refuse the delete when the object is frozen.
	 *
	 * Unlike the concept guard this one does not fail open: reading the marker
	 * needs nothing but the entity in hand, so there is no lookup that could
	 * fail and block deletes for an unrelated reason.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletingEvent && $event->getObject()->isFrozen() === true) {
			$this->refuseFrozen(event: $event);
		}
	}//end handle()

	/**
	 * Refuse the delete of a frozen object, naming who froze it and when.
	 *
	 * @param ObjectDeletingEvent $event The delete event of a frozen object.
	 *
	 * @return void
	 */
	private function refuseFrozen(ObjectDeletingEvent $event): void {
		$object = $event->getObject();
		$marker = ($object->getFrozen() ?? []);
		$userId = $this->text(value: ($marker['by'] ?? null));
		$frozenAt = $this->text(value: ($marker['at'] ?? null));

		$this->vetoAsConflict(
			event: $event,
			refusal: ['code' => self::ERROR_CODE, 'message' => $this->lockedMessage(userId: $userId, frozenAt: $frozenAt)],
			detail: ['frozenBy' => $userId, 'frozenAt' => $frozenAt, 'uuid' => $object->getUuid()]
		);
	}//end refuseFrozen()

	/**
	 * The refusal in words: who froze the record and since when.
	 *
	 * @param string|null $userId   The user id from the marker.
	 * @param string|null $frozenAt The ISO 8601 timestamp from the marker.
	 *
	 * @return string The message.
	 */
	private function lockedMessage(?string $userId, ?string $frozenAt): string {
		return sprintf(
			'This record is locked by %s since %s. Unlock it before deleting it.',
			$this->displayName(userId: $userId),
			$this->dateOf(timestamp: $frozenAt)
		);
	}//end lockedMessage()

	/**
	 * The display name of the user who froze the object.
	 *
	 * @param string|null $userId The user id from the marker.
	 *
	 * @return string The display name, the id when the account is gone, or a placeholder.
	 */
	private function displayName(?string $userId): string {
		if ($userId === null) {
			return 'an unknown user';
		}

		$user = $this->users->get($userId);
		if ($user === null) {
			return $userId;
		}

		return $user->getDisplayName();
	}//end displayName()

	/**
	 * The calendar date of the freeze.
	 *
	 * @param string|null $timestamp The ISO 8601 timestamp from the marker.
	 *
	 * @return string The date as Y-m-d, the raw value when unparseable, or a placeholder.
	 */
	private function dateOf(?string $timestamp): string {
		if ($timestamp === null) {
			return 'an unrecorded date';
		}

		try {
			return (new DateTime($timestamp))->format('Y-m-d');
		} catch (Exception) {
			return $timestamp;
		}
	}//end dateOf()

	/**
	 * A non-empty trimmed string, or null.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string|null The string, or null when it says nothing.
	 */
	private function text(mixed $value): ?string {
		$text = null;
		if (is_string($value) === true && trim($value) !== '') {
			$text = trim($value);
		}

		return $text;
	}//end text()
}//end class
