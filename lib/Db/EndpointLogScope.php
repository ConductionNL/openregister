<?php

/**
 * OpenRegister Endpoint Log Scope
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The endpoints whose logs a caller may read: the ones they can see, optionally narrowed to one.
 *
 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
 */
final class EndpointLogScope {
	/**
	 * Name the scope.
	 *
	 * @param int[]    $visibleIds The endpoints the caller can see.
	 * @param int|null $endpointId One endpoint to narrow to, or null for all visible ones.
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	public function __construct(
		public readonly array $visibleIds,
		public readonly ?int $endpointId=null,
	) {
	}//end __construct()

	/**
	 * The visible endpoints, or only the one asked for when it is visible.
	 *
	 * @return int[] The endpoints to read; empty when the one asked for is not visible.
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	public function endpointIds(): array {
		if ($this->endpointId === null) {
			return $this->visibleIds;
		}

		return array_values(array_intersect([$this->endpointId], $this->visibleIds));
	}//end endpointIds()
}//end class
