<?php

/**
 * OpenRegister MigrationRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * A request to migrate the objects of one schema in one register through a
 * declarative transform plan. A migration run and its preview both take it.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class MigrationRequest {

	/**
	 * Constructor.
	 *
	 * @param int                              $schemaId   The schema id.
	 * @param int                              $registerId The register id.
	 * @param array<int, array<string, mixed>> $plan       The transform chain.
	 * @param array<string, mixed>             $options    Run options (stopOnError).
	 */
	public function __construct(
		public readonly int $schemaId,
		public readonly int $registerId,
		public readonly array $plan,
		public readonly array $options = [],
	) {
	}//end __construct()
}//end class
