<?php

/**
 * Give existing runs the one subject they can honestly be said to have declared.
 *
 * 🔴 THE `trigger` ENTRY, FROM `subject_uuid`, AND NOTHING ELSE. That column
 * records what the run actually fired on, so calling it the run's `trigger`
 * subject states a fact the run already carried.
 *
 * 🔴 NOTHING IS BACK-FILLED FROM THE AUDIT. It would be easy to walk what each
 * run wrote and invent roles for it — and those declarations would then be
 * ADDRESSABLE by `attachTo`, so a later step could attach a task to an object
 * no author ever declared a subject. A wrong attachment is worse than a missing
 * one: the missing one fails loudly the first time somebody uses it.
 *
 * 🔴 IT MUST NOT FAIL AN UPGRADE. A run whose row cannot be read is reported
 * and skipped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-run-subjects/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunListing;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunViewer;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectsInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the `trigger` subject on runs that fired on an object.
 */
class SeedFlowRunTriggerSubject implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * Resolved lazily from the container, like the other flow repair steps:
	 * this runs during install, when the tables may not exist yet.
	 *
	 * @param ContainerInterface       $container The app container.
	 * @param LoggerInterface          $logger    Diagnostics.
	 * @param FlowRunSubjectsInterface $subjects  The subject rules.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly FlowRunSubjectsInterface $subjects,
	) {

	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function getName(): string {
		return 'Record what existing runs fired on as their trigger subject';
	}//end getName()

	/**
	 * Seed it.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$runs = $this->container->get(FlowRunMapperInterface::class);
		} catch (Throwable $e) {
			$output->info('Flow run trigger-subject seeding skipped: ' . $e->getMessage());
			return;
		}

		$tally = $this->seedAll(runs: $runs);

		$output->info(
			sprintf('Recorded a trigger subject on %d run(s); %d could not be read.', $tally['seeded'], $tally['skipped'])
		);

	}//end run()

	/**
	 * Seed every run, counting the runs seeded and the runs that could not be read.
	 *
	 * @param FlowRunMapperInterface $runs The mapper.
	 *
	 * @return array{seeded: int, skipped: int} The counts.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	private function seedAll(FlowRunMapperInterface $runs): array {
		$tally = ['seeded' => 0, 'skipped' => 0];

		foreach ($this->everyRun(runs: $runs) as $run) {
			$outcome = $this->trySeed(run: $run, runs: $runs);
			if ($outcome === null) {
				$tally['skipped']++;
				continue;
			}

			$tally['seeded'] += $outcome;
		}

		return $tally;

	}//end seedAll()

	/**
	 * Seed one run, or log why it could not be read.
	 *
	 * @param FlowRun                $run  The run.
	 * @param FlowRunMapperInterface $runs The mapper.
	 *
	 * @return int|null 1 when it was seeded, 0 when it needed nothing, null when it failed.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	private function trySeed(FlowRun $run, FlowRunMapperInterface $runs): ?int {
		try {
			return $this->seedOne(run: $run, runs: $runs);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SeedFlowRunTriggerSubject] Could not seed run "'
					. (string)$run->getUuid() . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

	}//end trySeed()

	/**
	 * Seed one run, if it has something to seed and nothing already.
	 *
	 * @param FlowRun                $run  The run.
	 * @param FlowRunMapperInterface $runs The mapper.
	 *
	 * @return int 1 when it was seeded, 0 otherwise.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	private function seedOne(FlowRun $run, FlowRunMapperInterface $runs): int {
		if ($this->subjects->all(run: $run) !== []) {
			// Already declared something. A repair must not overwrite what a
			// flow author said.
			return 0;
		}

		$uuid = trim((string)$run->getSubjectUuid());
		if ($uuid === '') {
			return 0;
		}

		$this->subjects->recordTrigger(run: $run);
		$runs->update($run);

		return 1;

	}//end seedOne()

	/**
	 * Every run, paged.
	 *
	 * 🔴 A MAPPER'S DEFAULT LIMIT WOULD SILENTLY SEED THE FIRST PAGE and report
	 * success — the shape that left 119 of 219 flows unversioned once already.
	 *
	 * @param FlowRunMapperInterface $runs The mapper.
	 *
	 * @return array<int, FlowRun> Every run.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	private function everyRun(FlowRunMapperInterface $runs): array {
		$page = 500;
		$offset = 0;
		$all = [];

		while (true) {
			// 🔴 `findAllRuns()`, NOT `findAll()`. The latter does not exist;
			// writing it once already produced a repair that threw, reported
			// itself skipped, and let the upgrade go green having done nothing.
			$batch = $runs->findAllRuns(
				listing: new FlowRunListing(limit: $page, offset: $offset),
				viewer: new FlowRunViewer(requesterUid: null, ownedFlowIds: [])
			);
			$all = array_merge($all, $batch);

			if (count($batch) < $page) {
				return $all;
			}

			$offset += $page;
		}

	}//end everyRun()
}//end class
