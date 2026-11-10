<?php

/**
 * The run-lifecycle collaborators of the flow run worker.
 *
 * @category Cron
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancerInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * What every pass of the worker needs: the runs, the advancer that walks
 * them, the app config that tunes the passes and the logger that reports them.
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */
final class FlowRunWorkerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowRunMapperInterface   $mapper    Run persistence.
	 * @param FlowRunAdvancerInterface $advancer  Walks a run.
	 * @param IAppConfig               $appConfig Pass tuning.
	 * @param LoggerInterface          $logger    Reports the passes.
	 */
	public function __construct(
		public readonly FlowRunMapperInterface $mapper,
		public readonly FlowRunAdvancerInterface $advancer,
		public readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
