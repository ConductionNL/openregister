<?php

/**
 * OpenRegister ApplicationsController services
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

use OCA\OpenRegister\Db\ApplicationMapperInterface;
use OCA\OpenRegister\Service\ApplicationServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of ApplicationsController, wired by DI autowiring.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
final class ApplicationsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ApplicationServiceInterface $applicationService Application service for business logic
	 * @param ApplicationMapperInterface $applicationMapper Application mapper for database operations
	 * @param LoggerInterface $logger Logger for error tracking
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function __construct(
		public readonly ApplicationServiceInterface $applicationService,
		public readonly ApplicationMapperInterface $applicationMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
