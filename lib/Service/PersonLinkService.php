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
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
class PersonLinkService implements PersonLinkServiceInterface {

	/**
	 * The CardDAV side of a contact link.
	 *
	 * @var ContactService
	 */
	private readonly ContactService $contacts;

	/**
	 * The account side of a user link.
	 *
	 * @var UserLinkWriter
	 */
	private readonly UserLinkWriter $userLinks;

	/**
	 * @param ContactLinkMapperInterface $links The link rows.
	 * @param PersonLinkTargets $targets The CardDAV side of a contact link and the account side of a user link.
	 * @param SchemaMapperInterface $schemas The schemas, for the role vocabulary.
	 * @param PersonLinkEvents $events Announces what happened to a link.
	 */
	public function __construct(
		private readonly ContactLinkMapperInterface $links,
		PersonLinkTargets $targets,
		private readonly SchemaMapperInterface $schemas,
		private readonly PersonLinkEvents $events,
	) {
		$this->contacts = $targets->contacts;
		$this->userLinks = $targets->userLinks;
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

		return [
			'results' => $listing['results'],
			'total' => $listing['total'],
			'byRole' => $this->groupByRole(rows: $listing['results']),
			'roles' => $this->roleVocabulary(schemaId: $schemaId),
		];
	}//end listForObject()

	/**
	 * Group link rows by role; a row without one goes under `other`.
	 *
	 * @param array<int, array<string, mixed>> $rows The link rows.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The rows per role.
	 */
	private function groupByRole(array $rows): array {
		$byRole = [];
		foreach ($rows as $row) {
			$role = (string)($row['role'] ?? '');
			if ($role === '') {
				$role = 'other';
			}

			$byRole[$role][] = $row;
		}

		return $byRole;
	}//end groupByRole()

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

		$link = $this->linkContact(
			target: new PersonLinkTarget(objectUuid: $objectUuid, registerId: $registerId, schemaId: $schemaId),
			payload: $payload,
			role: $role
		);

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
		$targets = $this->unlinkTargets(objectUuid: $objectUuid, contactUid: $contactUid, role: $role);
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
	 * The links an unlink removes: the one in the named role, else all of the person's on the object.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param string $contactUid The person.
	 * @param string|null $role Only this role, or null for all.
	 *
	 * @return array<int, ContactLink> The links.
	 *
	 * @throws Exception 404 when the named role's link does not exist.
	 */
	private function unlinkTargets(string $objectUuid, string $contactUid, ?string $role): array {
		if ($role !== null) {
			return [$this->findLink(objectUuid: $objectUuid, contactUid: $contactUid, role: $role)];
		}

		return array_values(
			array_filter(
				$this->links->findByObjectUuid($objectUuid),
				static fn (ContactLink $link): bool => $link->getContactUid() === $contactUid
			)
		);
	}//end unlinkTargets()

	/**
	 * Write the link: a user link when a user id is given, else a contact link
	 * through ContactService, which keeps the vCard in step.
	 *
	 * @param PersonLinkTarget $target The object linked to.
	 * @param array<string, mixed> $payload `userId`, or `addressbookId` and `contactUri`.
	 * @param string|null $role The role.
	 *
	 * @return ContactLink The link.
	 *
	 * @throws Exception 400 when the payload names no contact.
	 */
	private function linkContact(PersonLinkTarget $target, array $payload, ?string $role): ContactLink {
		$userId = trim((string)($payload['userId'] ?? ''));
		if ($userId !== '') {
			return $this->userLinks->write(
				objectUuid: $target->objectUuid,
				registerId: $target->registerId,
				schemaId: $target->schemaId,
				userId: $userId,
				role: $role
			);
		}

		$contact = [(int)($payload['addressbookId'] ?? 0), trim((string)($payload['contactUri'] ?? ''))];

		return $this->linkAddressbookContact(target: $target, contact: $contact, role: $role);
	}//end linkContact()

	/**
	 * Write a contact link through ContactService, which keeps the vCard in step.
	 *
	 * @param PersonLinkTarget $target The object linked to.
	 * @param array{0: int, 1: string} $contact The addressbook id and the trimmed contact uri.
	 * @param string|null $role The role.
	 *
	 * @return ContactLink The link.
	 *
	 * @throws Exception 400 when the payload names no contact.
	 */
	private function linkAddressbookContact(PersonLinkTarget $target, array $contact, ?string $role): ContactLink {
		if ($contact[0] <= 0 || $contact[1] === '') {
			throw new Exception('Either userId or addressbookId+contactUri is required', 400);
		}

		return $this->contacts->linkContact($target->objectUuid, $target->registerId, $contact[0], $contact[1], $role, $target->schemaId);
	}//end linkAddressbookContact()

	/**
	 * Set validity and note from a payload; a key absent is left alone, null or '' clears.
	 *
	 * @param ContactLink $link The link.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ContactLink The stored link, unchanged when the payload carried none of the keys.
	 */
	private function applyPeriodAndNote(ContactLink $link, array $payload): ContactLink {
		$periodSet = $this->applyPeriod(link: $link, payload: $payload);
		$noteSet   = $this->applyNote(link: $link, payload: $payload);
		if ($periodSet === false && $noteSet === false) {
			return $link;
		}

		return $this->links->update($link);
	}//end applyPeriodAndNote()

	/**
	 * Set the validity dates a payload carries; null or '' clears one.
	 *
	 * @param ContactLink $link The link.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool Whether the payload carried either date.
	 */
	private function applyPeriod(ContactLink $link, array $payload): bool {
		$touched = false;
		foreach (['validFrom' => 'setValidFrom', 'validUntil' => 'setValidUntil'] as $key => $setter) {
			if (array_key_exists($key, $payload) === true) {
				$link->{$setter}(self::dateOrNull(value: $payload[$key]));
				$touched = true;
			}
		}

		return $touched;
	}//end applyPeriod()

	/**
	 * Set the note a payload carries, trimmed; null or '' clears it.
	 *
	 * @param ContactLink $link The link.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool Whether the payload carried a note.
	 */
	private function applyNote(ContactLink $link, array $payload): bool {
		if (array_key_exists('note', $payload) === false) {
			return false;
		}

		$note = trim((string)($payload['note'] ?? ''));
		$link->setNote(null);
		if ($note !== '') {
			$link->setNote($note);
		}

		return true;
	}//end applyNote()

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
		if ($role === null) {
			return;
		}

		$this->assertRoleInVocabulary(vocabulary: $vocabulary, role: $role);
	}//end assertRoleAllowed()

	/**
	 * Refuse a role a non-empty vocabulary does not name.
	 *
	 * @param array<int, array{key: string, label: string, description?: string}> $vocabulary The schema's roles.
	 * @param string $role The role.
	 *
	 * @return void
	 *
	 * @throws Exception 400 naming the allowed keys.
	 */
	private function assertRoleInVocabulary(array $vocabulary, string $role): void {
		if ($vocabulary === []) {
			return;
		}

		$keys = array_map(static fn (array $entry): string => $entry['key'], $vocabulary);
		if (in_array($role, $keys, true) === true) {
			return;
		}

		throw new Exception('Role "' . $role . '" is not one of: ' . implode(', ', $keys), 400);
	}//end assertRoleInVocabulary()

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
