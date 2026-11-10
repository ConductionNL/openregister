<?php

/**
 * OpenRegister schema migration start
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * A migration a caller asked to start: the schema, the register that holds it and the plan.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class MigrationStart {
	/**
	 * Constructor.
	 *
	 * @param int                      $schemaId   The schema id.
	 * @param int                      $registerId The register containing the schema.
	 * @param array<int|string, mixed> $plan       The validated migration plan.
	 */
	public function __construct(
		public readonly int $schemaId,
		public readonly int $registerId,
		public readonly array $plan,
	) {
	}//end __construct()
}//end class
