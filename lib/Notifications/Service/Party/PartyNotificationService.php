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
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Party;

use OCA\OpenRegister\Service\Party\PartyAddressSelection;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\MailAddressee;
use OCA\OpenRegister\Notifications\Service\Notification\MailContent;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthority;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutQuestion;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartySendRefusalInterface;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;

/**
 * Resolves and reaches the parties on an object over their own addresses.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
class PartyNotificationService {

	/**
	 * The address kind an outbound message picks.
	 */
	public const OUTBOUND_KIND = 'correspondence';

	/**
	 * The outcome of a party integriq says may not be mailed.
	 */
	public const OUTCOME_REFUSED_OPTED_OUT = 'refused-opted-out';

	/**
	 * The outcome of a party nobody could decide for: integriq is absent.
	 */
	public const OUTCOME_AUTHORITY_UNAVAILABLE = OptOutAuthority::CODE_AUTHORITY_UNAVAILABLE;

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapperInterface     $links   The link rows, for the parties on an object.
	 * @param PartyServiceInterface $parties The party records and their addresses.
	 * @param EmailSender           $email   The email channel.
	 * @param PartyReachGates       $gates   The indicator guard and the opt-out authority.
	 */
	public function __construct(
		private readonly ContactLinkMapperInterface $links,
		private readonly PartyServiceInterface $parties,
		private readonly EmailSender $email,
		private readonly PartyReachGates $gates,
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
	 * @return array<int, array<string, string|null>> One entry per party, each
	 *         carrying `party`, `role`, `displayName`, `address` and `refusedBy`.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
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
	 * A refuse-send indicator comes first, a missing address next. Every
	 * remaining address is put to integriq in one question; a party it
	 * refuses is reported, never dropped (opt-out-before-send).
	 *
	 * @param string $objectUuid The object.
	 * @param string $subject The subject.
	 * @param string $body The body.
	 * @param string|null $role Only the parties in this role, or null for all of them.
	 * @param string $category The message category; absent or unknown reads as `service`.
	 *
	 * @return array<int, array{party: string, address: string|null, outcome: string}> What happened per party.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-parties-notification-asks-integriq-before-it-mails-a-party-req-ero-003
	 */
	public function notifyParties(
		string $objectUuid,
		string $subject,
		string $body,
		?string $role = null,
		string $category = OptOutAuthority::DEFAULT_CATEGORY,
	): array {
		return $this->notify(
			message: ['objectUuid' => $objectUuid, 'subject' => $subject, 'body' => $body, 'category' => $category],
			role: $role
		);
	}//end notifyParties()

	/**
	 * Ask integriq about every askable recipient in one question, then send or refuse per party.
	 *
	 * @param array<string, string> $message The object uuid, subject, body and category.
	 * @param string|null           $role    Only the parties in this role, or null for all of them.
	 *
	 * @return array<int, array{party: string, address: string|null, outcome: string}> What happened per party.
	 */
	private function notify(array $message, ?string $role): array {
		$recipients = $this->recipientsForObject(objectUuid: $message['objectUuid'], role: $role);

		$message['decisions'] = $this->gates->optOut->askQuestion(
			question: new OptOutQuestion(
				channel: 'email',
				category: $message['category'],
				addresses: $this->askable(recipients: $recipients),
				correlationId: 'openregister-parties:' . $message['objectUuid']
			)
		);

		$sent = [];
		foreach ($recipients as $recipient) {
			$sent[] = $this->outcomeFor(recipient: $recipient, message: $message);
		}

		return $sent;
	}//end notify()

	/**
	 * The addresses of the recipients no indicator refuses and that have one.
	 *
	 * @param array<int, array<string, string|null>> $recipients The recipients.
	 *
	 * @return array<int, string> The addresses to put to integriq.
	 */
	private function askable(array $recipients): array {
		$askable = [];
		foreach ($recipients as $recipient) {
			if ($recipient['refusedBy'] === null && $recipient['address'] !== null) {
				$askable[] = (string)$recipient['address'];
			}
		}

		return $askable;
	}//end askable()

	/**
	 * What happens to one recipient: an indicator refusal first, a missing
	 * address next, then integriq's decision; only a decision whose `send`
	 * is boolean true is mailed.
	 *
	 * @param array<string, string|null> $recipient The recipient.
	 * @param array<string, mixed>       $message   The subject, body and integriq's decisions.
	 *
	 * @return array{party: string, address: string|null, outcome: string} The outcome.
	 */
	private function outcomeFor(array $recipient, array $message): array {
		if ($recipient['refusedBy'] !== null) {
			return ['party' => $recipient['party'], 'address' => null, 'outcome' => 'refused-by-indicator'];
		}

		if ($recipient['address'] === null) {
			return ['party' => $recipient['party'], 'address' => null, 'outcome' => EmailSender::OUTCOME_NO_ADDRESS];
		}

		$decision = $this->gates->optOut->decisionFor(decisions: $message['decisions'], address: (string)$recipient['address']);
		if ($decision['send'] !== true) {
			return ['party' => $recipient['party'], 'address' => null, 'outcome' => $this->refusalOutcome(decision: $decision)];
		}

		return [
			'party' => $recipient['party'],
			'address' => $recipient['address'],
			'outcome' => $this->email->sendMailTo(
				addressee: new MailAddressee(address: $recipient['address'], displayName: (string)($recipient['displayName'] ?? '')),
				content: new MailContent(
					subject: $message['subject'],
					body: $this->gates->optOut->withLink(body: $message['body'], unsubscribe: $decision['unsubscribe']),
					unsubscribe: $decision['unsubscribe']
				)
			),
		];
	}//end outcomeFor()

	/**
	 * The outcome of a refused decision: an unavailable authority, else an opt-out.
	 *
	 * @param array<string, mixed> $decision Integriq's decision.
	 *
	 * @return string The outcome.
	 */
	private function refusalOutcome(array $decision): string {
		if ($decision['code'] === OptOutAuthority::CODE_AUTHORITY_UNAVAILABLE) {
			return self::OUTCOME_AUTHORITY_UNAVAILABLE;
		}

		return self::OUTCOME_REFUSED_OPTED_OUT;
	}//end refusalOutcome()

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
			'refusedBy' => $this->gates->indicators->sendRefusalFor(partyUuid: $partyUuid),
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
			selection: new PartyAddressSelection(kind: self::OUTBOUND_KIND, definition: $definition)
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
}//end class
