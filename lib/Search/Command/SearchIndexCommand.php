<?php

/**
 * OpenRegister SearchIndexCommand
 *
 * Rebuild, snapshot and restore the magic-table search indexes from occ.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Command;

use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use OCA\OpenRegister\Search\Service\Search\SearchIndexPass;

/**
 * The search index under administration, from the command line.
 *
 * One command with three actions rather than three commands, because they share
 * the scope options and an administrator reaches for them in sequence: snapshot,
 * rebuild, and restore when a rebuild went wrong.
 *
 * Dry run by default, like `openregister:tables:reconcile`. Nothing here touches
 * an index until someone types `--apply`.
 */
class SearchIndexCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param SearchIndexMaintenance $maintenance The service that does the work.
	 */
	public function __construct(
		private readonly SearchIndexMaintenance $maintenance,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the name, the action and the options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:tables:search-index')
			->setDescription(
				'Rebuild, snapshot or restore the indexes behind object search. A rebuild builds '
				. 'each replacement beside the index in use and swaps when it is complete, so '
				. 'search keeps answering while it runs.'
			)
			->addArgument(
				'action',
				InputArgument::REQUIRED,
					'rebuild, snapshot, restore or status'
			);

		$this->addMaintenanceOptions();
	}//end configure()

	/**
	 * Declare the options: --apply, --register and --file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	private function addMaintenanceOptions(): void {
		$this->addOption(
			name: 'apply',
			shortcut: null,
			mode: InputOption::VALUE_NONE,
			description: 'Do the work. Without this flag rebuild and restore report what they would do and '
			. 'change nothing.'
		);

		$this->addScopeOptions();
	}//end addMaintenanceOptions()

	/**
	 * Declare the options that scope the work: --register and --file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	private function addScopeOptions(): void {
		$this->addOption(
			name: 'register',
			shortcut: null,
			mode: InputOption::VALUE_REQUIRED,
			description: 'Limit rebuild and snapshot to one register id.'
		)
			->addOption(
				name: 'file',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'The snapshot file to write or read.'
			);
	}//end addScopeOptions()

	/**
	 * Run the requested action.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 when nothing failed, 1 otherwise.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per action, plus the argument guard.
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$action = (string)$input->getArgument('action');
		$run = new IndexMaintenanceRun(input: $input, output: $output);

		try {
			return match ($action) {
				'rebuild' => $this->rebuild(run: $run),
				'snapshot' => $this->snapshot(run: $run),
				'restore' => $this->restore(run: $run),
				'status' => $this->status(output: $output),
				default => $this->unknownAction(output: $output, action: $action),
			};
		} catch (Throwable $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');
			return 1;
		}
	}//end execute()

	/**
	 * Rebuild the indexes, reporting each one as it goes.
	 *
	 * @param IndexMaintenanceRun $run The run: output, --register and --apply.
	 *
	 * @return int 0 when nothing failed.
	 */
	private function rebuild(IndexMaintenanceRun $run): int {
		$output = $run->output;
		$apply  = $run->apply();
		$report = $this->maintenance->rebuild(
			registerId: $run->registerId(),
			pass: new SearchIndexPass(apply: $apply, progress: $this->progressPrinter(output: $output))
		);

		if (($report['state'] ?? '') === 'refused') {
			$output->writeln('<error>' . ($report['reason'] ?? 'Refused.') . '</error>');
			return 1;
		}

		$this->writeRebuildSummary(output: $output, report: $report);

		if ($apply === false) {
			$output->writeln('<comment>Dry run. Nothing was rebuilt. Add --apply.</comment>');
			return 0;
		}

		return $this->rebuildOutcome(output: $output, report: $report);
	}//end rebuild()

	/**
	 * The progress callback that prints each index as the rebuild reaches it.
	 *
	 * @param OutputInterface $output The console output.
	 *
	 * @return callable(string, string, int, int): void
	 */
	private function progressPrinter(OutputInterface $output): callable {
		return static function (string $table, string $index, int $position, int $total) use ($output): void {
			$output->writeln("  [{$position}/{$total}] {$table} · {$index}");
		};
	}//end progressPrinter()

	/**
	 * Print the rebuild's table, index and rebuilt counts after a blank line.
	 *
	 * @param OutputInterface      $output The console output.
	 * @param array<string, mixed> $report The rebuild report.
	 *
	 * @return void
	 */
	private function writeRebuildSummary(OutputInterface $output, array $report): void {
		$output->writeln('');
		$output->writeln("Tables: {$report['tables']}, indexes: {$report['indexes']}, rebuilt: {$report['rebuilt']}");
	}//end writeRebuildSummary()

	/**
	 * Report an applied rebuild's failures: 1 when any index failed, else 0.
	 *
	 * @param OutputInterface      $output The console output.
	 * @param array<string, mixed> $report The rebuild report.
	 *
	 * @return int 0 when nothing failed.
	 */
	private function rebuildOutcome(OutputInterface $output, array $report): int {
		$this->writeFailures(output: $output, report: $report);

		if (($report['failed'] ?? 0) > 0) {
			$output->writeln(
				'<error>The indexes that failed are unchanged and still answering.</error>'
			);
			return 1;
		}

		return 0;
	}//end rebuildOutcome()

	/**
	 * Print one error line per failed index in a report.
	 *
	 * @param OutputInterface      $output The console output.
	 * @param array<string, mixed> $report The rebuild or restore report.
	 *
	 * @return void
	 */
	private function writeFailures(OutputInterface $output, array $report): void {
		foreach (($report['failures'] ?? []) as $failure) {
			$output->writeln("<error>{$failure['table']} · {$failure['index']}: {$failure['error']}</error>");
		}
	}//end writeFailures()

	/**
	 * Write a snapshot of the index definitions.
	 *
	 * @param IndexMaintenanceRun $run The run: output, --file and --register.
	 *
	 * @return int 0 on success.
	 */
	private function snapshot(IndexMaintenanceRun $run): int {
		$path = $run->file();
		if ($path === null) {
			$run->output->writeln('<error>snapshot needs --file.</error>');
			return 1;
		}

		$report = $this->maintenance->snapshot(path: $path, registerId: $run->registerId());
		$run->output->writeln(
			"Wrote {$report['indexes']} index definitions over {$report['tables']} tables to {$report['path']}"
		);

		return 0;
	}//end snapshot()

	/**
	 * Recreate from a snapshot whatever the database is missing.
	 *
	 * @param IndexMaintenanceRun $run The run: output, --file and --apply.
	 *
	 * @return int 0 when nothing failed.
	 */
	private function restore(IndexMaintenanceRun $run): int {
		$output = $run->output;
		$path   = $run->file();
		if ($path === null) {
			$output->writeln('<error>restore needs --file.</error>');
			return 1;
		}

		$apply  = $run->apply();
		$report = $this->maintenance->restore(path: $path, apply: $apply);
		$this->writeRestorePlan(output: $output, report: $report);

		if ($apply === false) {
			$output->writeln('<comment>Dry run. Nothing was created. Add --apply.</comment>');
			return 0;
		}

		return $this->restoreOutcome(output: $output, report: $report);
	}//end restore()

	/**
	 * Report an applied restore's failures: 1 when any index failed, else 0.
	 *
	 * @param OutputInterface      $output The console output.
	 * @param array<string, mixed> $report The restore report.
	 *
	 * @return int 0 when nothing failed.
	 */
	private function restoreOutcome(OutputInterface $output, array $report): int {
		$this->writeFailures(output: $output, report: $report);

		if (($report['failed'] ?? 0) > 0) {
			return 1;
		}

		return 0;
	}//end restoreOutcome()

	/**
	 * Print what a restore found missing and created, and each missing index.
	 *
	 * @param OutputInterface      $output The console output.
	 * @param array<string, mixed> $report The restore report.
	 *
	 * @return void
	 */
	private function writeRestorePlan(OutputInterface $output, array $report): void {
		$output->writeln("Missing: {$report['missing']}, created: {$report['created']}");

		foreach (($report['indexes'] ?? []) as $entry) {
			$output->writeln("  {$entry['table']} · {$entry['index']}");
		}
	}//end writeRestorePlan()

	/**
	 * Print the report of the last rebuild.
	 *
	 * @param OutputInterface $output The console output.
	 *
	 * @return int Always 0.
	 */
	private function status(OutputInterface $output): int {
		$lastRun = $this->maintenance->lastRun();
		if ($lastRun === []) {
			$output->writeln('No rebuild has run yet.');
			return 0;
		}

		$output->writeln((string)json_encode($lastRun, JSON_PRETTY_PRINT));

		return 0;
	}//end status()

	/**
	 * Refuse an action nobody defined.
	 *
	 * @param OutputInterface $output The console output.
	 * @param string          $action What was typed.
	 *
	 * @return int Always 1.
	 */
	private function unknownAction(OutputInterface $output, string $action): int {
		$output->writeln("<error>Unknown action '{$action}'. Use rebuild, snapshot, restore or status.</error>");

		return 1;
	}//end unknownAction()
}//end class
