<?php

/**
 * PartySendRefusalInterface: what the party notification service asks of PartyIndicatorGuard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

/**
 * Which declared indicator refuses an outbound message to one party. Only the method PartyNotificationService calls.
 */
interface PartySendRefusalInterface {

	/**
	 * The label of the indicator refusing an outbound message to one party,
	 * or null when nothing refuses it.
	 *
	 * The send refuses PER PARTY rather than per object: one protected party
	 * on a case must not silence the letter to the other five.
	 *
	 * @param string $partyUuid The party.
	 *
	 * @return string|null The refusing indicator's label.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function sendRefusalFor(string $partyUuid): ?string;
}//end interface
