<?php

/**
 * OpenRegister integrity relation index
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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\ICacheFactory;

/**
 * What referential integrity builds and caches its relation index from.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class IntegrityRelationIndex {
	/**
	 * Schema data mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Register data mapper.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface $catalog      The register and schema stores.
	 * @param ICacheFactory          $cacheFactory Distributed cache for the relation index.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		public readonly ICacheFactory $cacheFactory,
	) {
		$this->schemaMapper   = $catalog->schemas();
		$this->registerMapper = $catalog->registers();
	}//end __construct()
}//end class
