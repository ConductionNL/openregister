<?php

/**
 * TimelineEntryRecords: what TimelineEntryService records an entry with.
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

use OCA\OpenRegister\Notifications\Db\TimelineEntryMapper;

/**
 * The record store, the kind registry and the language detector of an entry.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final readonly class TimelineEntryRecords {
	/**
	 * Bundle the collaborators.
	 *
	 * @param TimelineEntryMapper $entryMapper Reads and writes the record.
	 * @param TimelineKindService $kinds       Declares kinds and validates their fields.
	 * @param LanguageDetector    $languages  Records the language a message arrived in.
	 */
	public function __construct(
		public TimelineEntryMapper $entryMapper,
		public TimelineKindService $kinds,
		public LanguageDetector $languages,
	) {
	}//end __construct()
}//end class
