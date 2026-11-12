<?php

/**
 * MagicMapperLookups: the schema, register and lazy service lookups of MagicMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Container\ContainerInterface;

/**
 * Where MagicMapper looks up schemas, registers and the services it resolves lazily.
 *
 * One of the constructor bundles of MagicMapper, built by MapperWiring.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicMapperLookups {
	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface   $schemaMapper   Mapper for schema operations.
	 * @param RegisterMapperInterface $registerMapper Mapper for register operations.
	 * @param ContainerInterface      $container      Container for lazy loading services.
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly ContainerInterface $container,
	) {
	}//end __construct()
}//end class
