<?php

/**
 * OpenRegister EndpointsController services
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

use OCA\OpenRegister\Db\EndpointLogMapperInterface;
use OCA\OpenRegister\Db\EndpointMapperInterface;
use OCA\OpenRegister\Service\EndpointServiceInterface;

/**
 * The collaborators of EndpointsController, wired by DI autowiring.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
final class EndpointsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param EndpointMapperInterface $endpointMapper Endpoint mapper for database operations
	 * @param EndpointLogMapperInterface $endpointLogMapper Endpoint log mapper for log operations
	 * @param EndpointServiceInterface $endpointService Endpoint service for business logic
	 * @param ControllerSession $session Session, group manager and logger.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function __construct(
		public readonly EndpointMapperInterface $endpointMapper,
		public readonly EndpointLogMapperInterface $endpointLogMapper,
		public readonly EndpointServiceInterface $endpointService,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
