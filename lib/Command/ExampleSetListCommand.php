<?php

/**
 * OpenRegister example-set list command
 *
 * `occ openregister:example-set:list` names every example set an enabled app
 * ships a fragment of, the apps that ship one and whether it is installed.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-can-list-the-sets-req-exs-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List the example sets.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-can-list-the-sets-req-exs-008
 */
class ExampleSetListCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetCatalogue $catalogue Finds the fragments.
	 * @param ExampleSetRecord    $record    Says whether a set is installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetCatalogue $catalogue,
		private readonly ExampleSetRecord $record,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-can-list-the-sets-req-exs-008
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:example-set:list')
			->setDescription(description: 'List the example sets the enabled apps ship, and whether each is installed');
	}//end configure()

	/**
	 * Print the sets.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int Always 0.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Symfony's signature; the command takes no input.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-can-list-the-sets-req-exs-008
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$sets = $this->catalogue->sets();
		if ($sets === []) {
			$output->writeln('No enabled app ships an example set.');
			return self::SUCCESS;
		}

		foreach ($sets as $set => $apps) {
			$state = 'installed';
			if ($this->record->isEmpty(record: $this->record->read(set: $set)) === true) {
				$state = 'not installed';
			}

			$output->writeln($set.' ('.$state.'): '.implode(', ', $apps));
		}

		return self::SUCCESS;
	}//end execute()
}//end class
