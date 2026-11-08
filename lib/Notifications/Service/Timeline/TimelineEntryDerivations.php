<?php

/**
 * TimelineEntryDerivations: the steps TimelineWriteService derives from an entry's text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

/**
 * The references, mentions and text blocks a written entry's text produces.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final readonly class TimelineEntryDerivations {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ReferenceService    $references Records what the text points at.
	 * @param EntryMentionService $mentions   Notifies and subscribes whoever was named.
	 * @param TextBlockService    $blocks     Inserts administered canned text.
	 */
	public function __construct(
		public ReferenceService $references,
		public EntryMentionService $mentions,
		public TextBlockService $blocks,
	) {
	}//end __construct()
}//end class
