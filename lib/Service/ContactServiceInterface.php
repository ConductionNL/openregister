<?php

/**
 * ContactServiceInterface: the contact-link operations of ContactService that controllers use.
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
 * The methods of ContactService that callers outside the Service component use.
 */
interface ContactServiceInterface {

	/**
	 * Get all contact links for an object.
	 *
	 * Each row is the canonical {@see ContactLink::jsonSerialize()} payload
	 * enriched with `phone`, `org`, and `avatarUrl` resolved from the
	 * underlying vCard. The bespoke CnContactsCard surface
	 * (`single-entity` chip) reads `avatarUrl` for the round avatar; the
	 * `detail-page` and tab surfaces use `phone` + `org` to differentiate
	 * organisational vs. personal contacts.
	 *
	 * Enrichment is best-effort:
	 *   * If CardDAV throws or the card no longer exists, the link is
	 *     still returned but `phone` / `org` / `avatarUrl` are `null`.
	 *   * If the vCard is present but lacks any of TEL / ORG / PHOTO,
	 *     the corresponding field is `null`.
	 *   * The cached `phone` / `org` / `avatar_url` columns on the row
	 *     are used as long as the link is younger than
	 *     `ENRICHMENT_TTL_SECONDS`; older rows trigger a fresh vCard
	 *     read + a non-blocking persist of the re-enriched values.
	 *
	 * Idempotent: re-running the enrichment doesn't double-write — the
	 * widened keys are computed fresh each call.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return array{results: array, total: int}
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function getContactsForObject(string $objectUuid): array;

	/**
	 * Create a new contact and link it to an object.
	 *
	 * Tier-2: extended to persist `phone` / `org` straight from the
	 * caller-supplied payload, and to accept an optional `schemaId`
	 * + free-form `org` field. The avatar URL falls back to the
	 * per-uid Contacts route (no PHOTO is set on freshly-created
	 * vCards yet).
	 *
	 * @param ContactPlacement $placement Where it is linked (object, register, schema) and in what role.
	 * @param array            $data      Contact data: `fullName` or `displayName`, `email`, `phone`, `org`.
	 *
	 * @return ContactLink The created link.
	 *
	 * @throws Exception If no user or addressbook.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function createAndLinkContact(ContactPlacement $placement, array $data): ContactLink;

	/**
	 * Remove a contact link.
	 *
	 * Idempotent: tolerates a missing or corrupt vCard so the link row
	 * can always be cleaned. If the underlying vCard has been removed
	 * from the addressbook (e.g. user deleted the contact via NC
	 * Contacts), the CardDAV custom-property cleanup is skipped and the
	 * link row is still dropped. Any Throwable from the cleanup path is
	 * caught and logged at warning level — the link row deletion
	 * proceeds regardless so orphan rows are recoverable through this
	 * path rather than only via direct DB DELETE.
	 *
	 * @param int         $linkId     The link ID.
	 * @param string|null $objectUuid When given, the object the link must belong to.
	 *
	 * @return void
	 *
	 * @throws Exception If the link row isn't found, or belongs to another object (404).
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function unlinkContact(int $linkId, ?string $objectUuid = null): void;

	/**
	 * Find all objects linked to a contact.
	 *
	 * @param string $contactUid The contact UID.
	 *
	 * @return array Array of contact links with object UUIDs and roles.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function getObjectsForContact(string $contactUid): array;
}//end interface
