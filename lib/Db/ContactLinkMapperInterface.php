<?php

/**
 * ContactLinkMapperInterface: what callers outside the Db component use of ContactLinkMapper.
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

use OCA\OpenRegister\Service\PersonLinkRef;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of ContactLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ContactLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface ContactLinkMapperInterface {

	/**
	 * One link by its row id.
	 *
	 * QBMapper has no `find()`, and ContactService::unlinkContact(),
	 * updateRole() and the controller's legacy id path all call one: every
	 * unlink of a contact answered 500 with "Call to undefined method
	 * ContactLinkMapper::find()". The service's own tests did not catch it
	 * because the mapper double declared the method with `addMethods(['find'])`
	 * — a double that adds a method the real class lacks can only pass.
	 *
	 * @param int $id The row id.
	 *
	 * @return ContactLink The link.
	 *
	 * @throws DoesNotExistException When no link has that id.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function find(int $id): ContactLink;

	/**
	 * Find contact links by contact UID.
	 *
	 * @param string $contactUid The contact UID from the vCard.
	 *
	 * @return ContactLink[] Array of contact links.
	 */
	public function findByContactUid(string $contactUid): array;

	/**
	 * Find the single link for an (objectUuid, contactUid) pair.
	 *
	 * Backs the upsert path in `ContactService::linkContact()` — the DB
	 * already enforces uniqueness via the Tier-2 composite index
	 * `idx_contact_object_uid_uniq`, but reading first lets the service
	 * update the cached vCard fields (phone/org/avatar) and role
	 * in-place instead of failing on the duplicate-key.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $contactUid The vCard UID.
	 *
	 * @return ContactLink|null The link, or null when no row exists.
	 */
	public function findByObjectAndContact(string $objectUuid, string $contactUid): ?ContactLink;

	/**
	 * The link of one person on one object in one role, or null.
	 *
	 * The upsert key since people-on-objects: a person may hold several
	 * roles on an object, one row each.
	 *
	 * @param PersonLinkRef $ref The object, the contact uid (`user:<uid>` for a user), and the role, null for a link without one.
	 *
	 * @return ContactLink|null The link.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function findByObjectContactAndRole(PersonLinkRef $ref): ?ContactLink;

	/**
	 * Every link that names a party, newest first.
	 *
	 * This is the read behind "an indicator reaches every case of that
	 * party": the objects are found from the party's side, so setting an
	 * indicator writes the party and nothing else.
	 *
	 * @param string $partyUuid The party object's uuid.
	 *
	 * @return ContactLink[] The links.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function findByPartyUuid(string $partyUuid): array;

	/**
	 * Every party link on an object, oldest first.
	 *
	 * @param string $objectUuid The object uuid.
	 *
	 * @return ContactLink[] The links naming a party.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function findPartiesForObject(string $objectUuid): array;

	/**
	 * The link marking the party the object is filed against, or null.
	 *
	 * @param string $objectUuid The object uuid.
	 *
	 * @return ContactLink|null The primary party's link.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function findPrimaryParty(string $objectUuid): ?ContactLink;

	/**
	 * Every link whose metadata records that a merge operation moved it.
	 *
	 * The memo lives on the row the merge changed, so reversing a merge is a
	 * read of the rows themselves rather than surgery on the merge snapshot.
	 * The LIKE narrows; the caller decodes the memo and decides, so a row
	 * whose metadata merely contains the id as text is never acted on.
	 *
	 * @param string $operationId The merge operation's uuid.
	 *
	 * @return ContactLink[] The candidate links.
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function findByOperationMemo(string $operationId): array;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return ContactLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Delete all links of an object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function deleteByObjectUuid(string $objectUuid): int;

	/**
	 * Count the links of an object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Count of links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function countByObjectUuid(string $objectUuid): int;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return ContactLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return ContactLink The updated row.
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete one row.
	 *
	 * @param Entity $entity The row to delete.
	 *
	 * @return ContactLink The deleted row.
	 */
	public function delete(Entity $entity): Entity;
}//end interface
