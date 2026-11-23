<?php
/**
 * ObjectWatchersInterface: who follows an object, for the notification dispatcher.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

/**
 * The followers of one object that a notification rule may address.
 *
 * The recipient resolver (Notifications) asks; the watcher service (Processes)
 * answers and is bound to this contract in its own registrar.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
 */
interface ObjectWatchersInterface {

	/**
	 * The uids following one object with notifications on, for the dispatcher.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return array<int, string> The uids to notify.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
	 */
	public function watcherUids(string $objectUuid): array;

	/**
	 * Drop the watchers who may no longer read the object. Best-effort: a failure is logged, never thrown.
	 *
	 * @param string             $objectUuid The object's uuid.
	 * @param array<int, string> $userIds    The users to unsubscribe.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
	 */
	public function dropWatchers(string $objectUuid, array $userIds): void;
}//end interface
