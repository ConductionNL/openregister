<?php

/**
 * OpenRegister ContextsController services
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

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCP\IUserSession;

/**
 * The collaborators of ContextsController, wired by DI autowiring.
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
final class ContextsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface        $catalog        The register and schema mappers.
	 * @param JsonLdContextServiceInterface $contextService The JSON-LD context service.
	 * @param IUserSession                  $userSession    Tells a signed-in caller from an anonymous one.
	 * @param PermissionCheckInterface      $permissions    Answers whether a schema is readable anonymously.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function __construct(
		public readonly SchemaCatalogInterface $catalog,
		public readonly JsonLdContextServiceInterface $contextService,
		public readonly IUserSession $userSession,
		public readonly PermissionCheckInterface $permissions,
	) {
	}//end __construct()
}//end class
