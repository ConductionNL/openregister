<?php

/**
 * Which properties of a party schema carry the name, the addresses, the indicators and the parent.
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
 * The property names a party declaration reads a party object through.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
final readonly class PartyPropertyNames {

	/**
	 * Constructor.
	 *
	 * @param string|null $nameProperty The property carrying the party's display name.
	 * @param string $addressesProperty The property carrying the addresses.
	 * @param string $indicatorsProperty The property carrying the indicators.
	 * @param string|null $parentProperty The property naming the parent party, organisations only.
	 */
	public function __construct(
		public ?string $nameProperty = null,
		public string $addressesProperty = 'addresses',
		public string $indicatorsProperty = 'indicators',
		public ?string $parentProperty = null,
	) {
	}//end __construct()
}//end class
