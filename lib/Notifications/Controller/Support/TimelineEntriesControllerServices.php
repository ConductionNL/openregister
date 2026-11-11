<?php

/**
 * OpenRegister timeline entries controller services
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

/**
 * The service bundles of the timeline entries API.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final class TimelineEntriesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param TimelineEntriesDomain     $domain     The domain bundle.
	 * @param TimelineEntriesVisibility $visibility The internal or public flag and who may set it bundle.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly TimelineEntriesDomain $domain,
		public readonly TimelineEntriesVisibility $visibility,
	) {
	}//end __construct()
}//end class
