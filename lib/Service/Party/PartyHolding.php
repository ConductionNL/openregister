<?php

/**
 * Which party, in which role, a removal takes off an object.
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

/**
 * A party, and the one role it holds or null for every role it holds.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
 */
final readonly class PartyHolding {

	/**
	 * Constructor.
	 *
	 * @param string      $partyUuid The party.
	 * @param string|null $role      Only this role, or null for all.
	 */
	public function __construct(
		public string $partyUuid,
		public ?string $role = null,
	) {
	}//end __construct()

	/**
	 * Whether a link is the party's, in the one role or in every role.
	 *
	 * @param ContactLink $link The link.
	 *
	 * @return bool
	 */
	public function matches(ContactLink $link): bool {
		return $link->getPartyUuid() === $this->partyUuid
			&& ($this->role === null || $link->getRole() === $this->role);
	}//end matches()
}//end class
