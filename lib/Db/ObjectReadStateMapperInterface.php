<?php

/**
 * ObjectReadStateMapperInterface: what callers outside the Db component use of ObjectReadStateMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;


/**
 * The methods of ObjectReadStateMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ObjectReadStateMapper. Guarded by C04InterfaceBindingsTest.
 */
interface ObjectReadStateMapperInterface {

	/**
	 * How many seen uuids the per-request memo will load at most.
	 *
	 * `@self.unread` is rendered on every row of every list, so the seen set is
	 * loaded once and answered from memory. The cap keeps that one query
	 * bounded; past it the caller falls back to asking per object, which is
	 * slower but never unbounded in memory. ObjectReadStateMapper inherits it.
	 *
	 * @var integer
	 */
	public const SEEN_SET_LIMIT = 20000;

	/**
	 * One user's read state for one object, when it exists.
	 *
	 * @param string $userId The reading user's uid.
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return ObjectReadState|null The row, or null when the user has not seen the object.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function findOne(string $userId, string $objectUuid): ?ObjectReadState;

	/**
	 * The uuids one user has already seen, optionally narrowed to a scope.
	 *
	 * @param string         $userId The reading user's uid.
	 * @param ListQuery|null $query  How many uuids to load at most (the limit) and the `register` and `schema`
	 *                               filters to narrow to, as stored on the row; null for SEEN_SET_LIMIT unnarrowed.
	 *
	 * @return array<int, string> The seen object uuids.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function uuidsForUser(string $userId, ?ListQuery $query = null): array;

	/**
	 * Record that a user has now seen an object, idempotently.
	 *
	 * The unique index on (user_id, object_uuid) is the authority: opening an
	 * object twice refreshes one row rather than writing a second.
	 *
	 * @param ObjectReadState $sighting The row to insert when the user has none:
	 *        user, object, register and schema as the caller addressed them,
	 *        sub-resource seen moments to merge in (null for none) and the
	 *        moment seen (null for now).
	 *
	 * @return ObjectReadState The stored row.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markSeen(ObjectReadState $sighting): ObjectReadState;

	/**
	 * Put one object back to unread for one user.
	 *
	 * @param string $userId The reading user's uid.
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return boolean True when a row was removed, false when there was none.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markUnread(string $userId, string $objectUuid): bool;

	/**
	 * A substantive change: everyone but the actor has something new to see.
	 *
	 * The actor is excluded rather than deleted and re-inserted, so the write
	 * is one statement and the person who made the change never sees their own
	 * edit as unread.
	 *
	 * @param string $objectUuid The changed object's uuid.
	 * @param string|null $exceptUserId The actor, whose row survives. Null for a
	 *                                  system write, where every row goes.
	 *
	 * @return integer How many rows were removed.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function invalidate(string $objectUuid, ?string $exceptUserId = null): int;

	/**
	 * Remove every row of one object.
	 *
	 * Called when the object is deleted, so a re-created uuid never inherits
	 * the rows of the object it replaces.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many rows were removed.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function deleteByObject(string $objectUuid): int;
}//end interface
