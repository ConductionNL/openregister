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
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The mappers a single-object save reads and writes through.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectMappers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicMapperInterface    $objectEntityMapper  Object entity mapper.
	 * @param MagicMapperInterface    $unifiedObjectMapper Unified object mapper.
	 * @param SchemaMapperInterface   $schemaMapper        Schema mapper.
	 * @param RegisterMapperInterface $registerMapper      Register mapper.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectEntityMapper,
		public readonly MagicMapperInterface $unifiedObjectMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()
}//end class
