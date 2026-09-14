<?php

/**
 * Reaching the parties on an object, accounts or not.
 *
 * The notification subsystem resolves a recipient to a Nextcloud uid, which
 * is the right answer for a handler and no answer at all for a melder. A
 * party is reached over the addresses it holds: the correspondence address
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
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\EmailSender;

/**
 * Resolves and reaches the parties on an object over their own addresses.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
class PartyNotificationService {

	/**
	 * The address kind an outbound message picks.
	 */
	public const OUTBOUND_KIND = 'correspondence';

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapper $links The link rows, for the parties on an object.
	 * @param PartyService $parties The party records and their addresses.
	 * @param EmailSender $email The email channel.
	 */
	public function __construct(
		private readonly ContactLinkMapper $links,
		private readonly PartyService $parties,
		private readonly EmailSender $email,
	) {
	}//end __construct()

	/**
	 * The addresses an outbound message to an object's parties would go to.
	 *
	 * A party carrying a refuse-send indicator is left out and said so, never
	 * dropped in silence: an operator who sees fewer recipients than parties
	 * has to be able to read why.
	 *
	 * @param string $objectUuid The object.
	 * @param string|null $role Only the parties in this role, or null for all of them.
	 *
	 * @return array<int, array{party: string, role: string|null, displayName: string|null, address: string|null, refusedBy: string|null}> The recipients.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function recipientsForObject(string $objectUuid, ?string $role = null): array {
		$recipients = [];
		foreach ($this->links->findPartiesForObject(objectUuid: $objectUuid) as $link) {
			if ($role !== null && $link->getRole() !== $role) {
				continue;
			}

			$recipient = $this->recipientOf(link: $link);
			if ($recipient !== null) {
				$recipients[] = $recipient;
			}
		}

		return $recipients;
	}//end recipientsForObject()

	/**
	 * Send one message to every party on an object that may receive it.
	 *
	 * @param string $objectUuid The object.
	 * @param string $subject The subject.
	 * @param string $body The body.
	 * @param string|null $role Only the parties in this role, or null for all of them.
	 *
	 * @return array<int, array{party: string, address: string|null, outcome: string}> What happened per party.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function notifyParties(string $objectUuid, string $subject, string $body, ?string $role = null): array {
		$sent = [];
		foreach ($this->recipientsForObject(objectUuid: $objectUuid, role: $role) as $recipient) {
			if ($recipient['refusedBy'] !== null) {
				$sent[] = [
					'party' => $recipient['party'],
					'address' => null,
					'outcome' => 'refused-by-indicator',
				];
				continue;
			}

			if ($recipient['address'] === null) {
				$sent[] = [
					'party' => $recipient['party'],
					'address' => null,
					'outcome' => EmailSender::OUTCOME_NO_ADDRESS,
				];
				continue;
			}

			$sent[] = [
				'party' => $recipient['party'],
				'address' => $recipient['address'],
				'outcome' => $this->email->sendToAddress(
					address: $recipient['address'],
					displayName: (string)($recipient['displayName'] ?? ''),
					subject: $subject,
					body: $body
				),
			];
		}//end foreach

		return $sent;
	}//end notifyParties()

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
			'refusedBy' => $this->refusal(party: $party),
		];
	}//end recipientOf()

	/**
	 * The address an outbound message picks: the correspondence one, else the first.
	 *
	 * @param ObjectEntity $party The party.
	 * @param PartyDefinition|null $definition The declaration.
	 *
	 * @return string|null The address.
	 */
	private function outboundAddress(ObjectEntity $party, ?PartyDefinition $definition): ?string {
		$addresses = $this->parties->addressesOfKind(
			party: $party,
			kind: self::OUTBOUND_KIND,
			definition: $definition
		);

		if ($addresses === []) {
			$addresses = $this->parties->addresses(party: $party, definition: $definition);
		}

		foreach ($addresses as $address) {
			if ($address['type'] === 'email') {
				return $address['value'];
			}
		}

		return null;
	}//end outboundAddress()

	/**
	 * The label of the indicator refusing a send to this party, or null.
	 *
	 * @param ObjectEntity $party The party.
	 *
	 * @return string|null The indicator's label.
	 */
	private function refusal(ObjectEntity $party): ?string {
		foreach ($this->parties->indicators(party: $party) as $indicator) {
			if ($indicator['effect'] === PartyIndicatorGuard::EFFECT_REFUSE_SEND) {
				return $indicator['label'];
			}
		}

		return null;
	}//end refusal()
}//end class
