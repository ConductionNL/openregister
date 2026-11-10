<?php

/**
 * OpenRegister processing activities controller services
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

use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IDBConnection;

/**
 * The services of the processing activities (verwerkingsactiviteiten) API.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class VerwerkingsactiviteitenControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param VerwerkingsactiviteitMapperInterface $vrwMapper           Mapper for the catalog.
	 * @param ControllerSession                    $session             Session, group manager and logger.
	 * @param IDBConnection                        $db                  DB for the verantwoording aggregation.
	 * @param OrganisationServiceInterface         $organisationService Org-scoping helper.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function __construct(
		public readonly VerwerkingsactiviteitMapperInterface $vrwMapper,
		public readonly ControllerSession $session,
		public readonly IDBConnection $db,
		public readonly OrganisationServiceInterface $organisationService,
	) {
	}//end __construct()
}//end class
