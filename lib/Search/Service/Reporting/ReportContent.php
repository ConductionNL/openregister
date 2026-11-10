<?php

/**
 * OpenRegister ReportContent
 *
 * What a report renders: the dashboard payload and its resolved widgets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Reporting;

/**
 * What a report renders: the dashboard payload and its widgets with their resolved data.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-5
 */
final class ReportContent {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                               $dashboard       Dashboard payload.
	 * @param array<int, array{widget: array, data: array|null}> $resolvedWidgets Widget+data tuples.
	 */
	public function __construct(
		public readonly array $dashboard,
		public readonly array $resolvedWidgets,
	) {
	}//end __construct()
}//end class
