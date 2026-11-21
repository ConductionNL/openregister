<?php

/**
 * OpenRegister declared-groups command
 *
 * Prints every Nextcloud group this instance's registers, schemas and stored
 * app declarations depend on, with its live membership state.
 *
 * Read-only. Exists because provisioning solved half the problem: a declared
 * group now always EXISTS, but one nobody belongs to still denies every caller,
 * and `PermissionHandlerInterface::hasGroupPermission()` decides by membership test
 * alone — so an unpopulated group is indistinguishable from a correctly
 * configured one until someone is refused.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\Authorization\DeclaredGroupInventoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report the declared RBAC groups and whether anyone belongs to them.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class DeclaredGroupsCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param DeclaredGroupInventoryInterface $inventory Declared-group inventory.
	 */
	public function __construct(
		private readonly DeclaredGroupInventoryInterface $inventory,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:declared-groups')
			->setDescription(
				description: 'List the Nextcloud groups this instance declares, and whether anyone belongs to them'
			)
			->addOption(
				name: 'problems-only',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Show only groups that currently grant nobody anything (missing, or present with zero members)'
			);
	}//end configure()

	/**
	 * Execute the command.
	 *
	 * Exit code is 0 even when groups grant nobody anything: an unpopulated
	 * group is a state an administrator must decide about, not a failure of this
	 * instance. Making it non-zero would turn a report into a broken cron job.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Always 0.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->inventory->inventory();
		$problemsOnly = ($input->getOption('problems-only') === true);

		if ($report['declared'] === 0) {
			$output->writeln('<info>This instance declares no RBAC groups.</info>');
			return 0;
		}

		foreach (self::rowsToShow(groups: $report['groups'], problemsOnly: $problemsOnly) as $row) {
			$output->writeln($this->formatRow(row: $row));
		}

		$output->writeln('');
		$output->writeln(self::totalsLine(report: $report));
		$this->warnAboutMemberlessGroups(report: $report, output: $output);

		return 0;
	}//end execute()

	/**
	 * The rows to print: all of them, or with --problems-only only the groups that grant nobody.
	 *
	 * @param array<int, array<string, mixed>> $groups       The inventory rows.
	 * @param bool                             $problemsOnly Whether --problems-only was given.
	 *
	 * @return array<int, array<string, mixed>> The rows, in inventory order.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private static function rowsToShow(array $groups, bool $problemsOnly): array {
		$rows = [];
		foreach ($groups as $row) {
			if ($problemsOnly === true && $row['grantsNobody'] === false) {
				continue;
			}

			$rows[] = $row;
		}

		return $rows;
	}//end rowsToShow()

	/**
	 * The totals line: declared, missing, empty and uncountable groups.
	 *
	 * @param array<string, mixed> $report The inventory.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private static function totalsLine(array $report): string {
		return sprintf(
			'%d declared · %d missing · %d empty · %d uncountable',
			$report['declared'],
			$report['missing'],
			$report['empty'],
			$report['unknown']
		);
	}//end totalsLine()

	/**
	 * Explain, when a group is missing or empty, that such a group denies every caller.
	 *
	 * @param array<string, mixed> $report The inventory.
	 * @param OutputInterface      $output The console output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function warnAboutMemberlessGroups(array $report, OutputInterface $output): void {
		if ($report['missing'] > 0 || $report['empty'] > 0) {
			$output->writeln('');
			$output->writeln(
				'<comment>A group with no members denies every caller. RBAC resolves access by membership '
				. 'test alone, so these rules read as configured and behave as denied until you add members.</comment>'
			);
		}
	}//end warnAboutMemberlessGroups()

	/**
	 * Render one inventory row.
	 *
	 * @param array{group: string, exists: bool, members: int|null, grantsNobody: bool} $row The row.
	 *
	 * @return string The formatted line.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function formatRow(array $row): string {
		if ($row['exists'] === false) {
			return sprintf('  <error>MISSING</error>      %s  (declared, but no such Nextcloud group)', $row['group']);
		}

		if ($row['members'] === null) {
			// Not an alarm: the backend cannot count, so membership is UNKNOWN
			// rather than zero. Reporting it as empty would be a false alarm.
			return sprintf('  <comment>UNKNOWN</comment>      %s  (backend cannot report a member count)', $row['group']);
		}

		if ($row['members'] === 0) {
			return sprintf('  <comment>0 members</comment>    %s  (grants nobody anything)', $row['group']);
		}

		return sprintf('  <info>%-2d members</info>   %s', $row['members'], $row['group']);
	}//end formatRow()

}//end class
