<?php

/**
 * The lookups that trace a migrated flow row back to the object it was copied from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Object and schema lookups, bundled for PurgePhantomMigratedFlows.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
final class MigratedFlowOrigins {

	/**
	 * Bundle the lookups.
	 *
	 * @param MagicMapperInterface  $objectMapper Resolves a uuid back to the object it was copied from.
	 * @param SchemaMapperInterface $schemaMapper Turns that object's schema reference into a slug.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
