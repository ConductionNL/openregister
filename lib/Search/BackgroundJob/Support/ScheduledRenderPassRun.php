<?php

/**
 * One scheduled-render pass over the dashboards of the reports register.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Search\BackgroundJob\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\BackgroundJob\Support;

use DateTime;

/**
 * The reference time of a ReportRenderJob pass and how many dashboards it
 * rendered and skipped so far.
 *
 * Built by ReportRenderJob for each pass and never handed outside it.
 *
 * @spec openspec/specs/rapportage-bi-export/spec.md
 */
final class ScheduledRenderPassRun {

	/**
	 * Dashboards rendered and delivered in this pass.
	 *
	 * @var integer
	 */
	public int $rendered = 0;

	/**
	 * Dashboards that were not due in this pass.
	 *
	 * @var integer
	 */
	public int $skipped = 0;

	/**
	 * Constructor.
	 *
	 * @param DateTime $now The reference time every dashboard is checked against.
	 *
	 * @spec openspec/specs/rapportage-bi-export/spec.md
	 */
	public function __construct(
		public readonly DateTime $now,
	) {
	}//end __construct()
}//end class
