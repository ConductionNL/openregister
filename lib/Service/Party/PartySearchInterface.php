<?php

/**
 * The capped party query, as the party API reads it.
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

use Exception;

/**
 * Runs a party query against the administered cap.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
 */
interface PartySearchInterface {

	/**
	 * Search the parties, or refuse when the result set would exceed the cap.
	 *
	 * @param string          $query    The search term.
	 * @param int|string|null $schemaId Restrict to one party schema, or null for all of them.
	 *
	 * @return array{results: array<int, mixed>, total: int, cap: int} The matches.
	 *
	 * @throws Exception 400 when the query is empty, 403 naming the cap when it is exceeded.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function search(string $query, int|string|null $schemaId = null): array;
}//end interface
