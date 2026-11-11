<?php

/**
 * OpenRegister distinct-key budget
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * A rolling budget of distinct keys for one bucket: at most `maxKeys` distinct values per `windowSeconds`.
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-19
 */
final class DistinctKeyBudget {
	/**
	 * Hold the budget.
	 *
	 * @param string $bucketKey     Caller-namespaced bucket key (e.g. `getmiss:<uid>`).
	 * @param int    $maxKeys       Maximum distinct values permitted within the window.
	 * @param int    $windowSeconds Rolling window length.
	 */
	public function __construct(
		public readonly string $bucketKey,
		public readonly int $maxKeys,
		public readonly int $windowSeconds,
	) {
	}//end __construct()
}//end class
