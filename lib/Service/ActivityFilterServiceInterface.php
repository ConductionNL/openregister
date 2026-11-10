<?php

/**
 * ActivityFilterServiceInterface: the activity-stream queries of ActivityFilterService that controllers use.
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
 * The methods of ActivityFilterService that callers outside the Service component use.
 */
interface ActivityFilterServiceInterface {

	/**
	 * Whether the NC Activity app is installed/enabled.
	 *
	 * @return bool
	 */
	public function isActivityAvailable(): bool;

	/**
	 * Fetch a filtered, cursor-paginated page of activity entries for an
	 * OR object.
	 *
	 * @param string $objectUuid OR object UUID (marker target).
	 * @param string|null $type Optional exact activity `type` filter.
	 * @param string|null $actor Optional exact `affecteduser` filter.
	 * @param int|null $after Optional lower-bound Unix timestamp (date range).
	 * @param int $limit Page size (1..MAX_LIMIT, default 100).
	 * @param int|null $cursor Optional cursor — only rows with `timestamp` strictly
	 *                         less than this value are returned (DESC paging).
	 * @param string|null $visibility Optional `internal` / `public` filter. Every row of this
	 *                                source is internal, so a public-only read returns nothing.
	 *
	 * @return array{results: array<int,array<string,mixed>>, total: int, nextCursor: ?int}
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function getActivityEntries(
		string $objectUuid,
		?string $type = null,
		?string $actor = null,
		?int $after = null,
		int $limit = 100,
		?int $cursor = null,
		?string $visibility = null,
	): array;

	/**
	 * Distinct activity `type` values for the dropdown source.
	 *
	 * @param string $objectUuid OR object UUID.
	 *
	 * @return array<int,string>
	 */
	public function getActivityTypes(string $objectUuid): array;

	/**
	 * Distinct actor (`affecteduser`) values for the dropdown source.
	 *
	 * @param string $objectUuid OR object UUID.
	 *
	 * @return array<int,string>
	 */
	public function getActivityActors(string $objectUuid): array;
}//end interface
