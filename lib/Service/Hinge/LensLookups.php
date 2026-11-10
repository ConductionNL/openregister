<?php

/**
 * The storage a lens reads the referenced record through: the object finder and the schema lookup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The lookups of LensResolver, wired by DI autowiring.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class LensLookups {
	/**
	 * Bundle the services.
	 *
	 * @param MagicObjectFinderInterface $magicMapper  Object storage, to read the referenced record.
	 * @param SchemaMapperInterface      $schemaMapper Schema lookup for the referenced record.
	 */
	public function __construct(
		public readonly MagicObjectFinderInterface $magicMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
