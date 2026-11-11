<?php

/**
 * OpenRegister example-set install command
 *
 * `occ openregister:example-set:install <set> [--check] [--adopt=uid ...] [--password-from-env]`
 *
 * Installs a story example set across every enabled app, or with `--check`
 * only validates it. Both first import the OpenRegister registers the set
 * requires and the instance lacks. Exit codes: 0 everything declared is on
 * the instance (or the check passed), 1 none of the set's accounts, groups or
 * objects was written (unknown set, a refusal of the check, an account that
 * belongs to somebody else), 2 something did not arrive, each named.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Kernel\Contracts\ExampleSetInstallerInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogueInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyMarksInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Install or check an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */
class ExampleSetInstallCommand extends Command {

	/**
	 * The options, in declaration order: name => [mode, description]. None has a shortcut.
	 */
	private const OPTIONS = [
		'check' => [
			InputOption::VALUE_NONE,
			'Validate every object against its schema; writes no account, group or object '
				.'(a missing required register is still imported, and a missing cast organisation created)',
		],
		'adopt' => [
			(InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
			'An existing account id the set may use although it did not create it; remove leaves it in place',
		],
		'refresh' => [
			InputOption::VALUE_NONE,
			'Rewrite every object the set declares and already holds to its current values '
				.'(dates against today, cast, lookups); objects the set did not write are left alone',
		],
		'daily' => [
			InputOption::VALUE_NONE,
			'Also refresh the set once a day from now on, so its dates follow the day it is viewed (a demo instance)',
		],
		'no-daily' => [
			InputOption::VALUE_NONE,
			'Stop the daily refresh of the set',
		],
		'password-from-env' => [
			InputOption::VALUE_NONE,
			'Use the environment variable OC_PASS as the password of every new account instead of making one each',
		],
	];

	/**
	 * The report's parts, in print order, with the word each line starts with.
	 */
	private const REPORT_PARTS = [
		'registers'   => 'Register',
		'groups'      => 'Group',
		'users'       => 'Account',
		'memberships' => 'Membership',
		'changes'     => 'Change',
		'appConfig'   => 'App config',
		'userPreferences' => 'User preference',
		'tasks'       => 'Task',
		'views'       => 'View',
		'watchers'    => 'Watcher',
		'parties'     => 'Party',
		'notes'       => 'Note',
		'readStates'  => 'Read state',
		'flows'       => 'Flow',
	];
	/**
	 * Exit code when something did not arrive.
	 */
	public const INCOMPLETE = 2;

	/**
	 * Constructor.
	 *
	 * @param ExampleSetCatalogueInterface $catalogue Gathers the set.
	 * @param ExampleSetInstallerInterface $installer Checks, writes and proves.
	 * @param ExampleSetDailyMarksInterface|null $daily Marks a set for the daily refresh.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetCatalogueInterface $catalogue,
		private readonly ExampleSetInstallerInterface $installer,
		private readonly ?ExampleSetDailyMarksInterface $daily=null,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:example-set:install')
			->setDescription(description: 'Install a story example set across every enabled app, for a demo or test instance')
			->addArgument(name: 'set', mode: InputArgument::REQUIRED, description: 'The set id; openregister:example-set:list shows them');
		foreach (self::OPTIONS as $name => [$mode, $description]) {
			$this->addOption(name: $name, shortcut: null, mode: $mode, description: $description);
		}
	}//end configure()

	/**
	 * Check or install the set and report.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int 0, 1 or 2; see the class.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$plan = $this->catalogue->gather(set: trim((string) $input->getArgument('set')));
		} catch (ExampleSetException $e) {
			$output->writeln('<error>'.$e->getMessage().'</error>');
			return self::FAILURE;
		}

		$apps = array_map(static fn (array $fragment): string => $fragment['app'], $plan['fragments']);
		$output->writeln('Example set '.$plan['set'].' ('.$plan['title'].'), fragments from: '.implode(', ', $apps));
		foreach ($plan['skipped'] as $app) {
			$output->writeln('<comment>Skipped: '.$app.' is not enabled on this instance.</comment>');
		}

		$output->writeln($this->markDaily(set: (string) $plan['set'], input: $input));

		$adopt = array_values(array_filter(array_map('strval', (array) $input->getOption('adopt'))));
		if ($input->getOption('check') === true) {
			return $this->reportCheck(result: $this->installer->check(plan: $plan, adopt: $adopt), output: $output);
		}

		$password = '';
		if ($input->getOption('password-from-env') === true) {
			$password = (string) getenv('OC_PASS');
			if ($password === '') {
				$output->writeln('<error>--password-from-env was given, but the environment variable OC_PASS is empty. Nothing was written.</error>');
				return self::FAILURE;
			}
		}

		$plan['refresh'] = ($input->getOption('refresh') === true);
		$report          = $this->installer->install(plan: $plan, adopt: $adopt, password: $password);
		if ($report['refusals'] !== []) {
			$this->printRefusals(refusals: $report['refusals'], output: $output);
			$output->writeln('<error>The set was refused, so none of its accounts, groups or objects was written.</error>');
			return self::FAILURE;
		}

		$this->printReport(report: $report, output: $output);
		if ($report['ok'] === false) {
			$output->writeln('<error>The example set is not complete. See the lines above.</error>');
			return self::INCOMPLETE;
		}

		$output->writeln('<info>Every declared account, group and object is on this instance.</info>');

		return self::SUCCESS;
	}//end execute()

	/**
	 * Mark or unmark the set for the daily refresh when `--daily` or `--no-daily` asks.
	 *
	 * @param string         $set   The set id.
	 * @param InputInterface $input The command input.
	 *
	 * @return array<int, string> The line naming the daily sets, or none when nothing was asked.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	private function markDaily(string $set, InputInterface $input): array {
		if ($this->daily === null || ($input->getOption('daily') !== true && $input->getOption('no-daily') !== true)) {
			return [];
		}

		$this->daily->mark(set: $set, daily: ($input->getOption('daily') === true));
		$sets = $this->daily->sets();
		if ($sets === []) {
			$sets = ['none'];
		}

		return ['Daily refresh: '.implode(', ', $sets)];
	}//end markDaily()

	/**
	 * Report the outcome of a check-only run.
	 *
	 * @param array<string, mixed> $result What the installer's check found.
	 * @param OutputInterface      $output The command output.
	 *
	 * @return int 0 when nothing is refused, 1 otherwise.
	 */
	private function reportCheck(array $result, OutputInterface $output): int {
		$this->printChecked(result: $result, output: $output);

		if ($result['refusals'] !== []) {
			$this->printRefusals(refusals: $result['refusals'], output: $output);
			$output->writeln('<error>'.count($result['refusals']).' refusal(s). No account, group or object of the set was written.</error>');
			return self::FAILURE;
		}

		$output->writeln('<info>Every object passes its schema. No account, group or object of the set was written.</info>');
		$output->writeln(
			'A required register the instance lacked was imported, and a cast organisation it lacked was created, because the check needs them.'
		);

		return self::SUCCESS;
	}//end reportCheck()

	/**
	 * Print what the check covered: each required register, and the objects and changes checked per type.
	 *
	 * @param array<string, mixed> $result The installer's check result.
	 * @param OutputInterface      $output The command output.
	 *
	 * @return void
	 */
	private function printChecked(array $result, OutputInterface $output): void {
		foreach ($result['registers'] as $slug => $outcome) {
			$output->writeln('Register '.$slug.': '.self::word(outcome: $outcome));
		}

		foreach ($result['checked'] as $type => $count) {
			$output->writeln($type.': '.$count.' checked');
		}

		foreach ($result['checkedChanges'] as $type => $count) {
			$output->writeln($type.': '.$count.' change(s) checked');
		}
	}//end printChecked()

	/**
	 * Print refusals, one per line.
	 *
	 * @param array<int, string> $refusals The refusals.
	 * @param OutputInterface    $output   The command output.
	 *
	 * @return void
	 */
	private function printRefusals(array $refusals, OutputInterface $output): void {
		foreach ($refusals as $refusal) {
			$output->writeln('<error>Refused: '.$refusal.'</error>');
		}
	}//end printRefusals()

	/**
	 * Print what the install did, what went wrong and the new passwords.
	 *
	 * @param array<string, mixed> $report The installer's report.
	 * @param OutputInterface      $output The command output.
	 *
	 * @return void
	 */
	private function printReport(array $report, OutputInterface $output): void {
		$this->printParts(report: $report, output: $output);
		$this->printTypes(types: $report['types'], output: $output);

		if ($report['files'] > 0) {
			$output->writeln('Files attached: '.$report['files']);
		}

		$this->printOutstanding(report: $report, output: $output);
		$this->printPasswords(passwords: $report['passwords'], output: $output);
	}//end printReport()

	/**
	 * Print the outcome of every register, group, account and other part the set wrote, part by part.
	 *
	 * @param array<string, mixed> $report The installer's report.
	 * @param OutputInterface      $output The command output.
	 *
	 * @return void
	 */
	private function printParts(array $report, OutputInterface $output): void {
		foreach (self::REPORT_PARTS as $part => $word) {
			foreach (($report[$part] ?? []) as $name => $outcome) {
				$output->writeln($word.' '.$name.': '.self::word(outcome: $outcome));
			}
		}
	}//end printParts()

	/**
	 * Print the passwords of the accounts this install made, once.
	 *
	 * @param array<string, string> $passwords The new passwords by uid.
	 * @param OutputInterface       $output    The command output.
	 *
	 * @return void
	 */
	private function printPasswords(array $passwords, OutputInterface $output): void {
		if ($passwords === []) {
			return;
		}

		$output->writeln('New passwords, shown once and stored nowhere:');
		foreach ($passwords as $uid => $password) {
			$output->writeln('  '.$uid.': '.$password);
		}
	}//end printPasswords()

	/**
	 * Print the counts per register and schema.
	 *
	 * @param array<string, array<string, int>> $types  Counts by `<register> <schema>`.
	 * @param OutputInterface                   $output The command output.
	 *
	 * @return void
	 */
	private function printTypes(array $types, OutputInterface $output): void {
		foreach ($types as $type => $counts) {
			$output->writeln(
				sprintf(
					'%s: %d declared, %d created, %d already there%s, %d found afterwards',
					$type,
					$counts['declared'],
					$counts['created'],
					$counts['kept'],
					$this->refreshedNote(counts: $counts),
					$counts['arrived']
				)
			);
		}
	}//end printTypes()

	/**
	 * " (n refreshed, m unchanged)" after a `--refresh`, nothing otherwise.
	 *
	 * @param array<string, int> $counts The counts of one type.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-only-an-object-whose-values-differ
	 */
	private function refreshedNote(array $counts): string {
		if (isset($counts['refreshed']) === false && isset($counts['unchanged']) === false) {
			return '';
		}

		return sprintf(' (%d refreshed, %d unchanged)', ($counts['refreshed'] ?? 0), ($counts['unchanged'] ?? 0));
	}//end refreshedNote()

	/**
	 * Print what was left as it is, and what is not written, not there or not kept.
	 *
	 * @param array<string, mixed> $report The installer's report.
	 * @param OutputInterface      $output The command output.
	 *
	 * @return void
	 */
	private function printOutstanding(array $report, OutputInterface $output): void {
		foreach ($report['left'] as $line) {
			$output->writeln('Left as it is: '.$line);
		}

		$problems = ['Not written: ' => $report['dropped'], 'Not on the instance: ' => $report['missing'], 'Written but not kept: ' => $report['lost']];
		foreach ($problems as $words => $lines) {
			foreach ($lines as $line) {
				$output->writeln('<error>'.$words.$line.'</error>');
			}
		}
	}//end printOutstanding()

	/**
	 * The words for an outcome.
	 *
	 * @param string $outcome `created`, `adopted` or `kept`.
	 *
	 * @return string
	 */
	private static function word(string $outcome): string {
		if ($outcome === 'kept') {
			return 'already there';
		}

		return $outcome;
	}//end word()
}//end class
