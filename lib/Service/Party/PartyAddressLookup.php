<?php

/**
 * An inbound address sought among the records of one party schema.
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
 * The normalised address and the declaration of the party schema it is sought in.
 *
 * The two always travel together: the declaration says which property holds
 * the addresses the needle is compared with.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
final class PartyAddressLookup {

	/**
	 * Constructor.
	 *
	 * @param string          $needle     The normalised address.
	 * @param PartyDefinition $definition The party schema's declaration.
	 */
	public function __construct(
		public readonly string $needle,
		public readonly PartyDefinition $definition,
	) {
	}//end __construct()
}//end class
