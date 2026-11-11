<?php

/**
 * What a merge of two parties of one schema keeps and unions.
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
 * The merge rules a party declaration carries.
 *
 * `unionAddresses` is a value the schema declares, not a mode a caller picks:
 * it is read off the annotation and carried.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
final readonly class PartyMergeRules {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $survivingProperties Properties the merge keeps from the survivor.
	 * @param bool $unionAddresses Whether a merge unions both parties' addresses.
	 */
	public function __construct(
		public array $survivingProperties,
		public bool $unionAddresses,
	) {
	}//end __construct()
}//end class
