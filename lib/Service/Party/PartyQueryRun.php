<?php

/**
 * One capped party query over the party schemas.
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
 * The search term, the schemas it runs over and the administered cap.
 *
 * ADR-011 Rule 3: the run object of PartySearchService's count, find and
 * refusal helpers. It never leaves that class.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
 */
final class PartyQueryRun {
	/**
	 * Constructor.
	 *
	 * @param string          $term      The trimmed search term.
	 * @param array<int, int> $schemaIds The party schemas searched.
	 * @param int             $cap       The administered maximum result count.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function __construct(
		public readonly string $term,
		public readonly array $schemaIds,
		public readonly int $cap,
	) {
	}//end __construct()
}//end class
