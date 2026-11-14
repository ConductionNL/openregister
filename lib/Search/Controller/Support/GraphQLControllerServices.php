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

use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;

/**
 * The services the GraphQL controller works with: the GraphQL executor. The
 * explorer page is a bundled template, so it needs no endpoint link, CSP nonce
 * or request token from the controller.
 *
 * @spec openspec/specs/graphql-api/spec.md
 */
final class GraphQLControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param GraphQLService $graphQLService GraphQL executor
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function __construct(
		public readonly GraphQLService $graphQLService,
	) {
	}//end __construct()
}//end class
