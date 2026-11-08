<?php

/**
 * MovedJobStateInterface: what callers outside the Service component ask of MovedJobState.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

/**
 * Carrying the per-job-class state to a moved job class, as the repair step
 * that repoints moved background jobs uses it. Only the method it calls.
 */
interface MovedJobStateInterface {

	/**
	 * Move the schedule and the run history from the old class name to the new one.
	 *
	 * @param string $from The old class name.
	 * @param string $to   The new class name.
	 *
	 * @return void
	 *
	 * @spec exclude Upgrade plumbing for moved job classes; no capability spec covers it.
	 */
	public function carry(string $from, string $to): void;
}//end interface
