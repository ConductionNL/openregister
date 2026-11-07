<?php

/**
 * OpenRegister AutoTransitionPlanning
 *
 * The selector and queue that decide and hold the automatic transitions to run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The selector and queue that decide and hold the automatic transitions to run.
 *
 * Groups collaborators of {@see AutoTransitionRunner} so its constructor stays small;
 * the container autowires it.
 */
final class AutoTransitionPlanning {

	/**
	 * Bundle the collaborators.
	 *
	 * @param AutoTransitionSelector $selector Picks the one eligible move, or none.
	 * @param AutoTransitionQueue    $queue    Decides inline versus queued, and owns the job list.
	 */
	public function __construct(
		public readonly AutoTransitionSelector $selector,
		public readonly AutoTransitionQueue $queue,
	) {
	}//end __construct()
}//end class
