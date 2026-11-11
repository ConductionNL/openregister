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
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\PersonLinkRef;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Writes one party link row.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `ContactLink::partyUid()` is the entity's
 *   named constructor for a party link's uid, the same idiom as the
 *   `USER_UID_PREFIX` the account writer beside this one uses.
 */
class PartyLinkWriter {

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapperInterface $links The link rows.
	 * @param TrustedPartyReaderInterface $parties The party records.
	 * @param IUserSession $session The signed-in user, recorded as the linker.
	 */
	public function __construct(
		private readonly ContactLinkMapperInterface $links,
		private readonly TrustedPartyReaderInterface $parties,
		private readonly IUserSession $session,
	) {
	}//end __construct()

	/**
	 * Write a party link, or refresh the one the party already holds in that role.
	 *
	 * @param PartyLinkRequest $request The object, party, role and named linker; the signed-in user wins over the named linker.
	 *
	 * A trusted import (nobody signed in, a linker named) finds the party
	 * without RBAC, as PartyRoleService accepted it; a signed-in caller finds
	 * it with their own rights.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @throws Exception 404 when no party has that uuid, 400 when the object is not a party, 401 when nobody is signed in and no linker is named.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 * @spec openspec/changes/example-set-install-agrees-with-the-store/specs/example-sets/spec.md
	 */
	public function write(PartyLinkRequest $request): ContactLink {
		$partyUuid = $request->partyUuid;
		$signedIn = $this->session->getUser();
		$party    = $this->readParty(partyUuid: $partyUuid, trusted: ($signedIn === null && $request->namesLinker() === true));
		$definition = $this->definitionOf(party: $party, partyUuid: $partyUuid);

		$linker     = $this->linkerOf(signedIn: $signedIn, linkedBy: $request->linkedBy);

		$target     = $request->target;
		$contactUid = ContactLink::partyUid(partyUuid: $partyUuid);
		$linkRef    = new PersonLinkRef(objectUuid: $target->objectUuid, contactUid: $contactUid, role: $request->role);
		$existing   = $this->links->findByObjectContactAndRole(ref: $linkRef);
		$link       = ($existing ?? $this->newLink(objectUuid: $target->objectUuid, contactUid: $contactUid));

		$link->setRegisterId($target->registerId);
		$link->setSchemaId($target->schemaId);
		$link->setPartyUuid($partyUuid);
		$link->setPartyKind($definition->kind());
		$link->setDisplayName($this->displayNameOf(party: $party, definition: $definition));
		$link->setEmail($this->primaryEmailOf(party: $party, definition: $definition));
		$link->setRole($request->role);
		$link->setLinkedBy($linker);
		$link->setLinkedAt(new DateTime());

		return $this->store(link: $link, isNew: ($existing === null));
	}//end write()

	/**
	 * The party to link: a trusted import reads it without RBAC, anyone else
	 * with their own rights.
	 *
	 * @param string $partyUuid The party object's uuid.
	 * @param bool $trusted Whether nobody is signed in and a linker is named.
	 *
	 * @return ObjectEntity The party.
	 *
	 * @throws Exception 404 when no party has that uuid.
	 */
	private function readParty(string $partyUuid, bool $trusted): ObjectEntity {
		$party = match ($trusted) {
			true  => $this->parties->findUnscoped(uuid: $partyUuid),
			false => $this->parties->find(partyUuid: $partyUuid),
		};
		if ($party === null) {
			throw new Exception('Party not found', 404);
		}

		return $party;
	}//end readParty()

	/**
	 * The party declaration of the party's schema.
	 *
	 * @param ObjectEntity $party The party.
	 * @param string $partyUuid The party object's uuid, for the refusal.
	 *
	 * @return PartyDefinition The declaration.
	 *
	 * @throws Exception 400 when the object is not a party.
	 */
	private function definitionOf(ObjectEntity $party, string $partyUuid): PartyDefinition {
		$definition = $this->parties->definitionFor(party: $party);
		if ($definition === null) {
			throw new Exception('Object "' . $partyUuid . '" is not a party: its schema declares no party model', 400);
		}

		return $definition;
	}//end definitionOf()

	/**
	 * Who links: the signed-in user, else the named linker.
	 *
	 * @param IUser|null $signedIn The signed-in user.
	 * @param string|null $linkedBy The named linker.
	 *
	 * @return string The linker's uid.
	 *
	 * @throws Exception 401 when nobody is signed in and no linker is named.
	 */
	private function linkerOf(?IUser $signedIn, ?string $linkedBy): string {
		$linker = ($signedIn?->getUID() ?? $linkedBy);
		if ($linker === null || $linker === '') {
			throw new Exception('No user logged in', 401);
		}

		return $linker;
	}//end linkerOf()

	/**
	 * Insert a new row, or update the one the party already held.
	 *
	 * @param ContactLink $link The row.
	 * @param bool $isNew Whether the row is new.
	 *
	 * @return ContactLink The link as stored.
	 */
	private function store(ContactLink $link, bool $isNew): ContactLink {
		if ($isNew === true) {
			return $this->links->insert($link);
		}

		return $this->links->update($link);
	}//end store()

	/**
	 * A fresh, non-primary link row for the object and party.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param string $contactUid The party's contact uid.
	 *
	 * @return ContactLink The unsaved row.
	 */
	private function newLink(string $objectUuid, string $contactUid): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid($objectUuid);
		$link->setContactUid($contactUid);
		$link->setPrimaryParty(false);

		return $link;
	}//end newLink()

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
		$preferred = $this->parties->addressesOfKind(party: $party, selection: new PartyAddressSelection(kind: 'correspondence', definition: $definition));
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
