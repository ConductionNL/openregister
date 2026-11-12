<?php

/**
 * OpenRegister schema cache slot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Where one piece of schema data is cached: the schema and the cache key.
 *
 * The identity of one row in the schema cache table (ADR-011 Rule 2). Not
 * strict, like SchemaCacheHandler, which builds it from entity getters.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SchemaCacheSlot {
	/**
	 * Name the slot.
	 *
	 * @param int    $schemaId The schema the data belongs to.
	 * @param string $cacheKey Which of the schema's cached values this is.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly int $schemaId,
		public readonly string $cacheKey,
	) {
	}//end __construct()
}//end class
