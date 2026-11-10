<?php

/**
 * OpenRegister timeline entries domain
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntrySearchService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;

/**
 * The domain services of the timeline entries API.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final class TimelineEntriesDomain {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface              $objects Resolves an object through the RBAC boundary.
	 * @param TimelineEntryService       $entries The record.
	 * @param TimelineWriteService       $writer  Writing an entry end to end.
	 * @param TimelineEntrySearchService $search  Entries searched across objects.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly TimelineEntryService $entries,
		public readonly TimelineWriteService $writer,
		public readonly TimelineEntrySearchService $search,
	) {
	}//end __construct()
}//end class
