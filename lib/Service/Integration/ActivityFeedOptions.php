<?php

/**
 * ActivityFeedOptions: how a page of the merged activity feed is cut, and the
 * rows the caller hands in for sources the feed does not read itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * The optional part of an activity-feed page request. Both default to empty:
 * the first page at the default size, and no handed-in rows.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
final class ActivityFeedOptions {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>                          $criteria `pageSize`, `includeReads`, `kinds`, `from`, `until`, `before`,
	 *                                                               as {@see ActivityFeedMerge} reads them.
	 * @param array<string,mixed>                          $handedIn Rows for sources the feed service does not own:
	 *                                                               `file`, `note`, `mail`; a kind whose value is not a
	 *                                                               list contributes nothing.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
	 */
	public function __construct(
		public readonly array $criteria = [],
		public readonly array $handedIn = [],
	) {
	}//end __construct()
}//end class
