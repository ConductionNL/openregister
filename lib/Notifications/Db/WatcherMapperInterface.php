<?php

/**
 * WatcherMapperInterface: the public surface of WatcherMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Db
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

namespace OCA\OpenRegister\Notifications\Db;

/**
 * Every public method of WatcherMapper. Callers depend on this interface, not on the class.
 */
interface WatcherMapperInterface {

	/**
	 * How many rows the grouped watcher-count query will load in one pass.
	 *
	 * `@self.watcherCount` is rendered per row, so a count query per rendered
	 * object would be an N+1. Instead the counts are loaded once per request,
	 * grouped. The cap keeps that one query bounded: past it the caller falls
	 * back to counting a single object at a time, which is slower but never
	 * unbounded in memory.
	 *
	 * @var integer
	 */
	public const COUNT_MAP_LIMIT = 5000;

	/**
	 * The follow table, named once for the query lenses that join on it.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_watchers';

	/**
	 * The subscription of one user to one object, when it exists.
	 *
	 * @param string $userId The subscribing user's uid.
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return Watcher|null The row, or null when the user does not watch the object.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function findOne(string $userId, string $objectUuid): ?Watcher;

	/**
	 * Every subscription on one object, oldest first.
	 *
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return array<int, Watcher> The rows.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function findByObject(string $objectUuid): array;

	/**
	 * The subscriptions on one object that notify, oldest first.
	 *
	 * The notification dispatcher's question: who hears about a change. A
	 * quiet follow (`notify` false) still follows and is still listed to
	 * editors, but is not told. A null `notify`, a row from before the column
	 * existed, counts as on.
	 *
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return array<int, Watcher> The rows.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
	 */
	public function findNotifyingByObject(string $objectUuid): array;

	/**
	 * What one user follows, with each follow's notification setting.
	 *
	 * @param string $userId The following user's uid.
	 *
	 * @return array<string, bool> Object uuid to whether that follow notifies.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function notifyMapForUser(string $userId): array;

	/**
	 * Watcher counts per object, in one grouped pass.
	 *
	 * @param int $limit How many object rows to load at most.
	 *
	 * @return array<string, int> Object uuid to watcher count.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function countsByObject(int $limit = WatcherMapper::COUNT_MAP_LIMIT): array;

	/**
	 * How many users watch one object.
	 *
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return integer The number of watchers.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function countForObject(string $objectUuid): int;

	/**
	 * Subscribe a user to an object, idempotently, and set its notify switch.
	 *
	 * The unique index on (user_id, object_uuid) is the authority: a second
	 * subscribe returns the row that is already there rather than writing a
	 * duplicate. `$notify` null means "leave it": a new row notifies, an
	 * existing row keeps its setting. A bool sets it either way.
	 *
	 * @param Watcher $watcher The unsaved follow: the subscribing user's uid, the watched
	 *                         object's uuid, and its register and schema as the caller
	 *                         addressed it. It is inserted only when no row exists yet.
	 * @param bool|null $notify The notification switch, or null to keep it.
	 *
	 * @return Watcher The stored row.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function subscribe(Watcher $watcher, ?bool $notify = null): Watcher;

	/**
	 * Remove one user's subscription to one object.
	 *
	 * @param string $userId The subscribing user's uid.
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return boolean True when a row was removed, false when there was none.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function unsubscribe(string $userId, string $objectUuid): bool;

	/**
	 * Remove every subscription on one object.
	 *
	 * Called when the object is deleted, so a re-created uuid never inherits
	 * somebody else's audience.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many rows were removed.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-deleting-an-object-removes-its-watchers
	 */
	public function deleteByObject(string $objectUuid): int;
}//end interface
