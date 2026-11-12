<?php

/**
 * OpenRegister render mappers
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\SchemaCatalogInterface;

/**
 * The stores a render reads registers, schemas and objects from.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderMappers {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface $catalog            The register, schema and object stores.
	 * @param CacheHandlerInterface  $objectCacheService The object cache.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly SchemaCatalogInterface $catalog,
		public readonly CacheHandlerInterface $objectCacheService,
	) {
	}//end __construct()
}//end class
