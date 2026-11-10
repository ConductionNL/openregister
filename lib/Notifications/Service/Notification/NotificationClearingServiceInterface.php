<?php

/**
 * NotificationClearingServiceInterface: the public surface of NotificationClearingService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Every public method of NotificationClearingService. Callers depend on this interface, not on the class.
 */
interface NotificationClearingServiceInterface {

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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function clearForObject(string $objectUuid, ?string $userId = null): int;

	/**
	 * Opening a sub-resource clears the notices about that sub-resource only.
	 *
	 * Narrower than `clearForObject()` on purpose: opening the documents tab
	 * says nothing about the messages nobody has read.
	 *
	 * The notices cleared are the caller's own.
	 *
	 * @param string $objectUuid The object the sub-resource hangs off.
	 * @param string $subjectId  The sub-resource's own id.
	 *
	 * @return integer How many notices were cleared.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function clearForSubResource(string $objectUuid, string $subjectId): int;

	/**
	 * Archive every notice about an object that has been deleted.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many notices were archived.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function archiveOrphaned(string $objectUuid): int;
}//end interface
