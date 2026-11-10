<?php

/**
 * OpenRegister GraphQL controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Search\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller\Support;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;
use OCP\IURLGenerator;

/**
 * The services the GraphQL controller works with: the GraphQL executor, and for
 * the GraphiQL explorer page the endpoint link, the CSP nonce of its inline
 * script and the request token it posts with.
 *
 * @spec openspec/specs/graphql-api/spec.md
 */
final class GraphQLControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param GraphQLService                    $graphQLService GraphQL executor
	 * @param IURLGenerator                     $urlGenerator   URL generator for route linking
	 * @param ContentSecurityPolicyNonceManager $nonceManager   CSP nonce provider
	 * @param CsrfTokenManager                  $csrfManager    CSRF token manager
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function __construct(
		public readonly GraphQLService $graphQLService,
		public readonly IURLGenerator $urlGenerator,
		public readonly ContentSecurityPolicyNonceManager $nonceManager,
		public readonly CsrfTokenManager $csrfManager,
	) {
	}//end __construct()
}//end class
