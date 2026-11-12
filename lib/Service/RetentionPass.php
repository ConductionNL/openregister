<?php

/**
 * One AVG retention pass: when it runs and whether it may erase
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use DateTime;

/**
 * The reference time every activity's cut-off is computed from, and whether
 * the pass only reports (dry run) or also soft-deletes.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class RetentionPass {
	/**
	 * Hold the pass settings.
	 *
	 * @param DateTime $now    Reference timestamp.
	 * @param bool     $dryRun True when the pass reports without erasing.
	 */
	public function __construct(
		public readonly DateTime $now,
		public readonly bool $dryRun,
	) {
	}//end __construct()
}//end class
