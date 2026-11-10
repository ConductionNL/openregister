<?php

/**
 * OpenRegister bulk save stores
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Where a bulk save reads its registers and schemas and writes its rows.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveStores {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface    $objectEntityMapper Mapper for object entity database operations
	 * @param SchemaMapperInterface   $schemaMapper       Mapper for schema operations
	 * @param RegisterMapperInterface $registerMapper     Mapper for register operations
	 * @param SaveObjectInterface     $saveHandler        Handler for individual object operations
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectEntityMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SaveObjectInterface $saveHandler,
	) {
	}//end __construct()
}//end class
