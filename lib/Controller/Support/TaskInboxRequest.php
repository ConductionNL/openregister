<?php

/**
 * OpenRegister TaskInboxRequest
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
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Exception\ActionParameterTypeException;

/**
 * A request for one inbox page: the audience scope and the listing.
 *
 * The listing's filters carry the raw query strings of the route
 * (`state`, `isTerminal`, `priority`, `kind`, `objectUuid`, `runUuid`,
 * `overdue`, `dueAfter`, `dueBefore`, `includeBlocked`, `openFirst`); its
 * sort is the one `sort` key, a leading `-` making it descending.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */
final class TaskInboxRequest {

	/**
	 * The query parameters the listing carries as filters.
	 *
	 * @var list<string>
	 */
	private const FILTERS = [
		'state', 'isTerminal', 'priority', 'kind', 'objectUuid', 'runUuid',
		'overdue', 'dueAfter', 'dueBefore', 'includeBlocked', 'openFirst',
	];

	/**
	 * Describe the request.
	 *
	 * @param string    $scope The audience scope: assigned, pooled, watched or all.
	 * @param ListQuery $query The page, filters and sort.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function __construct(
		public readonly string $scope,
		public readonly ListQuery $query,
	) {
	}//end __construct()

	/**
	 * The inbox request of the route's query parameters.
	 *
	 * @param array<array-key, mixed> $params The request parameters.
	 *
	 * @return self The request.
	 *
	 * @throws ActionParameterTypeException When a string parameter is not a string.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public static function fromParams(array $params): self {
		$read    = new ActionParameters(params: $params);
		$filters = [];
		foreach (self::FILTERS as $key) {
			$filters[$key] = $read->string(key: $key);
		}

		$sort      = (string)$read->string(key: 'sort', default: TaskInboxCriteria::SORT_DUE);
		$direction = 'asc';
		if (str_starts_with($sort, '-') === true) {
			$direction = 'desc';
		}

		$query = new ListQuery(
			limit: $read->int(key: 'limit', default: 25),
			offset: $read->int(key: 'offset', default: 0),
			filters: $filters,
			sort: [ltrim($sort, '-') => $direction]
		);

		return new self(scope: (string)$read->string(key: 'scope', default: TaskInboxCriteria::SCOPE_ASSIGNED), query: $query);
	}//end fromParams()
}//end class
