<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * People on objects: a Nextcloud user or a CardDAV contact linked to an
 * object in a role, for a period, with a note.
 *
 * The write surface the contacts controller uses for both kinds. A contact
 * link is delegated to ContactService, which keeps the vCard in step; a
 * user link to UserLinkWriter, which reads the account. The role is
 * checked against the schema's `linkRoles` when it declares any, and
 * every write ends in an event.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md
 */
class PersonLinkService {

	/**
	 * @param ContactLinkMapper $links The link rows.
	 * @param ContactService $contacts The CardDAV side of a contact link.
	 * @param UserLinkWriter $userLinks The account side of a user link.
	 * @param SchemaMapper $schemas The schemas, for the role vocabulary.
	 * @param PersonLinkEvents $events Announces what happened to a link.
	 */
	public function __construct(
		private readonly ContactLinkMapper $links,
		private readonly ContactService $contacts,
		private readonly UserLinkWriter $userLinks,
		private readonly SchemaMapper $schemas,
		private readonly PersonLinkEvents $events,
	) {
	}//end __construct()

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
	public function listForObject(string $objectUuid, ?int $schemaId): array {
		$listing = $this->contacts->getContactsForObject($objectUuid);
		$byRole = [];
		foreach ($listing['results'] as $row) {
			$role = (string)($row['role'] ?? '');
			if ($role === '') {
				$role = 'other';
			}

			$byRole[$role][] = $row;
		}

		return [
			'results' => $listing['results'],
			'total' => $listing['total'],
			'byRole' => $byRole,
			'roles' => $this->roleVocabulary(schemaId: $schemaId),
		];
	}//end listForObject()

	/**
	 * The roles a person can hold on an object of the schema, [] when it declares none.
	 *
	 * @param int|null $schemaId The schema id.
	 *
	 * @return array<int, array{key: string, label: string, description?: string}> The vocabulary.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function roleVocabulary(?int $schemaId): array {
		if ($schemaId === null) {
			return [];
		}

		try {
			$schema = $this->schemas->find($schemaId);
		} catch (Throwable) {
			return [];
		}

		return $schema->getLinkRoles();
	}//end roleVocabulary()

	/**
	 * Link a person to an object: a user by `userId`, a contact by `addressbookId` and `contactUri`.
	 *
	 * The same person in the same role updates the row; in another role adds one.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int $registerId The register id.
	 * @param int|null $schemaId The schema id, for the vocabulary.
	 * @param array<string, mixed> $payload `userId` or `addressbookId`+`contactUri`, with `role`, `validFrom`, `validUntil`, `note`.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @throws Exception 400 for a role outside the vocabulary or a payload naming no person, 404 for an unknown user or contact.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function link(string $objectUuid, int $registerId, ?int $schemaId, array $payload): ContactLink {
		$role = $this->roleOf(payload: $payload);
		$this->assertRoleAllowed(schemaId: $schemaId, role: $role);

		$userId = trim((string)($payload['userId'] ?? ''));
		$link = $this->linkContact(objectUuid: $objectUuid, registerId: $registerId, schemaId: $schemaId, payload: $payload, role: $role, userId: $userId);

		$link = $this->applyPeriodAndNote(link: $link, payload: $payload);
		$this->events->linked(link: $link);

		return $link;
	}//end link()

	/**
	 * Change the role, validity or note of a person's link on an object.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param string $contactUid The person: the vCard uid, or `user:<uid>`.
	 * @param int|null $schemaId The schema id, for the vocabulary.
	 * @param array<string, mixed> $changes `role`, `validFrom`, `validUntil`, `note`; a key absent is left alone, null clears.
	 * @param string|null $currentRole Which of the person's links, when they hold several.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @throws Exception 404 when the person has no such link, 400 for a role outside the vocabulary.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function update(string $objectUuid, string $contactUid, ?int $schemaId, array $changes, ?string $currentRole = null): ContactLink {
		$link = $this->findLink(objectUuid: $objectUuid, contactUid: $contactUid, role: $currentRole);
		if (array_key_exists('role', $changes) === true) {
			$role = $this->roleOf(payload: $changes);
			$this->assertRoleAllowed(schemaId: $schemaId, role: $role);
			$link = $this->applyRole(link: $link, role: $role);
		}

		$link = $this->applyPeriodAndNote(link: $link, payload: $changes);
		$this->events->updated(link: $link);

		return $link;
	}//end update()

	/**
	 * Remove a person from an object: every role, or the one named.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param string $contactUid The person: the vCard uid, or `user:<uid>`.
	 * @param string|null $role Only this role, or null for all.
	 *
	 * @return int How many links went.
	 *
	 * @throws Exception 404 when the person has no such link.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function unlink(string $objectUuid, string $contactUid, ?string $role = null): int {
		$targets = [];
		if ($role !== null) {
			$targets[] = $this->findLink(objectUuid: $objectUuid, contactUid: $contactUid, role: $role);
		}

		if ($role === null) {
			$targets = array_values(
				array_filter(
					$this->links->findByObjectUuid($objectUuid),
					static fn (ContactLink $link): bool => $link->getContactUid() === $contactUid
				)
			);
		}

		if ($targets === []) {
			throw new Exception('Contact link not found', 404);
		}

		foreach ($targets as $link) {
			$this->contacts->unlinkContact(linkId: (int)$link->getId());
			$this->events->unlinked(link: $link);
		}

		return count($targets);
	}//end unlink()

	/**
	 * Write the link: a user link when a user id is given, else a contact link
	 * through ContactService, which keeps the vCard in step.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int $registerId The register id.
	 * @param int|null $schemaId The schema id.
	 * @param array<string, mixed> $payload `addressbookId` and `contactUri`.
	 * @param string|null $role The role.
	 * @param string $userId The account of a user link, '' for a contact link.
	 *
	 * @return ContactLink The link.
	 *
	 * @throws Exception 400 when the payload names no contact.
	 */
	private function linkContact(string $objectUuid, int $registerId, ?int $schemaId, array $payload, ?string $role, string $userId = ''): ContactLink {
		if ($userId !== '') {
			return $this->userLinks->write(objectUuid: $objectUuid, registerId: $registerId, schemaId: $schemaId, userId: $userId, role: $role);
		}

		$addressbookId = (int)($payload['addressbookId'] ?? 0);
		$contactUri = trim((string)($payload['contactUri'] ?? ''));
		if ($addressbookId <= 0 || $contactUri === '') {
			throw new Exception('Either userId or addressbookId+contactUri is required', 400);
		}

		return $this->contacts->linkContact(
			$objectUuid,
			$registerId,
			$addressbookId,
			$contactUri,
			$role,
			$schemaId
		);
	}//end linkContact()

