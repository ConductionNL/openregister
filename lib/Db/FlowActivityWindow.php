<?php

/**
 * Which flows a per-flow run summary covers and from which moment it counts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-run-summary/specs/flow-run-summary/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * The flows a per-flow run summary covers and the moment it counts from.
 *
 * The "runs this week" half of a flow list counts the runs created at or
 * after `since`; the "last run" half reads the newest run of the same flows.
 *
 * @spec openspec/changes/flow-run-summary/specs/flow-run-summary/spec.md
 */
final readonly class FlowActivityWindow {
	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $flowIds The flows to summarise.
	 * @param DateTime          $since   Count runs created at or after this.
	 *
	 * @return void
	 */
	public function __construct(
		public array $flowIds,
		public DateTime $since,
	) {
	}//end __construct()
}//end class
