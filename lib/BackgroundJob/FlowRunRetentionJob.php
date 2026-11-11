<?php

/**
 * Prunes flow-run history.
 *
 * Runs once daily. Reads the retention period from IAppConfig
 * (key: `flow_run_retention_days`, default: 31) and lets any flow override it
 * in EITHER direction — a noisy, high-frequency flow may keep less than the
 * instance default, an audited one more.
 *
 * The sweep is two-pass on purpose. One pass applies the instance cutoff to
 * every flow that does NOT declare an override; a second applies each
 * overriding flow's own cutoff by id. Doing it the other way round — filter in
 * PHP — would mean loading every expired run just to decide which to keep.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\Support\FlowRunRetentionServices;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunStepMapperInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes expired flow runs and their step rows.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
class FlowRunRetentionJob extends TimedJob {
	/**
	 * The app the setting lives under.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * App-config key holding the instance-wide retention period, in days.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'flow_run_retention_days';

	/**
	 * Default retention period in days.
	 *
	 * @var integer
	 */
	public const DEFAULT_RETENTION_DAYS = 31;

	/**
	 * Deletes expired runs.
	 *
	 * @var FlowRunMapperInterface
	 */
	private readonly FlowRunMapperInterface $runs;

	/**
	 * Deletes those runs' step rows.
	 *
	 * @var FlowRunStepMapperInterface
	 */
	private readonly FlowRunStepMapperInterface $steps;

	/**
	 * Finds the flows declaring an override.
	 *
	 * @var FlowMapperInterface
	 */
	private readonly FlowMapperInterface $flows;

	/**
	 * Reads the retention period.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $config;

	/**
	 * Records what was swept.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time     The clock.
	 * @param FlowRunRetentionServices $services The run history, the retention setting and the log.
	 */
	public function __construct(ITimeFactory $time, FlowRunRetentionServices $services) {
		parent::__construct(time: $time);
		$this->runs = $services->stores->runs;
		$this->steps = $services->stores->steps;
		$this->flows = $services->stores->flows;
		$this->config = $services->config;
		$this->logger = $services->logger;

		$this->setInterval(seconds: 86400);

	}//end __construct()

	/**
	 * The instance-wide retention period, in days.
	 *
	 * A non-positive configured value falls back to the default rather than
	 * being honoured: zero or negative would mean "delete everything, now",
	 * which is never what a mistyped setting is asking for.
	 *
	 * @return integer The retention period.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	private function instanceRetentionDays(): int {
		$days = (int)$this->config->getValueString(
			self::APP_ID,
			self::CONFIG_KEY,
			(string)self::DEFAULT_RETENTION_DAYS
		);

		if ($days <= 0) {
			return self::DEFAULT_RETENTION_DAYS;
		}

		return $days;
	}//end instanceRetentionDays()

	/**
	 * Sweep expired runs and their steps.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	protected function run($argument): void {
		$defaultDays = $this->instanceRetentionDays();

		$overriding = $this->overridingFlows();
		if ($overriding === null) {
			return;
		}

		$overrideIds = self::flowIds(flows: $overriding);

		// Pass 1 — everything that follows the instance default.
		$swept = $this->sweepInstance(defaultDays: $defaultDays, excludeFlowIds: $overrideIds);
		// Pass 2 — each flow that declares its own period, shorter or longer.
		$swept += $this->sweepOverrides(overriding: $overriding, defaultDays: $defaultDays);

		if ($swept > 0) {
			$this->logger->info(
				message: '[FlowRunRetention] Swept ' . $swept . ' expired flow run(s).',
				context: ['retentionDays' => $defaultDays, 'overrides' => count($overrideIds)]
			);
		}
	}//end run()

	/**
	 * The flows that declare their own retention period; null, logged, when they cannot be listed.
	 *
	 * @return Flow[]|null
	 */
	private function overridingFlows(): ?array {
		try {
			return $this->flows->findWithRetentionOverride();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowRunRetention] Could not list flows with a retention override: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end overridingFlows()

	/**
	 * The non-empty uuids of some flows.
	 *
	 * @param Flow[] $flows The flows.
	 *
	 * @return string[]
	 */
	private static function flowIds(array $flows): array {
		return array_values(
			array_filter(
				array_map(static fn (Flow $f): string => (string)$f->getUuid(), $flows)
			)
		);
	}//end flowIds()

	/**
	 * Sweep the terminal runs of every flow that follows the instance default.
	 *
	 * @param int      $defaultDays    The instance retention period.
	 * @param string[] $excludeFlowIds The flows that declare their own period.
	 *
	 * @return int How many runs were swept; 0, logged, when the sweep fails.
	 */
	private function sweepInstance(int $defaultDays, array $excludeFlowIds): int {
		try {
			$uuids = $this->runs->deleteTerminalOlderThanExcluding(
				cutoff: new DateTime('-' . $defaultDays . ' days'),
				excludeFlowIds: $excludeFlowIds
			);
			return $this->purgeSteps(uuids: $uuids);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowRunRetention] Instance-wide sweep failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return 0;
		}
	}//end sweepInstance()

	/**
	 * Sweep each flow that declares its own period.
	 *
	 * @param Flow[] $overriding  The flows that declare their own period.
	 * @param int    $defaultDays The instance retention period.
	 *
	 * @return int How many runs were swept.
	 */
	private function sweepOverrides(array $overriding, int $defaultDays): int {
		$swept = 0;
		foreach ($overriding as $flow) {
			$swept += $this->sweepFlow(flow: $flow, defaultDays: $defaultDays);
		}

		return $swept;
	}//end sweepOverrides()

	/**
	 * Sweep the terminal runs of one flow by its own retention period.
	 *
	 * @param Flow $flow        The flow.
	 * @param int  $defaultDays The instance retention period.
	 *
	 * @return int How many runs were swept; 0, logged, when the sweep fails.
	 */
	private function sweepFlow(Flow $flow, int $defaultDays): int {
		$days = $flow->effectiveRetentionDays(defaultDays: $defaultDays);

		try {
			$uuids = $this->runs->deleteTerminalOlderThan(
				cutoff: new DateTime('-' . $days . ' days'),
				flowId: (string)$flow->getUuid()
			);
			return $this->purgeSteps(uuids: $uuids);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowRunRetention] Sweep failed for flow ' . $flow->getUuid() . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return 0;
		}
	}//end sweepFlow()

	/**
	 * Delete the step rows belonging to a set of removed runs.
	 *
	 * Steps are removed AFTER their run, so a sweep interrupted between the two
	 * leaves orphaned steps rather than a run whose history has silently
	 * vanished — the next pass has no run to re-delete, so the orphans are
	 * cleaned by their own `created` cutoff instead.
	 *
	 * @param array<int, string> $uuids The removed runs' uuids.
	 *
	 * @return integer The number of runs whose steps were purged.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	private function purgeSteps(array $uuids): int {
		foreach ($uuids as $uuid) {
			try {
				$this->steps->deleteByRun(runUuid: $uuid);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[FlowRunRetention] Could not purge steps for run ' . $uuid . ': ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		return count($uuids);
	}//end purgeSteps()
}//end class
