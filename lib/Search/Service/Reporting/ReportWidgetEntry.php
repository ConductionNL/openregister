<?php

/**
 * OpenRegister ReportWidgetEntry
 *
 * One resolved widget for the spreadsheet writer: its index, descriptor and data.
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
 * One resolved widget for the spreadsheet writer: its index, descriptor and data.
 */
final class ReportWidgetEntry {
	/**
	 * Constructor.
	 *
	 * @param int                       $index  Widget index (drives sheet name + position).
	 * @param array<string, mixed>      $widget Widget descriptor.
	 * @param array<string, mixed>|null $data   Resolved widget data, or null when it did not resolve.
	 */
	public function __construct(
		public readonly int $index,
		public readonly array $widget,
		public readonly ?array $data,
	) {
	}//end __construct()
}//end class
