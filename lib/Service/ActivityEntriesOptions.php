<?php

/**
 * OpenRegister ActivityEntriesOptions
 *
 * The page size and visibility filter of one activity-feed read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * How many activity entries to read and which visibility the reader asked for.
 *
 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
 */
final class ActivityEntriesOptions {
	/**
	 * Constructor.
	 *
	 * @param int         $limit      Page size; the service clamps it to 1..MAX_LIMIT.
	 * @param string|null $visibility Optional `internal` / `public` filter. Every row of this
	 *                                source is internal, so a public-only read returns nothing.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function __construct(
		public readonly int $limit = 100,
		public readonly ?string $visibility = null,
	) {
	}//end __construct()
}//end class
