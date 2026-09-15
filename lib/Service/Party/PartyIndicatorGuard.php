<?php

/**
 * An indicator on a party, evaluated where the act happens.
 *
 * An indicator that only renders is an indicator somebody misses. Each one
 * declares what it does — warn the reader, refuse publication, refuse an
 * outbound message — and this guard is what the act calls before it acts.
 * The refusal names the indicator and the party, because "publication
 * refused" with no name is a refusal nobody can act on.
 *
 * The indicators are read from the party's side: the link table is indexed
 * on the party uuid, so every object a party holds a role on is one query
 * and setting an indicator writes the party and nothing else.
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

use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;

/**
 * Evaluates the declared effect of every indicator on every party of an object.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
 */
class PartyIndicatorGuard {

	/**
	 * The effect refusing a publication.
	 */
	public const EFFECT_REFUSE_PUBLICATION = 'refuse-publication';

	/**
	 * The effect refusing an outbound message.
	 */
	public const EFFECT_REFUSE_SEND = 'refuse-send';

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapper $links The link rows, for the parties on an object.
	 * @param PartyService $parties The party records.
	 */
	public function __construct(
		private readonly ContactLinkMapper $links,
		private readonly PartyService $parties,
	) {
	}//end __construct()

	/**
	 * Every indicator every party on an object carries, each naming its party.
	 *
	 * Reading the object's indicators never writes the object: the indicator
	 * lives on the party, and three cases of one party read the same row.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return array<int, array<string, string|null>> One entry per indicator, each
	 *         carrying `party`, `partyKind`, `role`, `key`, `label`, `effect` and `note`.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function indicatorsForObject(string $objectUuid): array {
		$found = [];
		foreach ($this->links->findPartiesForObject(objectUuid: $objectUuid) as $link) {
			foreach ($this->indicatorsOfLink(link: $link) as $indicator) {
				$found[] = $indicator;
			}
		}

		return $found;
	}//end indicatorsForObject()

	/**
	 * Every object a party holds a role on, by uuid.
	 *
	 * @param string $partyUuid The party.
	 *
	 * @return array<int, string> The object uuids, each once.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function objectsOfParty(string $partyUuid): array {
		$uuids = [];
		foreach ($this->links->findByPartyUuid(partyUuid: $partyUuid) as $link) {
			$uuid = (string)($link->getObjectUuid() ?? '');
			if ($uuid !== '' && in_array($uuid, $uuids, true) === false) {
				$uuids[] = $uuid;
			}
		}

		return $uuids;
	}//end objectsOfParty()

	/**
	 * Refuse to publish an object when a party on it says so.
	 *
	 * @param string $objectUuid The object about to be published.
	 *
	 * @return void
	 *
	 * @throws Exception 403 naming the indicator and the party.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function assertPublicationAllowed(string $objectUuid): void {
		foreach ($this->indicatorsForObject(objectUuid: $objectUuid) as $indicator) {
			if ($indicator['effect'] !== self::EFFECT_REFUSE_PUBLICATION) {
				continue;
			}

			throw new Exception(
				'Publication is refused by the indicator "' . $indicator['label'] . '" on party "'
				. $indicator['party'] . '"',
				403
			);
		}
	}//end assertPublicationAllowed()

	/**
	 * The label of the indicator refusing an outbound message to one party,
	 * or null when nothing refuses it.
	 *
	 * The send refuses PER PARTY rather than per object: one protected party
	 * on a case must not silence the letter to the other five. The caller
	 * leaves that party out and says which indicator did it, because a
	 * recipient list that is quietly shorter than the party list is a bug
	 * nobody can see.
	 *
	 * @param string $partyUuid The party.
	 *
	 * @return string|null The refusing indicator's label.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function sendRefusalFor(string $partyUuid): ?string {
		$party = $this->parties->find(partyUuid: $partyUuid);
		if ($party === null) {
			return null;
		}

		foreach ($this->parties->indicators(party: $party) as $indicator) {
			if ($indicator['effect'] === self::EFFECT_REFUSE_SEND) {
				return $indicator['label'];
			}
		}

		return null;
	}//end sendRefusalFor()

	/**
	 * The indicators behind one link, each stamped with its party and role.
	 *
	 * @param ContactLink $link The link.
	 *
	 * @return array<int, array<string, string|null>> One entry per indicator, each
	 *         carrying `party`, `partyKind`, `role`, `key`, `label`, `effect` and `note`.
	 */
	private function indicatorsOfLink(ContactLink $link): array {
		$partyUuid = (string)($link->getPartyUuid() ?? '');
		if ($partyUuid === '') {
			return [];
		}

		$party = $this->parties->find(partyUuid: $partyUuid);
		if ($party === null) {
			return [];
		}

		$found = [];
		foreach ($this->parties->indicators(party: $party) as $indicator) {
			$found[] = array_merge(
				[
					'party' => $partyUuid,
					'partyKind' => $link->getPartyKind(),
					'role' => $link->getRole(),
				],
				$indicator
			);
		}

		return $found;
	}//end indicatorsOfLink()
}//end class
