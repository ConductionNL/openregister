<?php

/**
 * ListingGather — one paged listing being read: its request headers and the ids gathered so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Sync;

/**
 * The state RestApiSourceFetcher's page helpers share (ADR-011 Rule 3).
 *
 * Built by RestApiSourceFetcher::gather() and never handed out. The ids are
 * the run's progress, appended to page by page.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class ListingGather {

	/**
	 * The ids gathered so far, in page order, duplicates included.
	 *
	 * @var array<int, string>
	 */
	public array $ids = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $headers The request headers every page is fetched with.
	 */
	public function __construct(
		public readonly array $headers,
	) {
	}//end __construct()
}//end class
