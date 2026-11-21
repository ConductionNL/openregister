<?php

/**
 * OpenRegister Bulk Legal Hold Background Job
 *
 * Queued background job that places legal holds on all objects in a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use Exception;
use OCA\OpenRegister\Service\HoldOrder;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Queued job for placing legal holds on all objects in a schema.
 */
class BulkLegalHoldJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory
	 * @param ContainerInterface $container App container the job resolves its collaborators from at run time
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(ITimeFactory $time, private readonly ContainerInterface $container) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Execute the bulk legal hold operation.
	 *
	 * @param mixed $argument Job arguments with schemaId, reason, userId
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	protected function run($argument): void {
		$logger = $this->container->get(LoggerInterface::class);

		$schemaId = $argument['schemaId'] ?? null;
		if ($schemaId === null) {
			$logger->error('[BulkLegalHoldJob] No schemaId provided');
			return;
		}

		$logger->info('[BulkLegalHoldJob] Placing legal holds on schema: ' . $schemaId);

		try {
			self::reportPlaced(logger: $logger, count: $this->placeHolds(schemaId: (int)$schemaId, reason: ($argument['reason'] ?? '')));
		} catch (Exception $e) {
			$logger->error('[BulkLegalHoldJob] Error: ' . $e->getMessage(), ['exception' => $e]);
		}//end try
	}//end run()

	/**
	 * Place a legal hold on every object of a schema.
	 *
	 * @param int   $schemaId The schema whose objects are held.
	 * @param mixed $reason   The hold's reason, as the job was given it.
	 *
	 * @return int How many objects were held.
	 */
	private function placeHolds(int $schemaId, mixed $reason): int {
		$retentionService = $this->container->get(RetentionServiceInterface::class);
		$objectMapper     = $this->container->get(MagicMapperInterface::class);

		$objects = $objectMapper->findBySchema(schemaId: $schemaId);

		$count = 0;
		foreach ($objects as $object) {
			$retentionService->placeHold($object, new HoldOrder(reason: $reason));
			$objectMapper->update($object);
			$count++;
		}

		return $count;
	}//end placeHolds()

	/**
	 * Log how many holds were placed, when there were any.
	 *
	 * @param LoggerInterface $logger The job's logger.
	 * @param int             $count  How many objects were held.
	 *
	 * @return void
	 */
	private static function reportPlaced(LoggerInterface $logger, int $count): void {
		if ($count > 0) {
			$logger->info('[BulkLegalHoldJob] Placed legal holds on ' . $count . ' objects');
		}
	}//end reportPlaced()
}//end class
