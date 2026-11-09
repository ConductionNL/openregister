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
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\ICacheFactory;

/**
 * What referential integrity builds and caches its relation index from.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class IntegrityRelationIndex {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface   $schemaMapper   Schema data mapper.
	 * @param RegisterMapperInterface $registerMapper Register data mapper.
	 * @param ICacheFactory           $cacheFactory   Distributed cache for the relation index.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()
}//end class
