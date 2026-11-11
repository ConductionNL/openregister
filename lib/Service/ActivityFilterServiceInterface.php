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
	 * @param ActivityFilter         $filter  The object, the optional type / actor / lower-bound filters and the
	 *                                        optional cursor (only rows with `timestamp` strictly below it, DESC paging).
	 * @param ActivityEntriesOptions $options The page size and the optional `internal` / `public` visibility filter.
	 *
	 * @return array{results: array<int,array<string,mixed>>, total: int, nextCursor: ?int}
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function getActivityEntries(ActivityFilter $filter, ActivityEntriesOptions $options): array;

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
