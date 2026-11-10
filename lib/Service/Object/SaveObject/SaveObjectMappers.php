<?php

/**
 * The mappers a single-object save reads and writes through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;

/**
 * The mappers a single-object save reads and writes through.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectMappers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaCatalogInterface $catalog             The register, schema and object entity mappers.
	 * @param MagicMapperInterface   $unifiedObjectMapper Unified object mapper.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly SchemaCatalogInterface $catalog,
		public readonly MagicMapperInterface $unifiedObjectMapper,
	) {
	}//end __construct()
}//end class
