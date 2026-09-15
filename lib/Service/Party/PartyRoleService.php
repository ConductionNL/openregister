<?php

/**
 * A party on an object, in a role, for a period.
 *
 * A property that points at a person answers one question: who is the
 * requester. Every other question needs a second property, and a case with
 * two gemachtigden needs a third. So the party is a row: object, party,
 * role, period. More than one party holds a role, one party holds several
 * roles, and which party the object is filed against is a flag on one of
 * those rows rather than a convention per app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\PersonLinkEvents;
use Throwable;

/**
 * The write and read surface for the parties on an object.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The party role reuses the
 *   link rows, the party records, the schema vocabulary, the audit trail and
 *   the person-link events rather than growing a second copy of any of them.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
 */
class PartyRoleService {

	/**
	 * The audit action written when the party a case is filed against changes.
	 */
	public const PRIMARY_REPLACED_ACTION = 'party.primary-replaced';

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapper $links The link rows.
	 * @param PartyLinkWriter $writer Writes one party link.
	 * @param PartyService $parties The party records.
	 * @param SchemaMapper $schemas The schemas, for the accepted kinds and roles.
	 * @param AuditTrailMapper $audit The audit trail.
	 * @param PersonLinkEvents $events Announces what happened to a link.
	 */
	public function __construct(
		private readonly ContactLinkMapper $links,
		private readonly PartyLinkWriter $writer,
		private readonly PartyService $parties,
		private readonly SchemaMapper $schemas,
		private readonly AuditTrailMapper $audit,
		private readonly PersonLinkEvents $events,
	) {
	}//end __construct()

	/**
	 * The parties on an object, grouped by role, with what the schema accepts.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int|string|null $schemaId The object's schema, for the vocabulary.
	 *
	 * @return array<string, mixed> The listing: `results`, `total`, `byRole`, the
	 *         schema's `kinds` and `roles`, and the `primary` party's uuid.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function listForObject(string $objectUuid, int|string|null $schemaId): array {
		$results = [];
		$byRole = [];
		$primary = null;
		foreach ($this->links->findPartiesForObject(objectUuid: $objectUuid) as $link) {
			$row = $link->jsonSerialize();
			$results[] = $row;
			$role = (string)($link->getRole() ?? '');
			if ($role === '') {
				$role = 'other';
			}

			$byRole[$role][] = $row;
			if ($link->getPrimaryParty() === true) {
				$primary = $link->getPartyUuid();
			}
		}

		$schema = $this->schemaOf(schemaId: $schemaId);

		return [
			'results' => $results,
			'total' => count($results),
			'byRole' => $byRole,
			'kinds' => ($schema?->getPartyKinds() ?? []),
			'roles' => ($schema?->getLinkRoles() ?? []),
			'primary' => $primary,
		];
	}//end listForObject()

	/**
	 * Give a party a role on an object.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int $registerId The register id.
	 * @param int|string|null $schemaId The object's schema id.
	 * @param array<string, mixed> $payload `partyUuid`, `role`, `validFrom`, `validUntil`, `note`, `primary`.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @throws Exception 400 for an undeclared kind or role, 404 for an unknown party.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function addParty(string $objectUuid, int $registerId, int|string|null $schemaId, array $payload): ContactLink {
		$partyUuid = trim((string)($payload['partyUuid'] ?? ''));
		if ($partyUuid === '') {
			throw new Exception('partyUuid is required', 400);
		}

		$role = self::text(value: ($payload['role'] ?? null));
		$this->assertAccepted(schemaId: $schemaId, partyUuid: $partyUuid, role: $role);

		$link = $this->writer->write(
			objectUuid: $objectUuid,
			registerId: $registerId,
			schemaId: self::intOrNull(value: $schemaId),
			partyUuid: $partyUuid,
			role: $role
		);

		$link = $this->applyPeriodAndNote(link: $link, payload: $payload);
		if (($payload['primary'] ?? false) === true) {
			$link = $this->markPrimary(objectUuid: $objectUuid, link: $link);
		}

		$this->events->linked(link: $link);

		return $link;
	}//end addParty()

	/**
	 * Take a party off an object: every role, or the one named.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param string $partyUuid The party.
	 * @param string|null $role Only this role, or null for all.
	 *
	 * @return int How many roles went.
	 *
	 * @throws Exception 404 when the party holds no such role.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function removeParty(string $objectUuid, string $partyUuid, ?string $role = null): int {
		$targets = array_values(
			array_filter(
				$this->links->findPartiesForObject(objectUuid: $objectUuid),
				static fn (ContactLink $link): bool => $link->getPartyUuid() === $partyUuid
					&& ($role === null || $link->getRole() === $role)
			)
		);

		if ($targets === []) {
			throw new Exception('Party link not found', 404);
		}

		foreach ($targets as $link) {
			$this->links->delete($link);
			$this->events->unlinked(link: $link);
		}

		return count($targets);
	}//end removeParty()

	/**
	 * Replace the party the object is filed against, and record it.
	 *
	 * An intake filed on the wrong person is an AVG incident, not a typo, so
	 * the change writes one audit entry naming the party that went, the party
	 * that came, and the actor.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $partyUuid The party that becomes primary.
	 * @param string|null $role The role the new primary party holds.
	 *
	 * @return ContactLink The new primary link.
	 *
	 * @throws Exception 400 for an undeclared kind or role, 404 for an unknown party.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function replacePrimaryParty(ObjectEntity $object, string $partyUuid, ?string $role = null): ContactLink {
		$objectUuid = (string)($object->getUuid() ?? '');
		$previous = $this->links->findPrimaryParty(objectUuid: $objectUuid);
		$link = $this->addParty(
			objectUuid: $objectUuid,
			registerId: (int)($object->getRegister() ?? 0),
			schemaId: $object->getSchema(),
			payload: ['partyUuid' => $partyUuid, 'role' => $role, 'primary' => true]
		);

		$this->audit->createAuditTrailEntry(
			object: $object,
			action: self::PRIMARY_REPLACED_ACTION,
			context: [
				'from' => ($previous?->getPartyUuid() ?? null),
				'fromName' => ($previous?->getDisplayName() ?? null),
				'to' => $partyUuid,
				'toName' => $link->getDisplayName(),
				'role' => $link->getRole(),
			]
		);

		return $link;
	}//end replacePrimaryParty()

	/**
	 * Refuse a party kind or a role the schema does not accept, naming it.
	 *
	 * A schema that declares no party kinds accepts every kind, which is what
	 * keeps a register written before the party model behaving as it did.
	 *
	 * @param int|string|null $schemaId The object's schema.
	 * @param string $partyUuid The party being added.
	 * @param string|null $role The role.
	 *
	 * @return void
	 *
	 * @throws Exception 400 naming the kind or the role, 404 when the party is unknown.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function assertAccepted(int|string|null $schemaId, string $partyUuid, ?string $role): void {
		$schema = $this->schemaOf(schemaId: $schemaId);
		if ($schema === null) {
			return;
		}

		$kinds = $schema->getPartyKinds();
		if ($kinds === []) {
			return;
		}

		$party = $this->parties->find(partyUuid: $partyUuid);
		if ($party === null) {
			throw new Exception('Party not found', 404);
		}

		$definition = $this->parties->definitionFor(party: $party);
		if ($definition === null) {
			throw new Exception('Object "' . $partyUuid . '" is not a party: its schema declares no party model', 400);
		}

		$accepted = null;
		foreach ($kinds as $kind) {
			if ($kind['key'] === $definition->kind()) {
				$accepted = $kind;
				break;
			}
		}

		if ($accepted === null) {
			$keys = array_map(static fn (array $kind): string => $kind['key'], $kinds);
			throw new Exception(
				'Party kind "' . $definition->kind() . '" is not one of: ' . implode(', ', $keys),
				400
			);
		}

		$this->assertRoleOfKind(accepted: $accepted, role: $role);
	}//end assertAccepted()

	/**
	 * Refuse a role the accepted kind does not name; a kind naming none takes any.
	 *
	 * @param array<string, mixed> $accepted The accepted kind entry.
	 * @param string|null $role The role.
	 *
	 * @return void
	 *
	 * @throws Exception 400 naming the kind and its roles.
	 */
	private function assertRoleOfKind(array $accepted, ?string $role): void {
		$roles = ($accepted['roles'] ?? null);
		if (is_array($roles) === false || $roles === [] || $role === null) {
			return;
		}

		if (in_array($role, $roles, true) === true) {
			return;
		}

		throw new Exception(
			'Party kind "' . $accepted['key'] . '" holds only the roles: ' . implode(', ', $roles),
			400
		);
	}//end assertRoleOfKind()

