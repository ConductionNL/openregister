<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\BulkJobsController;

/**
 * Builds BulkJobsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class BulkJobsControllerFactory {
	/**
	 * The constructor arguments of BulkJobsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param BulkJobService $service Constructor argument.
	 * @param BulkJobReversal $reversal Constructor argument.
	 * @param BulkJobMapper $jobMapper Constructor argument.
	 * @param BulkActionRegistry $registry Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\BulkJob\BulkJobService $service,
		\OCA\OpenRegister\Service\BulkJob\BulkJobReversal $reversal,
		\OCA\OpenRegister\Db\BulkJobMapper $jobMapper,
		\OCA\OpenRegister\Service\BulkActionRegistry $registry,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\BulkJobsControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\BulkJobsDomain(
					jobMapper: $jobMapper,
					service: $service,
					reversal: $reversal,
					registry: $registry,
				),
				platform: new \OCA\OpenRegister\Controller\Support\AuditTrailPlatform(
					userSession: $userSession,
					groupManager: $groupManager,
				),
			),
		];
	}

	/**
	 * Build BulkJobsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return BulkJobsController
	 */
	public static function create(mixed ...$args): BulkJobsController {
		return new BulkJobsController(...self::args(...$args));
	}
}
