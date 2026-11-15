<?php

/**
 * OpenRegister document-store:status command
 *
 * Prints where OpenRegister keeps its managed documents: the root folder, the
 * storage behind it and whether that storage is separate from the instance's
 * primary storage.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\File\DocumentStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print the document store: root, backend, storage, mount point, separation and free space.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
class DocumentStoreStatusCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param DocumentStore $documentStore The store to describe.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		private readonly DocumentStore $documentStore,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:document-store:status')
			->setDescription(description: 'Show where OpenRegister keeps its documents and on which storage.');
	}//end configure()

	/**
	 * Print the store.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Command::SUCCESS, or Command::FAILURE when the root cannot be read.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$store = $this->documentStore->describe();
		} catch (\Throwable $e) {
			$output->writeln(messages: sprintf('<error>The document store cannot be read: %s</error>', $e->getMessage()));
			return Command::FAILURE;
		}

		$separate = 'no, it is on the instance\'s primary storage';
		if ($store['separate'] === true) {
			$separate = 'yes, it is separate from the instance\'s primary storage';
		}

		$free = 'not reported by the storage';
		if ($store['freeBytes'] !== null) {
			$free = sprintf('%d bytes', $store['freeBytes']);
		}

		$output->writeln(messages: sprintf('Root:        %s', $store['root']));
		$output->writeln(messages: sprintf('Backend:     %s (%s)', $store['backend'], $store['backendName']));
		$output->writeln(messages: sprintf('Storage:     %s', $store['storageId']));
		$output->writeln(messages: sprintf('Mount point: %s', $store['mountPoint']));
		$output->writeln(messages: sprintf('Separate:    %s', $separate));
		$output->writeln(messages: sprintf('Free space:  %s', $free));

		return Command::SUCCESS;
	}//end execute()
}//end class
