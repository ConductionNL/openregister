<?php

/**
 * OpenRegister Transfer Check Job
 *
 * Background job that scans for objects eligible for e-Depot transfer
 * and generates transfer lists for archivist review.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Cron
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Transfer Check Job
 *
 * Periodically scans for objects with archiefnominatie=bewaren that have reached
 * their archiefactiedatum, and generates transfer lists for archivist approval.
 *
 * @category Cron
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @psalm-suppress UnusedClass
 */
class TransferCheckJob extends TimedJob {

	/**
	 * Default interval: 24 hours (86400 seconds).
	 */
	private const DEFAULT_INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param RetentionService $retentionService Owns the retention sweep both archival jobs share.
	 * @param TransferListService $transferListService The transfer list service.
	 * @param TransferRecordService $transferRecords Durable transfer-list records.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly RetentionService $retentionService,
		private readonly TransferListService $transferListService,
		private readonly TransferRecordService $transferRecords,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$interval = (int)$this->appConfig->getValueString(
			'openregister',
			'edepot_check_interval',
			(string)self::DEFAULT_INTERVAL
		);
		$this->setInterval(seconds: $interval);
	}//end __construct()

	/**
	 * Run the transfer check.
	 *
	 * @param mixed $argument Job arguments (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	protected function run(mixed $argument): void {
		if ($this->isEdepotConfigured() === false) {
			$this->logger->debug(
				message: '[TransferCheckJob] No e-Depot configured, skipping'
			);
			return;
		}

		$this->logger->info(
			message: '[TransferCheckJob] Starting transfer eligibility scan'
		);

		try {
			$eligibleObjects = $this->findEligibleObjects();

			if (empty($eligibleObjects) === true) {
				$this->logger->info(
					message: '[TransferCheckJob] No objects eligible for transfer'
				);
				return;
			}

			$transferList = $this->transferListService->createTransferList($eligibleObjects);
			$this->transferListService->notifyArchivists($transferList);

			$this->logger->info(
				message: '[TransferCheckJob] Transfer list created',
				context: [
					'uuid' => $transferList['uuid'],
					'objectCount' => count($eligibleObjects),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[TransferCheckJob] Error during transfer check',
				context: ['error' => $e->getMessage()]
			);
		}//end try
	}//end run()

	/**
	 * Check if e-Depot is configured.
	 *
	 * @return bool True if e-Depot endpoint is configured.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	private function isEdepotConfigured(): bool {
		$endpointUrl = $this->appConfig->getValueString('openregister', 'edepot_endpoint_url', '');
		return (empty($endpointUrl) === false);
	}//end isEdepotConfigured()

	/**
	 * Find objects eligible for e-Depot transfer.
	 *
	 * Objects are eligible when:
	 * - the appraisal is retain-permanently (`bewaren` / `blijvend_bewaren`)
	 * - archiefactiedatum <= today
	 * - the record state is not already transferred or destroyed
	 * - there is no active legal hold
	 * - Not already on an active transfer list
	 *
	 * 🔴 THIS USED TO RETURN `[]` UNCONDITIONALLY, behind a comment saying a
	 * real implementation would query JSON field conditions. It ran daily on
	 * every install with an e-Depot configured and produced no transfer list,
	 * ever. The decision itself now lives in
	 * {@see RetentionService::findEligibleForTransfer()}, beside the destruction
	 * sweep it shares a scanner with.
	 *
	 * @return array<int, \OCA\OpenRegister\Db\ObjectEntity> Eligible objects.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function findEligibleObjects(): array {
		// Objects already awaiting or approved for transfer are excluded the way
		// the destruction sweep excludes objects on a pending destruction list,
		// so a second run does not re-list what an archivist is already holding.
		$excludeUuids = $this->transferListService->getObjectsOnActiveTransferLists(
			activeTransferLists: $this->transferRecords->listTransferLists()
		);

		$eligible = $this->retentionService->findEligibleForTransfer($excludeUuids);

		$this->logger->debug(
			message: '[TransferCheckJob] Scanned for eligible objects',
			context: ['eligibleCount' => count($eligible), 'excludedCount' => count($excludeUuids)]
		);

		return $eligible;
	}//end findEligibleObjects()
}//end class
