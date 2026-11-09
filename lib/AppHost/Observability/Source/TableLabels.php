<?php

/**
 * TableLabels: how a grouped tableCount row becomes metric labels
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
 * The group-by columns of a tableCount descriptor, the label each column is
 * published under, and the value an empty column falls back to. Built and
 * consumed by {@see TableMetricSource} only.
 *
 * @spec openspec/specs/apphost-observability/spec.md
 */
final class TableLabels {

	/**
	 * Constructor.
	 *
	 * @param array<int, string>    $columns  Group-by columns, in order.
	 * @param array<string, string> $map      Column => label key; an unmapped column is its own label key.
	 * @param array<string, string> $defaults Column => value for a null or empty column.
	 */
	public function __construct(
		public readonly array $columns,
		public readonly array $map,
		public readonly array $defaults,
	) {
	}//end __construct()
}//end class