	/**
	 * Set validity and note from a payload; a key absent is left alone, null or '' clears.
	 *
	 * @param ContactLink $link The link.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ContactLink The stored link, unchanged when the payload carried none of the keys.
	 */
	private function applyPeriodAndNote(ContactLink $link, array $payload): ContactLink {
		$touched = false;
		foreach (['validFrom' => 'setValidFrom', 'validUntil' => 'setValidUntil'] as $key => $setter) {
			if (array_key_exists($key, $payload) === false) {
				continue;
			}

			$link->{$setter}(self::dateOrNull(value: $payload[$key]));
			$touched = true;
		}

		if (array_key_exists('note', $payload) === true) {
			$note = trim((string)($payload['note'] ?? ''));
			$link->setNote(null);
			if ($note !== '') {
				$link->setNote($note);
			}

			$touched = true;
		}

		if ($touched === false) {
			return $link;
		}

		return $this->links->update($link);
	}//end applyPeriodAndNote()

	/**
	 * Move a link to another role; a contact link's vCard follows.
	 *
	 * @param ContactLink $link The link.
	 * @param string|null $role The new role.
	 *
	 * @return ContactLink The stored link.
	 */
	private function applyRole(ContactLink $link, ?string $role): ContactLink {
		if ($link->isUserLink() === false && $role !== null) {
			return $this->contacts->updateRole(linkId: (int)$link->getId(), role: $role);
		}

		$link->setRole($role);

		return $this->links->update($link);
	}//end applyRole()

	/**
	 * One link of a person on an object: the role's when named, else the first.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param string $contactUid The person.
	 * @param string|null $role The role, or null for the first link found.
	 *
	 * @return ContactLink The link.
	 *
	 * @throws Exception 404 when there is none.
	 */
	private function findLink(string $objectUuid, string $contactUid, ?string $role): ContactLink {
		$link = null;
		if ($role !== null) {
			$link = $this->links->findByObjectContactAndRole(objectUuid: $objectUuid, contactUid: $contactUid, role: $role);
		}

		if ($role === null) {
			$link = $this->links->findByObjectAndContact(objectUuid: $objectUuid, contactUid: $contactUid);
		}

		if ($link === null) {
			throw new Exception('Contact link not found', 404);
		}

		return $link;
	}//end findLink()

	/**
	 * The role a payload names, trimmed, null when empty.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return string|null The role.
	 */
	private function roleOf(array $payload): ?string {
		$role = trim((string)($payload['role'] ?? ''));
		if ($role === '') {
			return null;
		}

		return $role;
	}//end roleOf()

	/**
	 * Refuse a role the schema's vocabulary does not name; a schema without one accepts any.
	 *
	 * @param int|null $schemaId The schema id.
	 * @param string|null $role The role.
	 *
	 * @return void
	 *
	 * @throws Exception 400 naming the allowed keys.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	private function assertRoleAllowed(?int $schemaId, ?string $role): void {
		$vocabulary = $this->roleVocabulary(schemaId: $schemaId);
		if ($vocabulary === [] || $role === null) {
			return;
		}

		$keys = array_map(static fn (array $entry): string => $entry['key'], $vocabulary);
		if (in_array($role, $keys, true) === true) {
			return;
		}

		throw new Exception('Role "' . $role . '" is not one of: ' . implode(', ', $keys), 400);
	}//end assertRoleAllowed()

	/**
	 * A date from a payload value, null when empty or unreadable.
	 *
	 * @param mixed $value A `Y-m-d` string, or anything DateTime reads.
	 *
	 * @return DateTime|null The date.
	 */
	private static function dateOrNull(mixed $value): ?DateTime {
		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return null;
		}

		try {
			return new DateTime($text);
		} catch (Throwable) {
			return null;
		}
	}//end dateOrNull()
}//end class
