<?php

/**
 * Every schema on the instance that declares itself a party schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

/**
 * The party schemas, for callers that search across them.
 *
 * The container binds it to {@see PartyService} in ContractBindings.
 */
interface PartySchemaCatalogueInterface {

	/**
	 * Every schema on the instance that declares itself a party schema.
	 *
	 * @return array<int, array{schema: \OCA\OpenRegister\Db\Schema, definition: PartyDefinition}> The party schemas.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function partySchemas(): array;
}//end interface
