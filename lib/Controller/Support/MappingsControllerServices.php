<?php

/**
 * OpenRegister mappings controller services
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

use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingServiceInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IAppConfig;

/**
 * The services of the mappings API.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
final class MappingsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IAppConfig          $config              App configuration
	 * @param MappingMapper       $mappingMapper       Mapping mapper for database operations
	 * @param MappingServiceInterface      $mappingService      Mapping service for executing mappings
	 * @param OrganisationServiceInterface $organisationService Organisation service for multi-tenancy
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function __construct(
		public readonly IAppConfig $config,
		public readonly MappingMapper $mappingMapper,
		public readonly MappingServiceInterface $mappingService,
		public readonly OrganisationServiceInterface $organisationService,
	) {
	}//end __construct()
}//end class
