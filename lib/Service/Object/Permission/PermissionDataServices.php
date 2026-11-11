<?php

/**
 * OpenRegister permission handler data services: mappers, container and logger
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Permission
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

namespace OCA\OpenRegister\Service\Object\Permission;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Permission handler data services: mappers, container and logger.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionDataServices {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface $schemaMapper       Mapper for schema operations.
	 * @param MagicMapperInterface  $objectEntityMapper Mapper for object entity operations.
	 * @param ContainerInterface    $container          Container for lazy loading services.
	 * @param LoggerInterface       $logger             Logger for permission auditing.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly MagicMapperInterface $objectEntityMapper,
		public readonly ContainerInterface $container,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
