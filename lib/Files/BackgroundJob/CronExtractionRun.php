<?php

/**
 * One tick of the cron file text extraction job.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Files\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\BackgroundJob;

use Psr\Log\LoggerInterface;

/**
 * The logger a tick reports to and when it started.
 *
 * Built by {@see CronFileTextExtractionJob::run()} and never leaves that class.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class CronExtractionRun {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger    The logger.
	 * @param float           $startTime When the run started (microtime).
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly float $startTime,
	) {
	}//end __construct()
}//end class
