<?php

/**
 * The two gates a party passes before it is mailed.
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

use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthority;
use OCA\OpenRegister\Service\Party\PartySendRefusalInterface;

/**
 * The declared indicators and the opt-out authority, which decide who may be reached.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
final readonly class PartyReachGates {
	/**
	 * Bundle the gates.
	 *
	 * @param PartySendRefusalInterface $indicators The declared effects, which decide who may be reached.
	 * @param OptOutAuthority           $optOut     Asks integriq whether a party's address may be mailed.
	 */
	public function __construct(
		public PartySendRefusalInterface $indicators,
		public OptOutAuthority $optOut,
	) {
	}//end __construct()
}//end class
