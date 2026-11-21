<?php

/**
 * OpenRegister RevalidationRequest
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
 * A request to revalidate the objects of one schema in one register, against
 * the current definition or, as a dry run, against a proposed one.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class RevalidationRequest {

	/**
	 * Constructor.
	 *
	 * @param int                       $schemaId           The schema id.
	 * @param int                       $registerId         The register the objects live in.
	 * @param array<string, mixed>|null $proposedDefinition Optional proposed definition (dry-run); null = current.
	 */
	public function __construct(
		public readonly int $schemaId,
		public readonly int $registerId,
		public readonly ?array $proposedDefinition = null,
	) {
	}//end __construct()
}//end class
