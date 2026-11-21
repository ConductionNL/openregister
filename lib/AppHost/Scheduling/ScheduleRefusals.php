<?php

/**
 * OpenRegister AppHost: the schedule refusals of this request.
 *
 * A refusal in the log alone would let a publish succeed with no schedule and
 * nobody told, so each one is also kept for the response of the request that
 * caused it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

use OCA\OpenRegister\Exception\DatabaseErrorChain;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs a refused schedule and keeps it for the publish response.
 *
 * One instance per request: the container shares it.
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */
class ScheduleRefusals implements ScheduleRefusalReportInterface {
	/**
	 * This request's refusals.
	 *
	 * @var array<int, string>
	 */
	private array $refusals = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The log.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a refusal.
	 *
	 * @param ScheduleRelease $release The publish.
	 * @param string $reason Why.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-scheduling/spec.md
	 */
	public function refuse(ScheduleRelease $release, string $reason): void {
		$this->refusals[] = $reason;
		$this->logger->warning(
			message: '[AppHost\\Scheduling] Schedule refused: ' . $reason,
			context: ['application' => $release->applicationUuid(), 'publisher' => $release->publisher]
		);
	}//end refuse()

	/**
	 * Record a schedule whose store failed; a database error is reported in neutral words and logged.
	 *
	 * @param ScheduleRelease $release The publish.
	 * @param Throwable       $failure What the store threw.
	 *
	 * @return void
	 */
	public function refuseFailure(ScheduleRelease $release, Throwable $failure): void {
		if (DatabaseErrorChain::causeOf(failure: $failure) === null) {
			$this->refuse(release: $release, reason: $failure->getMessage());
			return;
		}

		$this->refusals[] = 'A schedule could not be stored.';
		$this->logger->error(
			message: '[AppHost\\Scheduling] Schedule could not be stored',
			context: ['application' => $release->applicationUuid(), 'publisher' => $release->publisher, 'exception' => $failure]
		);
	}//end refuseFailure()

	/**
	 * This request's refusals.
	 *
	 * @return array<int, string> The reasons, in order.
	 *
	 * @spec openspec/specs/apphost-scheduling/spec.md
	 */
	public function all(): array {
		return $this->refusals;
	}//end all()
}//end class
