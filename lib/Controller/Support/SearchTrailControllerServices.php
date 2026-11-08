<?php

/**
 * OpenRegister search-trail controller services
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

use OCA\OpenRegister\Db\SearchTrailMapperInterface;
use OCA\OpenRegister\Service\SearchTrailServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The services of SearchTrailController, bundled so its constructor stays small.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
 */
final class SearchTrailControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param SearchTrailServiceInterface $searchTrailService The search trail service
	 * @param SearchTrailMapperInterface  $searchTrailMapper  Mapper the admin clear-all path deletes through
	 * @param IUserSession                $userSession        Active user session for caller identity
	 * @param IGroupManager               $groupManager       Group manager for admin / role checks
	 */
	public function __construct(
		public readonly SearchTrailServiceInterface $searchTrailService,
		public readonly SearchTrailMapperInterface $searchTrailMapper,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
