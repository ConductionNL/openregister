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

/**
 * The stores a render reads registers, schemas and objects from.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderMappers {
	/**
	 * Bundle the services.
	 *
	 * @param \OCA\OpenRegister\Db\MagicMapperInterface    $objectEntityMapper The object store.
	 * @param \OCA\OpenRegister\Db\RegisterMapperInterface $registerMapper     The register store.
	 * @param \OCA\OpenRegister\Db\SchemaMapperInterface   $schemaMapper       The schema store.
	 * @param CacheHandlerInterface                      $objectCacheService The object cache.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly \OCA\OpenRegister\Db\MagicMapperInterface $objectEntityMapper,
		public readonly \OCA\OpenRegister\Db\RegisterMapperInterface $registerMapper,
		public readonly \OCA\OpenRegister\Db\SchemaMapperInterface $schemaMapper,
		public readonly CacheHandlerInterface $objectCacheService,
	) {
	}//end __construct()
}//end class
