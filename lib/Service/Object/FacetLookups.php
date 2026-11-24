<?php

/**
 * OpenRegister FacetLookups
 *
 * The object and schema lookups facets are computed from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The object and schema lookups facets are computed from.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final readonly class FacetLookups {

	/**
	 * Bundle the lookups.
	 *
	 * @param MagicMapperInterface         $unifiedObjectMapper Unified object mapper with storage routing.
	 * @param SchemaMapperInterface        $schemaMapper        Schema database mapper.
	 * @param RegisterMapperInterface|null $registerMapper      Reads the query's register, so a schema slug
	 *                                                          resolves inside it; nullable and last so no
	 *                                                          construction shifts.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		public MagicMapperInterface $unifiedObjectMapper,
		public SchemaMapperInterface $schemaMapper,
		public ?RegisterMapperInterface $registerMapper = null,
	) {
	}//end __construct()
}//end class
