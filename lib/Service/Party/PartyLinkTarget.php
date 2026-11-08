<?php

/**
 * The object a party link is written onto.
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
 * The object, register and schema one party link row records.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
 */
final readonly class PartyLinkTarget {

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int $registerId The register id.
	 * @param int|null $schemaId The object's schema id, null when it is not numeric.
	 */
	public function __construct(
		public string $objectUuid,
		public int $registerId,
		public ?int $schemaId,
	) {
	}//end __construct()
}//end class
