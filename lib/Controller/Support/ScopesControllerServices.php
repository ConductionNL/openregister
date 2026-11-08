<?php

/**
 * OpenRegister scopes controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * The services of ScopesController, bundled so its constructor stays small.
 *
 */
final class ScopesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapperInterface    $registerMapper    Register lookup.
	 * @param SchemaMapperInterface      $schemaMapper      Schema lookup.
	 * @param PermissionHandlerInterface $permissionHandler RBAC evaluator that
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly PermissionHandlerInterface $permissionHandler,
	) {
	}//end __construct()
}//end class
