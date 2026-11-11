<?php

/**
 * OpenRegister RegisterMapperServices
 *
 * What a register mapper reads through: the schema mapper, the container for the lazy magic mapper, the dispatcher and the logger.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What a register mapper reads through: the schema mapper, the container for the lazy magic mapper, the dispatcher and the logger.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
final readonly class RegisterMapperServices {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaMapperInterface $schemaMapper    Schema mapper for schema operations.
	 * @param ContainerInterface    $container       Container for lazy MagicMapper resolution.
	 * @param IEventDispatcher      $eventDispatcher Event dispatcher for register events.
	 * @param LoggerInterface       $logger          Structured logger for the resolution diagnostics.
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public ContainerInterface $container,
		public IEventDispatcher $eventDispatcher,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
