<?php

/**
 * OpenRegister views controller services
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller\Support;

use OCA\OpenRegister\Search\Service\ViewPresentationService;
use OCA\OpenRegister\Search\Service\ViewService;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * The services of the saved-views API.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class ViewsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ViewService                  $viewService             The view service
	 * @param ViewPresentationService      $viewPresentationService The view presentation service
	 * @param LoggerInterface              $logger                  Logger for error tracking
	 * @param ViewerReachResolverInterface $viewers                 Resolves the caller and their reach
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		public readonly ViewService $viewService,
		public readonly ViewPresentationService $viewPresentationService,
		public readonly LoggerInterface $logger,
		public readonly ViewerReachResolverInterface $viewers,
	) {
	}//end __construct()
}//end class
