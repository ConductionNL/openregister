<?php

/**
 * One DestructionCheckJob run: its logger, archival settings and retention service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Service\RetentionServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * The values the steps of one destruction check share: where it logs, the
 * archival settings it read at the start, and the retention service it asks.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class DestructionCheckRun {

	/**
	 * The run's logger.
	 *
	 * @var LoggerInterface
	 */
	public LoggerInterface $logger;

	/**
	 * The retention service the run asks for eligible objects and the list.
	 *
	 * @var RetentionServiceInterface
	 */
	public RetentionServiceInterface $retention;

	/**
	 * The archival settings, read once at the start of the run.
	 *
	 * @var array<string, mixed>
	 */
	public array $settings = [];
}//end class
