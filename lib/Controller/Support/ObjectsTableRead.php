<?php

/**
 * OpenRegister ObjectsTableRead
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

namespace OCA\OpenRegister\Controller\Support;


/**
 * One table read through the global objects route: what was named, what it resolved to, what was asked.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class ObjectsTableRead {
	/**
	 * Hold the read.
	 *
	 * @param ObjectsRouteScope    $scope    The register and schema the request named.
	 * @param array<string, mixed> $resolved The resolved ids and entities.
	 * @param array<string, mixed> $query    The search query.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function __construct(
		public readonly ObjectsRouteScope $scope,
		public readonly array $resolved,
		public readonly array $query = [],
	) {
	}//end __construct()

	/**
	 * This read with its search query.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return self The read.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function withQuery(array $query): self {
		return new self(scope: $this->scope, resolved: $this->resolved, query: $query);
	}//end withQuery()

	/**
	 * The paging keys of the envelope.
	 *
	 * @param int $total The number of rows answered.
	 *
	 * @return array<string, mixed> Total, pages, page and limit.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function paging(int $total): array {
		$limit = ($this->query['_limit'] ?? 20);
		$offset = ($this->query['_offset'] ?? 0);
		$pages = 1;
		$page = 1;
		if ($limit > 0) {
			$pages = (int)ceil($total / $limit);
			$page = (int)floor($offset / $limit) + 1;
		}

		return ['total' => $total, 'pages' => $pages, 'page' => $page, 'limit' => $limit];
	}//end paging()
}//end class
