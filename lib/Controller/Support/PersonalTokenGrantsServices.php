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

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\AccessReviewGateInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantBinder;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
use OCA\OpenRegister\Service\Rbac\TokenGrantPreview;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use OCP\Authentication\Token\IProvider as ITokenProvider;

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
	 * @param ITokenProvider|null           $tokenProvider Resolves an app password its owner pastes into the editor.
	 * @param AccessReviewGateInterface|null $gate        Reads the object a preview is asked about, as the caller.
	 * @param SchemaMapperInterface|null   $schemas   The schema of that object.
	 * @param TokenGrantPreview|null       $preview   Answers the preview.
	 */
	public function __construct(
		public readonly PersonalTokenGrantStore $store,
		public readonly PersonalTokenGrantBinder $binder,
		public readonly TokenGrantSource $source,
		public readonly TokenGrantValidator $validator,
		public readonly PermissionCatalogueInterface $catalogue,
		public readonly ?ITokenProvider $tokenProvider = null,
		public readonly ?AccessReviewGateInterface $gate = null,
		public readonly ?SchemaMapperInterface $schemas = null,
		public readonly ?TokenGrantPreview $preview = null,
	) {
	}//end __construct()
}//end class
