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
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The object, schema and register mappers of TextExtractionService.
 */
final class TextExtractionObjects {
	/**
	 * Mapper for object entities.
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $objectEntityMapper;

	/**
	 * Mapper for schemas.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Mapper for registers.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Take the mappers from the schema catalog.
	 *
	 * @param SchemaCatalogInterface $catalog The register, schema and magic-table mappers.
	 */
	public function __construct(SchemaCatalogInterface $catalog) {
		$this->objectEntityMapper = $catalog->tables();
		$this->schemaMapper = $catalog->schemas();
		$this->registerMapper = $catalog->registers();
	}//end __construct()
}//end class
