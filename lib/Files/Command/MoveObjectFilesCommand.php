<?php

/**
 * occ openregister:files:move-to-account
 *
 * Runs the same move as the upgrade step, so an admin can move what an earlier
 * run left behind.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Command;

use OCA\OpenRegister\Files\Repair\ObjectFileMoveCounts;
use OCA\OpenRegister\Files\Service\File\ObjectFileMigration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Move object files from people's homes into the openregister account's home.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class MoveObjectFilesCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param ObjectFileMigration $migration The move.
	 */
	public function __construct(
		private readonly ObjectFileMigration $migration,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:files:move-to-account')
			->setDescription(description: 'Move object files from people\'s homes into the OpenRegister account, keeping file ids');
	}//end configure()

	/**
	 * Run the move.
	 *
	 * @param InputInterface  $input  Input.
	 * @param OutputInterface $output Output.
	 *
	 * @return int 0 when the move ran, 1 when it was refused.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Symfony's signature; the command takes no input.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$tally = $this->migration->run();

		if ($tally['refused'] === true) {
			$output->writeln('<error>' . ObjectFileMigration::REFUSAL_MESSAGE . '</error>');
		}

		$output->writeln((new ObjectFileMoveCounts())->sentence(tally: $tally));

		if ($tally['refused'] === true) {
			return 1;
		}

		return 0;
	}//end execute()
}//end class
