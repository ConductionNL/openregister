<?php

/**
 * ValidationMappers: the mappers ValidationHandler reads objects, registers and schemas through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The read side of a validation run: the objects of a schema, the register
 * and schema they belong to, and the magic table rows. Autowired.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ValidationMappers {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface    $objectEntityMapper Mapper for object entities.
	 * @param RegisterMapperInterface $registerMapper     Mapper for registers.
	 * @param SchemaMapperInterface   $schemaMapper       Mapper for schemas.
	 * @param MagicMapperInterface    $magicMapper        Mapper for magic tables.
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectEntityMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly MagicMapperInterface $magicMapper,
	) {
	}//end __construct()
}//end class
