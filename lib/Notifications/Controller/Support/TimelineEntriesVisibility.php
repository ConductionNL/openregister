<?php

/**
 * OpenRegister timeline entries visibility
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

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService;
use Psr\Log\LoggerInterface;

/**
 * Services of the timeline entries API.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final class TimelineEntriesVisibility {
	/**
	 * Bundle the services.
	 *
	 * @param ReferenceService          $references What an entry points at.
	 * @param TimelineVisibilityService $visibility The internal or public flag and who may set it.
	 * @param LoggerInterface           $logger     Logger.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly ReferenceService $references,
		public readonly TimelineVisibilityService $visibility,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
