<?php

/**
 * OpenRegister time-tracker reconcile command
 *
 * Re-fetches the upstream NC TimeManager metadata (name, duration,
 * billable, started_at) for every persisted time-tracker link row and
 * rewrites the denormalised cache when it drifted from the
 * authoritative source. Used after upstream edits in NC TimeManager
 * (or any other backing time-tracking app, see AD-1) so per-object
 * totals — which `CnTimeCard` renders from the denormalised duration
 * column (per the integration-time-tracker spec's "denormalized object
 * total" requirement) — stay correct without waiting for the on-read
 * drift-guard window.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-time-tracker/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\TimeTrackerLinkService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reconcile every persisted time-tracker link row against its upstream
 * NC TimeManager entry — refreshes the denormalised name / duration /
 * billable / started_at cache so dashboards can read per-object totals
 * from the link table without aggregating across N entries at render
 * time (per the spec's "Denormalized Object Total" requirement).
 */
class TimeReconcileCommand extends Command {
	/**
	 * Wire the time-tracker link service used by the command.
	 *
	 * @param TimeTrackerLinkService $service Tier-2 link-service facade.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TimeTrackerLinkService $service,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-time-tracker/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:time:reconcile')
			->setDescription(
				'Reconcile denormalised time-tracker link metadata (name, duration, billable, started_at) '
				. 'against the authoritative NC TimeManager source so per-object totals stay correct.'
			)
			->addOption(
				'object',
				null,
				InputOption::VALUE_REQUIRED,
				'Optionally restrict the scan to a single object uuid; default is every link row.'
			)
			->addOption(
				'dry-run',
				null,
				InputOption::VALUE_NONE,
				'Report drift without writing the link table.'
			);
	}//end configure()

	/**
	 * Run the reconcile walk and report the summary stats.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/integration-time-tracker/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$objectUuid = $input->getOption('object');
		if (is_string($objectUuid) === false || $objectUuid === '') {
			$objectUuid = null;
		}

		$dryRun = (bool)$input->getOption('dry-run');
		$dryRunLabel = '';
		if ($dryRun === true) {
			$dryRunLabel = ' (dry run)';
		}

		$scope = 'all link rows';
		if ($objectUuid !== null) {
			$scope = 'object=' . $objectUuid;
		}

		$output->writeln(sprintf('<info>Reconciling %s%s</info>', $scope, $dryRunLabel));

		$stats = $this->service->reconcileAllLinks(objectUuid: $objectUuid, dryRun: $dryRun);

		$output->writeln(
			sprintf(
				' walked=%d refreshed=%d missing=%d errors=%d',
				$stats['walked'],
				$stats['refreshed'],
				$stats['missing'],
				$stats['errors']
			)
		);

		if ($stats['errors'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()
}//end class
