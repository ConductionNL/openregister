<?php

/**
 * WatcherServiceInterface: what callers outside the component use of WatcherService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Notifications\Db\Watcher;

/**
 * Who watches an object, and the ways to follow or leave it.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface WatcherServiceInterface {

	/**
	 * Subscribe the calling user to an object they may read.
	 *
	 * Idempotent: subscribing twice leaves one row, and writes nothing on the
	 * object itself — no audit entry, no version.
	 *
	 * `$notify` is the follow's notification switch: a bool sets it, null
	 * keeps what is there (and a new follow notifies).
	 *
	 * @param ObjectEntity $object The object to follow, already resolved through RBAC.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 * @param bool|null $notify The notification switch, or null to keep it.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return Watcher The subscription.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function watch(
		ObjectEntity $object,
		?string $register = null,
		?string $schema = null,
		?bool $notify = null,
	): Watcher;

	/**
	 * Remove the calling user's own subscription.
	 *
	 * @param ObjectEntity $object The object to stop following.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return boolean True when a subscription was removed.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function unwatch(ObjectEntity $object): bool;

	/**
	 * The watchers of one object, for a caller with `update`.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @throws NotAuthorizedException When the caller may not update the object.
	 *
	 * @return array<int, Watcher> The subscriptions, oldest first.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function listWatchers(ObjectEntity $object): array;

	/**
	 * Subscribe another user, for a caller with `manage`.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $userId The user to subscribe.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 *
	 * @throws NotAuthorizedException When the caller may not manage the object.
	 *
	 * @return Watcher The subscription.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function addWatcher(ObjectEntity $object, string $userId, ?string $register = null, ?string $schema = null): Watcher;

	/**
	 * Subscribe a principal who was NAMED in a timeline entry.
	 *
	 * WHY THIS IS NOT `addWatcher()`. That method is an administrative act:
	 * one person deciding that another will follow an object, which is why it
	 * asks for `manage`. A mention is not that. The person doing it is a
	 * handler who just wrote an entry on this object, and the authorisation
	 * that matters is the MENTIONED principal's own: they are subscribed only
	 * when they may already read the object, and that is decided by the caller
	 * before this method runs (see EntryMentionService). Requiring `manage`
	 * here would mean only an owner could ever name a colleague in a note,
	 * which is the opposite of what a mention is for.
	 *
	 * Idempotent, like every other subscribe: naming somebody twice leaves one
	 * row and writes nothing on the object.
	 *
	 * @param ObjectEntity $object The object the entry hangs on.
	 * @param string $userId The principal who was named, already checked for read access.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 *
	 * @return Watcher The subscription.
	 *
	 * @throws NotAuthorizedException When the object carries no uuid to hang the row on.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function subscribeMentioned(
		ObjectEntity $object,
		string $userId,
		?string $register = null,
		?string $schema = null,
	): Watcher;

	/**
	 * Make an assignee follow the object, with notifications on.
	 *
	 * Being assigned something means hearing about it, so an existing quiet
	 * follow is switched on. Like `subscribeMentioned()` this is not
	 * `addWatcher()`: the act is the assignment, not one person deciding for
	 * another, and the caller has checked that the assignee may read the
	 * object (AssigneeFollowListener does, through the same read check a
	 * mention uses). Public, so an app whose assignment is not an object
	 * property (a task, a workflow step) can call it.
	 *
	 * @param ObjectEntity $object The assigned object.
	 * @param string $userId The assignee, already checked for read access.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 *
	 * @return Watcher The follow.
	 *
	 * @throws NotAuthorizedException When the object carries no uuid to hang the row on.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
	 */
	public function followAssigned(
		ObjectEntity $object,
		string $userId,
		?string $register = null,
		?string $schema = null,
	): Watcher;

	/**
	 * Remove another user's subscription.
	 *
	 * A watcher removing THEMSELVES needs nothing beyond being that watcher;
	 * removing anybody else needs `manage`.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $userId The user whose subscription is removed.
	 *
	 * @throws NotAuthorizedException When the caller is neither that user nor a manager.
	 *
	 * @return boolean True when a subscription was removed.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	public function removeWatcher(ObjectEntity $object, string $userId): bool;

	/**
	 * The uids following one object with notifications on, for the dispatcher.
	 *
	 * A quiet follow (a former favourite, or a follower who switched
	 * notifications off) is left out here and nowhere else.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return array<int, string> The uids to notify.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
	 */
	public function watcherUids(string $objectUuid): array;

	/**
	 * Remove every subscription on a deleted object.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many subscriptions were removed.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-deleting-an-object-removes-its-watchers
	 */
	public function cleanupForObject(string $objectUuid): int;
}//end interface
