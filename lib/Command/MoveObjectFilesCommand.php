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
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\File\ObjectFileMigration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Move object files from people's homes into the openregister account's home.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
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
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
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
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$tally = $this->migration->run();

		if ($tally['refused'] === true) {
			$output->writeln('<error>' . ObjectFileMigration::REFUSAL_MESSAGE . '</error>');
		}

		$output->writeln(
			sprintf(
				'%d folders moved, %d files moved, %d published links re-owned, %d left where they are',
				$tally['foldersMoved'],
				$tally['filesMoved'],
				$tally['sharesReowned'],
				$tally['foldersLeft']
			)
		);

		if ($tally['refused'] === true) {
			return 1;
		}

		return 0;
	}//end execute()
}//end class
