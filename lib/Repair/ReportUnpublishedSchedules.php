<?php

/**
 * Logs the schedules an on-disk app ships that nobody has published.
 *
 * A manifest in an app's own `src/manifest.json` has no publisher, so its
 * schedules do not run. Saying so at upgrade keeps that refusal visible.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStoreInterface;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifest;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoaderInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Reports each on-disk schedule with no compiled flow as refused.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
class ReportUnpublishedSchedules implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param ScheduleManifestLoaderInterface $manifests The on-disk manifests.
	 * @param ScheduleFlowStoreInterface $flows The compiled flows.
	 * @param LoggerInterface $logger Refusals.
	 */
	public function __construct(
		private readonly ScheduleManifestLoaderInterface $manifests,
		private readonly ScheduleFlowStoreInterface $flows,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function getName(): string {
		return 'Report schedules in app manifests that nobody published';
	}//end getName()

	/**
	 * Log every on-disk schedule without a publisher.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function run(IOutput $output): void {
		foreach ($this->manifests->loadAllOnDisk() as $manifest) {
			if ($this->flows->hasFlowsNamedFor(slug: $manifest->applicationId) === false) {
				$this->refuse(manifest: $manifest, output: $output);
			}
		}
	}//end run()

	/**
	 * Log one app's schedules as refused.
	 *
	 * @param ScheduleManifest $manifest The app's schedules.
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 */
	private function refuse(ScheduleManifest $manifest, IOutput $output): void {
		foreach ($manifest->schedules as $schedule) {
			$message = sprintf(
				'Schedule "%s" of app "%s" refused: nobody published it, so it has no identity to run as.',
				$schedule->id,
				$manifest->applicationId
			);
			$this->logger->warning(message: '[AppHost\\Scheduling] ' . $message);
			$output->warning($message);
		}
	}//end refuse()
}//end class
