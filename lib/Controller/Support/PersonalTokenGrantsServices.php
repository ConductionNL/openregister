<?php

/**
 * OpenRegister PersonalTokenGrantsController services
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
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantBinder;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;

/**
 * The collaborators of PersonalTokenGrantsController, wired by DI autowiring.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
final class PersonalTokenGrantsServices {
	/**
	 * Bundle the services.
	 *
	 * @param PersonalTokenGrantStore      $store     The personal grants.
	 * @param PersonalTokenGrantBinder     $binder    Tells a token request from an interactive one.
	 * @param TokenGrantSource             $source    The grant a Consumer bound for this request.
	 * @param TokenGrantValidator          $validator Refuses a grant that cannot be issued.
	 * @param PermissionCatalogueInterface $catalogue The verbs this instance knows.
	 */
	public function __construct(
		public readonly PersonalTokenGrantStore $store,
		public readonly PersonalTokenGrantBinder $binder,
		public readonly TokenGrantSource $source,
		public readonly TokenGrantValidator $validator,
		public readonly PermissionCatalogueInterface $catalogue,
	) {
	}//end __construct()
}//end class
