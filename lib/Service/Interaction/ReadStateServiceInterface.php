<?php

/**
 * ReadStateServiceInterface: what callers outside the component use of ReadStateService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * The per-user read state of objects: mark, read back, count, invalidate and clean up.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ReadStateServiceInterface {

	/**
	 * The calling user's uid.
	 *
	 * @return string|null The uid, or null when anonymous.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function callerUid(): ?string;

	/**
	 * Record that the calling user has now seen an object.
	 *
	 * Idempotent, and writes nothing on the object itself: no audit entry, no
	 * version.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 * @param string|null $register The register as the caller addressed it.
	 * @param string|null $schema The schema as the caller addressed it.
	 * @param string|null $subResource A sub-resource that was opened, when one was.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return ObjectReadState The stored read state.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markRead(
		ObjectEntity $object,
		?string $register = null,
		?string $schema = null,
		?string $subResource = null
	): ObjectReadState;

	/**
	 * Put an object back to unread for the calling user.
	 *
	 * It stays read for everybody else: the row this removes is nobody's but
	 * the caller's.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 *
	 * @throws NotAuthorizedException When the caller is anonymous.
	 *
	 * @return boolean True when a read state was removed.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markUnread(ObjectEntity $object): bool;

	/**
	 * One user's own read state, and nobody else's.
	 *
	 * The `$userId` argument exists so the refusal is explicit at the one place
	 * that could otherwise leak: a caller naming somebody else is refused rather
	 * than silently answered about themselves, which would be a lie.
	 *
	 * @param ObjectEntity $object The object, already resolved through RBAC.
	 * @param string|null $userId The user asked about, or null for the caller.
	 *
	 * @throws NotAuthorizedException When the caller asks about another user.
	 *
	 * @return ObjectReadState|null The row, or null when the object is unread.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function readStateFor(ObjectEntity $object, ?string $userId = null): ?ObjectReadState;

	/**
	 * How many entries of each sub-resource are unread for the calling user.
	 *
	 * One map, from one read state row plus the object's own body, so a page
	 * renders every tab badge without a call per tab. A sub-resource the schema
	 * does not declare is absent from the map rather than zero, because "no
	 * badge" and "a badge reading nought" are different claims.
	 *
	 * @param ObjectEntity $object The object being rendered.
	 *
	 * @return array<string, int> Sub-resource name to its unread count.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function unreadCounts(ObjectEntity $object): array;

	/**
	 * A write happened: everybody but the actor has something new to see.
	 *
	 * Called by the invalidation listener, which has already asked the evaluator
	 * whether the change was substantive. Kept separate from that question so
	 * the listener stays a wire and this stays the one write.
	 *
	 * @param string $objectUuid The changed object's uuid.
	 * @param string|null $actorUid The user who made the change, whose own read
	 *                              state survives. Null for a system write.
	 *
	 * @return integer How many read states were invalidated.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function invalidate(string $objectUuid, ?string $actorUid = null): int;

	/**
	 * The object is gone, so nothing about it is unread.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many read states were removed.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function cleanupForObject(string $objectUuid): int;
}//end interface
