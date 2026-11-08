<?php

/**
 * PartyIndicatorGuardInterface: the part of PartyIndicatorGuard that the party controller uses.
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

/**
 * The methods of PartyIndicatorGuard that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface PartyIndicatorGuardInterface {

	/**
	 * Every object a party holds a role on, by uuid.
	 *
	 * @param string $partyUuid The party.
	 *
	 * @return array<int, string> The object uuids, each once.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function objectsOfParty(string $partyUuid): array;
}//end interface
