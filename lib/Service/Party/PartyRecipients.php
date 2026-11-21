<?php

/**
 * The parties on an object, with the address an outbound message goes to.
 *
 * A party is reached over the addresses it holds: the correspondence address
 * when it has one, its first address otherwise, and nothing at all when an
 * indicator on the party refuses the send.
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

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Notifications\PartyRecipientsInterface;

/**
 * Answers the party notifications' recipients question from the party records.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
final class PartyRecipients implements PartyRecipientsInterface {

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapperInterface $links      The link rows, for the parties on an object.
	 * @param PartyServiceInterface      $parties    The party records and their addresses.
	 * @param PartySendRefusalInterface  $indicators The indicator that may refuse a send.
	 */
	public function __construct(
		private readonly ContactLinkMapperInterface $links,
		private readonly PartyServiceInterface $parties,
		private readonly PartySendRefusalInterface $indicators,
	) {
	}//end __construct()

	/**
	 * One entry per party linked to the object, in link order.
	 *
	 * A link that names no party, or a party that no longer exists, is left
	 * out. The indicator is read here, before any opt-out question is asked.
	 *
	 * @param string      $objectUuid The object.
	 * @param string|null $role       Only the parties in this role, or null for all of them.
	 *
	 * @return array<int, array{party: string, role: ?string, displayName: ?string, address: ?string, refusedBy: ?string}> The recipients.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function recipientsForObject(string $objectUuid, ?string $role = null): array {
		$recipients = [];
		foreach ($this->linksInRole(objectUuid: $objectUuid, role: $role) as $link) {
			$recipient = $this->recipientOf(link: $link);
			if ($recipient !== null) {
				$recipients[] = $recipient;
			}
		}

		return $recipients;
	}//end recipientsForObject()

	/**
	 * The object's party links, narrowed to one role when one is given.
	 *
	 * @param string      $objectUuid The object.
	 * @param string|null $role       Only the parties in this role, or null for all of them.
	 *
	 * @return array<int, ContactLink> The links, in the order the mapper returned them.
	 */
	private function linksInRole(string $objectUuid, ?string $role): array {
		$links = [];
		foreach ($this->links->findPartiesForObject(objectUuid: $objectUuid) as $link) {
			if ($role !== null && $link->getRole() !== $role) {
				continue;
			}

			$links[] = $link;
		}

		return $links;
	}//end linksInRole()

	/**
	 * One link's recipient, or null when the link names no party.
	 *
	 * @param ContactLink $link The link.
	 *
	 * @return array{party: string, role: string|null, displayName: string|null, address: string|null, refusedBy: string|null}|null The recipient.
	 */
	private function recipientOf(ContactLink $link): ?array {
		$partyUuid = (string)($link->getPartyUuid() ?? '');
		if ($partyUuid === '') {
			return null;
		}

		$party = $this->parties->find(partyUuid: $partyUuid);
		if ($party === null) {
			return null;
		}

		$definition = $this->parties->definitionFor(party: $party);

		return [
			'party' => $partyUuid,
			'role' => $link->getRole(),
			'displayName' => $link->getDisplayName(),
			'address' => $this->outboundAddress(party: $party, definition: $definition),
			'refusedBy' => $this->indicators->sendRefusalFor(partyUuid: $partyUuid),
		];
	}//end recipientOf()

	/**
	 * The address an outbound message picks: the correspondence one, else the first.
	 *
	 * @param ObjectEntity         $party      The party.
	 * @param PartyDefinition|null $definition The declaration.
	 *
	 * @return string|null The address.
	 */
	private function outboundAddress(ObjectEntity $party, ?PartyDefinition $definition): ?string {
		$addresses = $this->parties->addressesOfKind(
			party: $party,
			selection: new PartyAddressSelection(kind: self::OUTBOUND_KIND, definition: $definition)
		);

		if ($addresses === []) {
			$addresses = $this->parties->addresses(party: $party, definition: $definition);
		}

		return $this->firstEmailAddress(addresses: $addresses);
	}//end outboundAddress()

	/**
	 * The value of the first e-mail address in a list.
	 *
	 * @param array<int, array<string, mixed>> $addresses The party's addresses, in preference order.
	 *
	 * @return string|null The address, or null when none is an e-mail address.
	 */
	private function firstEmailAddress(array $addresses): ?string {
		foreach ($addresses as $address) {
			if ($address['type'] === 'email') {
				return $address['value'];
			}
		}

		return null;
	}//end firstEmailAddress()
}//end class
