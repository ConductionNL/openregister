<?php

/**
 * The services of the timeline administration API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Notifications\Service\Timeline\TextBlockService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindService;

/**
 * The services of the timeline administration API.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final readonly class TimelineAdminControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param TimelineKindService $kinds      The kind registry.
	 * @param ReferenceService    $references The short-code pattern registry.
	 * @param TextBlockService    $blocks     The text block library.
	 * @param ControllerSession   $session    The session, group and logging bundle.
	 */
	public function __construct(
		public TimelineKindService $kinds,
		public ReferenceService $references,
		public TextBlockService $blocks,
		public ControllerSession $session,
	) {
	}//end __construct()
}//end class
