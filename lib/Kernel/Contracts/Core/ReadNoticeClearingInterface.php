<?php
/**
 * ReadNoticeClearingInterface: the notices a read clears, as the object read-state API asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Clears the notices that opening an object, or one of its sub-resources, covers.
 *
 * The object read-state API (Core) asks after it has stored the read; Notifications
 * keeps the notices and binds its clearing service to this contract in its own wiring.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
 */
interface ReadNoticeClearingInterface {

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
}//end interface
