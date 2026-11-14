<?php

/**
 * OpenRegister document-store:move command
 *
 * Moves the managed documents, register by register, into a root on another
 * storage, keeping every reference, publication link, tag and earlier version;
 * or, with --purge-source, deletes the old tree once every file in it has a
 * verified copy.
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

use OCA\OpenRegister\Service\File\DocumentMoveOutcome;
use OCA\OpenRegister\Service\File\DocumentStoreMove;
use OCA\OpenRegister\Service\File\DocumentStoreMoveOptions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Move the document store, or purge the old one.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
class DocumentStoreMoveCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param DocumentStoreMove $move The move.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		private readonly DocumentStoreMove $move,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, describe and declare the options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:document-store:move')
			->setDescription(description: 'Move OpenRegister\'s documents to a folder on another storage, or purge the old one.')
			->addOption(
				name: 'to',
				mode: InputOption::VALUE_REQUIRED,
				description: 'The target folder in the openregister account\'s home, on the new storage.'
			)
			->addOption(name: 'register', mode: InputOption::VALUE_REQUIRED, description: 'Move only this register (its id).')
			->addOption(
				name: 'dry-run',
				mode: InputOption::VALUE_NONE,
				description: 'Report what would be moved and what would stop it; change nothing.'
			)
			->addOption(
				name: 'without-earlier-versions',
				mode: InputOption::VALUE_NONE,
				description: 'Move files with earlier versions to a target that keeps none, recording it per file.'
			)
			->addOption(
				name: 'purge-source',
				mode: InputOption::VALUE_NONE,
				description: 'Delete the old tree, only when every file in it has a verified copy.'
			);
	}//end configure()

	/**
	 * Run the move or the purge and print the report.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Command::SUCCESS, or Command::FAILURE when anything was refused.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('purge-source') === true) {
			return $this->purge(output: $output);
		}

		$options = $this->options(input: $input, output: $output);
		if ($options === null) {
			return Command::FAILURE;
		}

		try {
			$result = $this->move->move(options: $options);
		} catch (\Throwable $e) {
			$output->writeln(messages: sprintf('<error>%s</error>', $e->getMessage()));
			return Command::FAILURE;
		}

		$refused = $this->printOutcomes(outcomes: $result['outcomes'], output: $output);
		if ($result['rootSwitched'] === true) {
			$output->writeln(messages: sprintf('Every register is switched: the document store root is now "%s".', $options->targetPath()));
		}

		if ($refused === true) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * The run's options from the input, or null after printing why they are refused.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return DocumentStoreMoveOptions|null
	 */
	private function options(InputInterface $input, OutputInterface $output): ?DocumentStoreMoveOptions {
		$target = (string)$input->getOption('to');
		if ($target === '') {
			$output->writeln(messages: '<error>Pass --to with the target folder, or --purge-source.</error>');
			return null;
		}

		$register = $input->getOption('register');
		if ($register !== null && self::registerId(value: $register) === null) {
			$output->writeln(messages: '<error>--register takes a register id.</error>');
			return null;
		}

		return new DocumentStoreMoveOptions(
			target: $target,
			registerId: self::registerId(value: $register),
			dryRun: ($input->getOption('dry-run') === true),
			withoutVersions: ($input->getOption('without-earlier-versions') === true),
		);
	}//end options()

	/**
	 * Print every register's outcome.
	 *
	 * @param list<DocumentMoveOutcome> $outcomes The outcomes.
	 * @param OutputInterface           $output   Console output.
	 *
	 * @return bool Whether any register was refused.
	 */
	private function printOutcomes(array $outcomes, OutputInterface $output): bool {
		$refused = false;
		foreach ($outcomes as $outcome) {
			$this->printOutcome(outcome: $outcome, output: $output);
			if ($outcome->isRefused() === true) {
				$refused = true;
			}
		}

		return $refused;
	}//end printOutcomes()

	/**
	 * Purge the renamed old tree and print what stopped it.
	 *
	 * @param OutputInterface $output Console output.
	 *
	 * @return int
	 */
	private function purge(OutputInterface $output): int {
		$result = $this->move->purge();
		if ($result['path'] === null) {
			$output->writeln(messages: 'There is no old document tree to purge.');
			return Command::SUCCESS;
		}

		if ($result['deleted'] === true) {
			$output->writeln(messages: sprintf('Deleted the old document tree "%s".', $result['path']));
			return Command::SUCCESS;
		}

		$output->writeln(messages: sprintf(
			'<error>Nothing deleted: %d items in "%s" have no verified copy:</error>',
			count($result['unmapped']),
			$result['path']
		));
		foreach ($result['unmapped'] as $path) {
			$output->writeln(messages: sprintf('  %s', $path));
		}

		return Command::FAILURE;
	}//end purge()

	/**
	 * Print one register's line, its refusals and its notes.
	 *
	 * @param DocumentMoveOutcome $outcome The register's outcome.
	 * @param OutputInterface     $output  Console output.
	 *
	 * @return void
	 */
	private function printOutcome(DocumentMoveOutcome $outcome, OutputInterface $output): void {
		$state = $outcome->state;
		if ($outcome->isRefused() === true) {
			$state = DocumentMoveOutcome::REFUSED;
		}

		$output->writeln(
			messages: sprintf(
				'Register %d (%s): %s; %d folders, %d files, %d earlier versions, %d shares, %d objects.',
				$outcome->registerId,
				$outcome->name,
				$state,
				$outcome->folders,
				$outcome->files,
				$outcome->versions,
				$outcome->shares,
				$outcome->objects
			)
		);
		foreach ($outcome->refusals as $reason) {
			$output->writeln(messages: sprintf('  refused: %s', $reason));
		}

		foreach ($outcome->notes as $note) {
			$output->writeln(messages: sprintf('  note: %s', $note));
		}
	}//end printOutcome()

	/**
	 * The --register value as an id, or null.
	 *
	 * @param mixed $value The option value.
	 *
	 * @return int|null
	 */
	private static function registerId(mixed $value): ?int {
		if (is_string($value) === true && ctype_digit($value) === true) {
			return (int)$value;
		}

		return null;
	}//end registerId()
}//end class