	/**
	 * Make one link the object's primary party, and no other.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param ContactLink $link The link that becomes primary.
	 *
	 * @return ContactLink The stored link.
	 */
	private function markPrimary(string $objectUuid, ContactLink $link): ContactLink {
		foreach ($this->links->findPartiesForObject(objectUuid: $objectUuid) as $other) {
			// The natural key, not the row id: the link that just came back
			// from the writer may not carry an id the caller can compare, and
			// an id comparison that matches everything would leave the old
			// primary party standing beside the new one.
			$isSameRow = ($other->getContactUid() === $link->getContactUid() && $other->getRole() === $link->getRole());
			if ($isSameRow === true || $other->getPrimaryParty() !== true) {
				continue;
			}

			$other->setPrimaryParty(false);
			$this->links->update($other);
		}

		$link->setPrimaryParty(true);

		return $this->links->update($link);
	}//end markPrimary()

	/**
	 * Set validity and note from a payload; a key absent is left alone, empty clears.
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
			$link->setNote(self::text(value: $payload['note']));
			$touched = true;
		}

		if ($touched === false) {
			return $link;
		}

		return $this->links->update($link);
	}//end applyPeriodAndNote()

	/**
	 * The schema behind an id, null when there is none.
	 *
	 * @param int|string|null $schemaId The schema id, uuid or slug.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaOf(int|string|null $schemaId): ?Schema {
		if ($schemaId === null || $schemaId === '' || $schemaId === 0) {
			return null;
		}

		try {
			return $this->schemas->find($schemaId);
		} catch (Throwable) {
			return null;
		}
	}//end schemaOf()

	/**
	 * An int id, or null when the value does not name one.
	 *
	 * @param int|string|null $value The value.
	 *
	 * @return int|null The id.
	 */
	private static function intOrNull(int|string|null $value): ?int {
		if ($value === null || (is_string($value) === true && ctype_digit($value) === false)) {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The text.
	 */
	private static function text(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()

	/**
	 * A date from a payload value, null when empty or unreadable.
	 *
	 * @param mixed $value A `Y-m-d` string.
	 *
	 * @return DateTime|null The date.
	 */
	private static function dateOrNull(mixed $value): ?DateTime {
		$text = self::text(value: $value);
		if ($text === null) {
			return null;
		}

		try {
			return new DateTime($text);
		} catch (Throwable) {
			return null;
		}
	}//end dateOrNull()
}//end class
