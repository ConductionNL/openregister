<?php

/**
 * Publishes version 1 of every existing flow, and pins every in-flight run to it.
 *
 * 🔴 WITHOUT THIS STEP THE UPGRADE IS A FLEET OUTAGE. From the moment
 * versioning lands, `FlowRunService::queue()` refuses any flow with no
 * published version, and `FlowRunAdvancer` fails any run with no pin. Every
 * flow and every run that existed before the upgrade has neither. So this step
 * is not a convenience — it is the half of the change that keeps the other
 * half from stopping all work.
 *
 * 🔑 IDEMPOTENT BY QUERY, NOT BY FLAG. A flow that already has version rows is
 * skipped, and runs are pinned only where `flow_version IS NULL`. Running it
 * twice therefore matches nothing the second time, which is what `occ
 * maintenance:repair` and a re-run upgrade both need.
 *
 * Terminal runs keep their null pin on purpose. Telling a run that completed
 * last year that it executed "version 1" would be inventing history; the
 * question a terminal run answers is what it DID, and that is in its log.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapperInterface;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPinInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Gives every pre-versioning flow a published version 1, and pins its runs.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
class BackfillFlowVersions implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * Resolved lazily from the container, like the other flow repair steps:
	 * this runs during install and upgrade, when the flow tables may not exist
	 * yet, and a constructor-injected mapper would make that a fatal rather
	 * than a skip.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param LoggerInterface    $logger    Diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function getName(): string {
		return 'Publish version 1 of every existing flow and pin in-flight runs to it';
	}//end getName()

	/**
	 * Run the back-fill.
	 *
	 * Never throws. An upgrade that aborted here would leave the instance with
	 * the new refusals in place and no versions to satisfy them — strictly
	 * worse than reporting the failure and letting an operator re-run repair.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$flows = $this->everyFlow();
		} catch (Throwable $e) {
			$output->info('Flow version backfill skipped: ' . $e->getMessage());
			return;
		}

		if ($flows === []) {
			$output->info('Flow versions: no flows to publish.');
			return;
		}

		$this->publishEvery(flows: $flows, output: $output);

		// Sweep version rows whose flow is gone. `FlowService::delete()` now
		// cascades to them, so no NEW orphan can appear — but an instance that
		// deleted a flow between the version table landing and that cascade
		// landing kept the rows, unreachable through any read path because
		// every version read is keyed by flow.
		$this->sweepOrphanedVersions(output: $output);

	}//end run()

	/**
	 * Version every flow, one failure at a time, and print the counts.
	 *
	 * @param array<int, Flow> $flows  Every flow on the instance.
	 * @param IOutput          $output Migration output.
	 *
	 * @return void
	 */
	private function publishEvery(array $flows, IOutput $output): void {
		$tally = new FlowVersionBackfillTally();

		foreach ($flows as $flow) {
			$result = $this->backfillOrWarn(flow: $flow, output: $output);
			if ($result !== null) {
				$tally->record(result: $result);
			}
		}

		$output->info($tally->summary());

	}//end publishEvery()

	/**
	 * Version one flow, turning its failure into a log line and a warning.
	 *
	 * @param Flow    $flow   The flow.
	 * @param IOutput $output Migration output.
	 *
	 * @return array{published: boolean, pinned: integer}|null What was done, or null when it failed.
	 */
	private function backfillOrWarn(Flow $flow, IOutput $output): ?array {
		try {
			return $this->backfillOne(flow: $flow);
		} catch (Throwable $e) {
			// One flow's failure must not cost the other flows their
			// versions — an aborted loop here is the difference between
			// one unrunnable flow and an unrunnable instance.
			$this->logger->error(
				message: '[BackfillFlowVersions] Could not publish flow "' . $flow->getUuid()
					. '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flow->getUuid()]
			);
			$output->warning('Flow "' . $flow->getUuid() . '" could not be versioned: ' . $e->getMessage());
			return null;
		}

	}//end backfillOrWarn()

	/**
	 * Remove version rows whose flow no longer exists.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 */
	private function sweepOrphanedVersions(IOutput $output): void {
		try {
			$orphans = $this->container->get(FlowVersionMapperInterface::class)->deleteOrphaned();
			if ($orphans > 0) {
				$output->info(sprintf('Flow versions: removed %d orphaned version row(s).', $orphans));
			}
		} catch (Throwable $e) {
			$output->warning('Orphaned flow versions could not be swept: ' . $e->getMessage());
		}

	}//end sweepOrphanedVersions()

	/**
	 * Every flow, paged.
	 *
	 * 🔴 `findAllFlows()` DEFAULTS TO 100. Calling it bare versioned only the
	 * first page and reported success — measured on a dev instance: 219 flows,
	 * 100 versioned, 119 left with no published version and therefore
	 * unrunnable. That is exactly the outage this repair step exists to
	 * prevent, produced by the repair step itself.
	 *
	 * Paged rather than called with a huge limit: a limit large enough to be
	 * "obviously safe" today is a limit that silently truncates once an
	 * instance grows past it, and the failure would look identical.
	 *
	 * @return array<int, \OCA\OpenRegister\Db\Flow> Every flow on the instance.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function everyFlow(): array {
		$mapper = $this->container->get(FlowMapperInterface::class);
		$page = 500;
		$offset = 0;
		$all = [];

		while (true) {
			$batch = $mapper->findAllFlows(query: new ListQuery(limit: $page, offset: $offset));
			$all = array_merge($all, $batch);

			if (count($batch) < $page) {
				return $all;
			}

			$offset += $page;
		}

	}//end everyFlow()


	/**
	 * Version one flow and pin its in-flight runs.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array{published: boolean, pinned: integer} What was done.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function backfillOne(Flow $flow): array {
		$flowId = (string)$flow->getUuid();
		$versions = $this->container->get(FlowVersionMapperInterface::class);

		// Already versioned. Not "already run once" — the check is for any
		// version row at all, so a flow that was drafted but never published
		// is left as the draft its author made it.
		if ($versions->highestVersion(flowUuid: $flowId) > 0) {
			return ['published' => false, 'pinned' => 0];
		}

		$graph = [
			'nodes' => ($flow->getNodes() ?? []),
			'edges' => ($flow->getEdges() ?? []),
			'limits' => ($flow->getLimits() ?? []),
			'executionMode' => (string)($flow->getExecutionMode() ?? Flow::MODE_ASYNC),
		];

		$hash = $this->container->get(FlowDefinitionPinInterface::class)->pin(flow: $graph, flowId: $flowId);
		if ($hash === null) {
			throw new RuntimeException('the definition could not be stored');
		}

		$version = new FlowVersion();
		$version->setFlowUuid($flowId);
		$version->setVersion(1);
		// PUBLISHED, not draft. Every one of these flows was live before the
		// upgrade; delivering them as drafts would silently switch off every
		// automation on the instance, which is the outage this step exists to
		// prevent.
		$version->setStatus(FlowVersion::STATUS_PUBLISHED);
		$version->setDefinitionHash($hash);
		$version->setOwner($flow->getOwner());
		$version->setOrganisation($flow->getOrganisation());
		$version->setPublishedAt(new DateTime());
		$version->setCreated(new DateTime());
		$versions->insert($version);

		$flow->setVersion(1);
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
		$this->container->get(FlowMapperInterface::class)->update($flow);

		$pinned = $this->container->get(FlowRunMapperInterface::class)
			->pinUnversionedActive(flowUuid: $flowId, version: 1);

		return ['published' => true, 'pinned' => $pinned];

	}//end backfillOne()
}//end class
