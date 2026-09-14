<?php

/**
 * Writes the link of a party on an object: the party side of the link row,
 * as UserLinkWriter is the account side and ContactService the CardDAV side.
 *
 * A party link stores `party:<uuid>` as its contact uid, takes its display
 * name from the party record, and needs no Nextcloud account and no
 * Contacts app.
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
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUserSession;

/**
 * Writes one party link row.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
 */
class PartyLinkWriter {

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapper $links The link rows.
	 * @param PartyService $parties The party records.
	 * @param IUserSession $session The signed-in user, recorded as the linker.
	 */
	public function __construct(
		private readonly ContactLinkMapper $links,
		private readonly PartyService $parties,
		private readonly IUserSession $session,
	) {
	}//end __construct()

	/**
	 * Write a party link, or refresh the one the party already holds in that role.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int $registerId The register id.
	 * @param int|null $schemaId The object's schema id.
	 * @param string $partyUuid The party object's uuid.
	 * @param string|null $role The role.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @throws Exception 404 when no party has that uuid, 400 when the object is not a party, 401 when nobody is signed in.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function write(string $objectUuid, int $registerId, ?int $schemaId, string $partyUuid, ?string $role): ContactLink {
		$party = $this->parties->find(partyUuid: $partyUuid);
		if ($party === null) {
			throw new Exception('Party not found', 404);
		}

		$definition = $this->parties->definitionFor(party: $party);
		if ($definition === null) {
			throw new Exception('Object "' . $partyUuid . '" is not a party: its schema declares no party model', 400);
		}

		$linker = $this->session->getUser();
		if ($linker === null) {
			throw new Exception('No user logged in', 401);
		}

		$contactUid = ContactLink::partyUid(partyUuid: $partyUuid);
		$link = $this->links->findByObjectContactAndRole(objectUuid: $objectUuid, contactUid: $contactUid, role: $role);
		$isNew = ($link === null);
		if ($link === null) {
			$link = new ContactLink();
			$link->setObjectUuid($objectUuid);
			$link->setContactUid($contactUid);
			$link->setPrimaryParty(false);
		}

		$link->setRegisterId($registerId);
		$link->setSchemaId($schemaId);
		$link->setPartyUuid($partyUuid);
		$link->setPartyKind($definition->kind());
		$link->setDisplayName($this->displayNameOf(party: $party, definition: $definition));
		$link->setEmail($this->primaryEmailOf(party: $party, definition: $definition));
		$link->setRole($role);
		$link->setLinkedBy($linker->getUID());
		$link->setLinkedAt(new DateTime());
		if ($isNew === true) {
			return $this->links->insert($link);
		}

		return $this->links->update($link);
	}//end write()

	/**
	 * The name to show for a party: the declared name property, else the
	 * object's own name, else the uuid, so a chip is never blank.
	 *
	 * @param ObjectEntity $party The party.
	 * @param PartyDefinition $definition The declaration.
	 *
	 * @return string The display name.
	 */
	private function displayNameOf(ObjectEntity $party, PartyDefinition $definition): string {
		$property = $definition->nameProperty();
		if ($property !== null) {
			$value = trim((string)($party->getObject()[$property] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		$name = trim((string)($party->getName() ?? ''));
		if ($name !== '') {
			return $name;
		}

		return (string)($party->getUuid() ?? '');
	}//end displayNameOf()

	/**
	 * The party's correspondence e-mail, cached on the link so a listing needs
	 * no second read; null when the party holds none.
	 *
	 * @param ObjectEntity $party The party.
	 * @param PartyDefinition $definition The declaration.
	 *
	 * @return string|null The address.
	 */
	private function primaryEmailOf(ObjectEntity $party, PartyDefinition $definition): ?string {
		$preferred = $this->parties->addressesOfKind(party: $party, kind: 'correspondence', definition: $definition);
		if ($preferred === []) {
			$preferred = $this->parties->addresses(party: $party, definition: $definition);
		}

		foreach ($preferred as $address) {
			if ($address['type'] === 'email') {
				return $address['value'];
			}
		}

		return null;
	}//end primaryEmailOf()
}//end class
