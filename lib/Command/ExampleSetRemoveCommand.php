<?php

/**
 * OpenRegister example-set remove command
 *
 * `occ openregister:example-set:remove <set>` deletes exactly what an install
 * of the set made, as its record says. Exit codes: 0 everything recorded is
 * gone (or nothing was installed), 2 something could not be removed, each
 * named and kept in the record.
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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove an installed example set.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
class ExampleSetRemoveCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetRemover $remover Deletes what the record holds.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetRemover $remover,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:example-set:remove')
			->setDescription(description: 'Remove exactly what an install of an example set made: objects, memberships, groups and accounts')
			->addArgument(name: 'set', mode: InputArgument::REQUIRED, description: 'The set id');
	}//end configure()

	/**
	 * Remove the set and report.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int 0 or 2; see the class.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$set    = trim((string) $input->getArgument('set'));
		$report = $this->remover->remove(set: $set);
		if ($report['installed'] === false) {
			$output->writeln('Example set '.$set.' is not installed on this instance; nothing to remove.');
			return self::SUCCESS;
		}

		foreach ($report['removed'] as $kind => $count) {
			$output->writeln('Removed '.$kind.': '.$count);
		}

		foreach ($report['left'] as $uid) {
			$output->writeln('Left in place: account '.$uid.', which the set adopted and did not create.');
		}

		foreach ($report['failed'] as $line) {
			$output->writeln('<error>Not removed: '.$line.'</error>');
		}

		if ($report['failed'] !== []) {
			$output->writeln('<error>Some of the set is still here and stays in its record; run remove again once the cause is fixed.</error>');
			return ExampleSetInstallCommand::INCOMPLETE;
		}

		return self::SUCCESS;
	}//end execute()
}//end class
