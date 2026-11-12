<?php

/**
 * OpenRegister RematerialiseStorage
 *
 * The registers, schemas and object stores the rematerialise-calculations command reads and writes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The registers, schemas and object stores the rematerialise-calculations command reads and writes.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class RematerialiseStorage {
	/**
	 * Register lookup mapper.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema lookup mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Magic table mapper for objects.
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $magicMapper;

	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface    $catalog       Register, schema and magic table mappers.
	 * @param ObjectOperationsInterface $objectService Object persistence service.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		public readonly ObjectOperationsInterface $objectService,
	) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper   = $catalog->schemas();
		$this->magicMapper    = $catalog->tables();
	}//end __construct()
}//end class
