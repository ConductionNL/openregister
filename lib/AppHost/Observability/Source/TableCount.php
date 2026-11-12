<?php

/**
 * TableCount: the table and filters of one tableCount metric
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Observability\Source
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability\Source;

/**
 * The allowlisted table a tableCount descriptor counts and the filters it
 * declares. Built and consumed by {@see TableMetricSource} only.
 *
 * @spec openspec/specs/apphost-observability/spec.md
 */
final class TableCount {

	/**
	 * Constructor.
	 *
	 * @param string                              $table  Allowlisted table name.
	 * @param array<string, array<string, mixed>> $filter Column => {operator: value}.
	 */
	public function __construct(
		public readonly string $table,
		public readonly array $filter,
	) {
	}//end __construct()
}//end class
