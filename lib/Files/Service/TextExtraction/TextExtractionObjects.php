<?php

/**
 * TextExtractionObjects: the object, schema and register mappers of TextExtractionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The object, schema and register mappers of TextExtractionService.
 */
final class TextExtractionObjects {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface    $objectEntityMapper Mapper for object entities
	 * @param SchemaMapperInterface   $schemaMapper       Mapper for schemas
	 * @param RegisterMapperInterface $registerMapper     Mapper for registers
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectEntityMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()
}//end class
