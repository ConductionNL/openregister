<?php

/**
 * PersonLinkServiceInterface: the part of PersonLinkService that the contacts controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\ContactLink;

/**
 * The methods of PersonLinkService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface PersonLinkServiceInterface {

	/**
	 * The people on an object: the links, grouped by role, with the schema's vocabulary.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int|null $schemaId The object's schema, for the vocabulary.
	 *
	 * @return array<string, mixed> The listing: `results`, `total`, `byRole` and the schema's `roles`.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function listForObject(string $objectUuid, ?int $schemaId): array;

	/**
	 * Link a person to an object: a user by `userId`, a contact by `addressbookId` and `contactUri`.
	 *
	 * The same person in the same role updates the row; in another role adds one.
	 *
	 * @param PersonLinkTarget     $target  The object: its uuid, register and schema (for the vocabulary).
	 * @param array<string, mixed> $payload `userId` or `addressbookId`+`contactUri`, with `role`, `validFrom`, `validUntil`, `note`.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @throws Exception 400 for a role outside the vocabulary or a payload naming no person, 404 for an unknown user or contact.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function linkPerson(PersonLinkTarget $target, array $payload): ContactLink;

	/**
	 * Change the role, validity or note of a person's link on an object.
	 *
	 * @param PersonLinkChange $change The link (object, person, and which role when they hold several), the schema
	 *                                 for the vocabulary, and the changes: `role`, `validFrom`, `validUntil`, `note`;
	 *                                 a key absent is left alone, null clears.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @throws Exception 404 when the person has no such link, 400 for a role outside the vocabulary.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function changeLink(PersonLinkChange $change): ContactLink;

	/**
	 * Remove a person from an object: every role, or the one named.
	 *
	 * @param PersonLinkRef $ref The object, the person, and only this role or null for all.
	 *
	 * @return int How many links went.
	 *
	 * @throws Exception 404 when the person has no such link.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function removeLinks(PersonLinkRef $ref): int;
}//end interface
