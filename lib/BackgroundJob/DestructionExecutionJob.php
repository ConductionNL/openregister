<?php

/**
 * OpenRegister Destruction Execution Background Job
 *
 * Queued background job that processes approved destruction lists by permanently
 * deleting objects in configurable batches, generating audit trails and
 * destruction certificates upon completion.
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
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use Exception;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Queued job for executing approved destruction lists.
 *
 * Processes destruction in batches, respects legal holds at execution time,
 * and generates destruction certificates.
 *
 */
class DestructionExecutionJob extends QueuedJob implements DestructionExecutionJobInterface {

	/**
	 * Default batch size for destruction processing.
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for parent class
	 * @param ContainerInterface $container App container the job resolves its collaborators from at run time
	 */
	public function __construct(ITimeFactory $time, private readonly ContainerInterface $container) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Execute the destruction of an approved destruction list.
	 *
	 * @param mixed $argument Job arguments containing 'destructionListUuid'
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	protected function run($argument): void {
		$logger = $this->container->get(LoggerInterface::class);

		$listUuid = $argument['destructionListUuid'] ?? null;
		if ($listUuid === null) {
			$logger->error('[DestructionExecutionJob] No destructionListUuid provided');
			return;
		}

		$logger->info('[DestructionExecutionJob] Processing destruction list: ' . $listUuid);

		try {
			(new DestructionListRun(container: $this->container, logger: $logger))->execute(listUuid: (string)$listUuid);
		} catch (Exception $e) {
			$logger->error('[DestructionExecutionJob] Fatal: '.$e->getMessage(), ['exception' => $e]);
		}
	}//end run()
}//end class
