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

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\Search\SearchIndexMaintenance;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Do the work. Without this flag rebuild and restore report what they would do and '
				. 'change nothing.'
			)
			->addOption(
				'register',
				null,
				InputOption::VALUE_REQUIRED,
				'Limit rebuild and snapshot to one register id.'
			)
			->addOption(
				'file',
				null,
				InputOption::VALUE_REQUIRED,
				'The snapshot file to write or read.'
			);
	}//end configure()

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
		$apply = (bool)$input->getOption('apply');
		$registerOption = $input->getOption('register');
		$registerId = null;
		if ($registerOption !== null) {
			$registerId = (int)$registerOption;
		}

		try {
			return match ($action) {
				'rebuild' => $this->rebuild(output: $output, registerId: $registerId, apply: $apply),
				'snapshot' => $this->snapshot(input: $input, output: $output, registerId: $registerId),
				'restore' => $this->restore(input: $input, output: $output, apply: $apply),
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
	 * @param OutputInterface $output     The console output.
	 * @param int|null        $registerId Limit to one register.
	 * @param bool            $apply      Whether to actually rebuild.
	 *
	 * @return int 0 when nothing failed.
	 */
	private function rebuild(OutputInterface $output, ?int $registerId, bool $apply): int {
		$report = $this->maintenance->rebuild(
			registerId: $registerId,
			apply: $apply,
			progress: static function (string $table, string $index, int $position, int $total) use ($output): void {
				$output->writeln("  [{$position}/{$total}] {$table} · {$index}");
			}
		);

		if (($report['state'] ?? '') === 'refused') {
			$output->writeln('<error>' . ($report['reason'] ?? 'Refused.') . '</error>');
			return 1;
		}

		$output->writeln('');
		$output->writeln("Tables: {$report['tables']}, indexes: {$report['indexes']}, rebuilt: {$report['rebuilt']}");

		if ($apply === false) {
			$output->writeln('<comment>Dry run. Nothing was rebuilt. Add --apply.</comment>');
			return 0;
		}

		foreach (($report['failures'] ?? []) as $failure) {
			$output->writeln(
				"<error>{$failure['table']} · {$failure['index']}: {$failure['error']}</error>"
			);
		}

		if (($report['failed'] ?? 0) > 0) {
			$output->writeln(
				'<error>The indexes that failed are unchanged and still answering.</error>'
			);
			return 1;
		}

		return 0;
	}//end rebuild()

	/**
	 * Write a snapshot of the index definitions.
	 *
	 * @param InputInterface  $input      The console input.
	 * @param OutputInterface $output     The console output.
	 * @param int|null        $registerId Limit to one register.
	 *
	 * @return int 0 on success.
	 */
	private function snapshot(InputInterface $input, OutputInterface $output, ?int $registerId): int {
		$path = $input->getOption('file');
		if ($path === null) {
			$output->writeln('<error>snapshot needs --file.</error>');
			return 1;
		}

		$report = $this->maintenance->snapshot(path: (string)$path, registerId: $registerId);
		$output->writeln(
			"Wrote {$report['indexes']} index definitions over {$report['tables']} tables to {$report['path']}"
		);

		return 0;
	}//end snapshot()

	/**
	 * Recreate from a snapshot whatever the database is missing.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 * @param bool            $apply  Whether to actually create.
	 *
	 * @return int 0 when nothing failed.
	 */
	private function restore(InputInterface $input, OutputInterface $output, bool $apply): int {
		$path = $input->getOption('file');
		if ($path === null) {
			$output->writeln('<error>restore needs --file.</error>');
			return 1;
		}

		$report = $this->maintenance->restore(path: (string)$path, apply: $apply);
		$output->writeln("Missing: {$report['missing']}, created: {$report['created']}");

		foreach (($report['indexes'] ?? []) as $entry) {
			$output->writeln("  {$entry['table']} · {$entry['index']}");
		}

		if ($apply === false) {
			$output->writeln('<comment>Dry run. Nothing was created. Add --apply.</comment>');
			return 0;
		}

		foreach (($report['failures'] ?? []) as $failure) {
			$output->writeln("<error>{$failure['table']} · {$failure['index']}: {$failure['error']}</error>");
		}

		if (($report['failed'] ?? 0) > 0) {
			return 1;
		}

		return 0;
	}//end restore()

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
