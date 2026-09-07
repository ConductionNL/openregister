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
 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunSubjects;
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
	 * @param ContainerInterface $container The app container.
	 * @param LoggerInterface    $logger    Diagnostics.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
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
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$runs = $this->container->get(FlowRunMapper::class);
			$subjects = new FlowRunSubjects(logger: $this->logger);
		} catch (Throwable $e) {
			$output->info('Flow run trigger-subject seeding skipped: ' . $e->getMessage());
			return;
		}

		$seeded = 0;
		$skipped = 0;

		foreach ($this->everyRun(runs: $runs) as $run) {
			try {
				$seeded += $this->seedOne(run: $run, runs: $runs, subjects: $subjects);
			} catch (Throwable $e) {
				$skipped++;
				$this->logger->warning(
					message: '[SeedFlowRunTriggerSubject] Could not seed run "'
						. (string)$run->getUuid() . '": ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		$output->info(
			sprintf('Recorded a trigger subject on %d run(s); %d could not be read.', $seeded, $skipped)
		);

	}//end run()

	/**
	 * Seed one run, if it has something to seed and nothing already.
	 *
	 * @param FlowRun         $run      The run.
	 * @param FlowRunMapper   $runs     The mapper.
	 * @param FlowRunSubjects $subjects The subject rules.
	 *
	 * @return int 1 when it was seeded, 0 otherwise.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	private function seedOne(FlowRun $run, FlowRunMapper $runs, FlowRunSubjects $subjects): int {
		if ($subjects->all(run: $run) !== []) {
			// Already declared something. A repair must not overwrite what a
			// flow author said.
			return 0;
		}

		$uuid = trim((string)$run->getSubjectUuid());
		if ($uuid === '') {
			return 0;
		}

		$subjects->record(
			run: $run,
			role: FlowRunSubjects::ROLE_TRIGGER,
			uuid: $uuid,
			register: trim((string)$run->getSubjectRegister()),
			schema: trim((string)$run->getSubjectSchema())
		);
		$runs->update($run);

		return 1;

	}//end seedOne()

	/**
	 * Every run, paged.
	 *
	 * 🔴 A MAPPER'S DEFAULT LIMIT WOULD SILENTLY SEED THE FIRST PAGE and report
	 * success — the shape that left 119 of 219 flows unversioned once already.
	 *
	 * @param FlowRunMapper $runs The mapper.
	 *
	 * @return array<int, FlowRun> Every run.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	private function everyRun(FlowRunMapper $runs): array {
		$page = 500;
		$offset = 0;
		$all = [];

		while (true) {
			// 🔴 `findAllRuns()`, NOT `findAll()`. The latter does not exist;
			// writing it once already produced a repair that threw, reported
			// itself skipped, and let the upgrade go green having done nothing.
			$batch = $runs->findAllRuns(limit: $page, offset: $offset);
			$all = array_merge($all, $batch);

			if (count($batch) < $page) {
				return $all;
			}

			$offset += $page;
		}

	}//end everyRun()
}//end class
