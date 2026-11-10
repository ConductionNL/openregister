<?php

/**
 * OpenRegister ActivityLinksController services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCA\OpenRegister\Service\ActivityFilterServiceInterface;

/**
 * The collaborators of ActivityLinksController, wired by DI autowiring.
 *
 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
 */
final class ActivityLinksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ActivityFilterServiceInterface $filterService Backing service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 * @param TimelineVisibilityServiceInterface $visibility Internal/public filter for the feed.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function __construct(
		public readonly ActivityFilterServiceInterface $filterService,
		public readonly ObjectOperationsInterface $objectService,
		public readonly TimelineVisibilityServiceInterface $visibility,
	) {
	}//end __construct()
}//end class
