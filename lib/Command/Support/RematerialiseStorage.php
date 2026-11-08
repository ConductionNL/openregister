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
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The registers, schemas and object stores the rematerialise-calculations command reads and writes.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class RematerialiseStorage {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapperInterface   $registerMapper Register lookup mapper.
	 * @param SchemaMapperInterface     $schemaMapper   Schema lookup mapper.
	 * @param MagicMapperInterface      $magicMapper    Magic table mapper for objects.
	 * @param ObjectOperationsInterface $objectService  Object persistence service.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly MagicMapperInterface $magicMapper,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
