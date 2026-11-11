<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\TransferController;

/**
 * Builds TransferController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TransferControllerFactory {
	/**
	 * The constructor arguments of TransferController.
	 *
	 * @param mixed $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param TransferRecordService $transferRecordService Constructor argument.
	 * @param IJobList $jobList Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param TransferListService $transferListService Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		mixed $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Edepot\TransferRecordService $transferRecordService,
		\OCP\BackgroundJob\IJobList $jobList,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\Edepot\TransferListService $transferListService,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\TransferControllerServices(
				transferRecords: $transferRecordService,
				jobList: $jobList,
				logger: $logger,
				transferListService: $transferListService,
			),
			$userSession,
		];
	}

	/**
	 * Build TransferController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TransferController
	 */
	public static function create(mixed ...$args): TransferController {
		return new TransferController(...self::args(...$args));
	}
}
