<?php

/**
 * OpenRegister ObjectService mapper services: registers, schemas, views and objects
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;

/**
 * ObjectService mapper services: registers, schemas, views and objects.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectMapperServices {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapperInterface $registerMapper Mapper for register operations.
	 * @param SchemaMapperInterface   $schemaMapper   Mapper for schema operations.
	 * @param ViewMapperInterface     $viewMapper     Mapper for view operations.
	 * @param MagicMapperInterface    $objectMapper   Unified mapper for object operations (routes to magic tables).
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ViewMapperInterface $viewMapper,
		public readonly MagicMapperInterface $objectMapper,
	) {
	}//end __construct()
}//end class
