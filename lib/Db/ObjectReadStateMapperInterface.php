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

use DateTime;

/**
 * The methods of ObjectReadStateMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ObjectReadStateMapper. Guarded by C04InterfaceBindingsTest.
 */
interface ObjectReadStateMapperInterface {

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
	 * @param string $userId The reading user's uid.
	 * @param string|null $register Narrow to this register, as stored on the row.
	 * @param string|null $schema Narrow to this schema, as stored on the row.
	 * @param int $limit How many uuids to load at most.
	 *
	 * @return array<int, string> The seen object uuids.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function uuidsForUser(
		string $userId,
		?string $register = null,
		?string $schema = null,
		int $limit = self::SEEN_SET_LIMIT
	): array;

	/**
	 * Record that a user has now seen an object, idempotently.
	 *
	 * The unique index on (user_id, object_uuid) is the authority: opening an
	 * object twice refreshes one row rather than writing a second.
	 *
	 * @param string $userId The reading user's uid.
	 * @param string $objectUuid The object's uuid.
	 * @param string|null $register The object's register, as the caller addressed it.
	 * @param string|null $schema The object's schema, as the caller addressed it.
	 * @param array<string, string>|null $subSeen Sub-resource seen moments to merge in.
	 * @param DateTime|null $seenAt The moment to record, defaulting to now.
	 *
	 * @return ObjectReadState The stored row.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markSeen(
		string $userId,
		string $objectUuid,
		?string $register = null,
		?string $schema = null,
		?array $subSeen = null,
		?DateTime $seenAt = null
	): ObjectReadState;

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
