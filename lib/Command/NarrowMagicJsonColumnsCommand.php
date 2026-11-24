<?php

/**
 * OpenRegister tables:narrow-json command.
 *
 * On MariaDB an object table created before JSON was stored as checked
 * MEDIUMTEXT holds it in LONGTEXT, and every such column slows each read
 * Nextcloud makes of the database schema, so every upgrade of any app. The
 * upgrade narrows the small tables itself; rebuilding a large one can take
 * minutes and blocks writes to it meanwhile, so that is left to this command,
 * to be run when it suits.
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
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\MagicMapper\MagicJsonColumnNarrower;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Narrow the LONGTEXT JSON columns of the object tables, whatever their size.
 */
class NarrowMagicJsonColumnsCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param MagicJsonColumnNarrower $narrower Finds and narrows the columns.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MagicJsonColumnNarrower $narrower,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:tables:narrow-json')
			->setDescription(
				'MariaDB only: rebuild the object tables that still hold JSON in LONGTEXT columns, '
				. 'which slow down every database upgrade. Each table is locked for writes while it is rebuilt.'
			)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the tables and their size, and change nothing.');
	}//end configure()

	/**
	 * List or narrow the pending tables.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->narrower->applies() === false) {
			$output->writeln('<comment>This database stores JSON natively — nothing to narrow.</comment>');
			return Command::SUCCESS;
		}

		if ($input->getOption('dry-run') === true) {
			return $this->listPending(output: $output);
		}

		$result = $this->narrower->narrowPending();
		foreach ($result['failed'] as $table => $reason) {
			$output->writeln(sprintf('<error>%s: left as it is: %s</error>', $table, $reason));
		}

		$output->writeln(sprintf('Narrowed the JSON columns of %d table(s); %d could not be.', count($result['narrowed']), count($result['failed'])));
		if ($result['failed'] !== []) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Print the tables that still hold JSON in LONGTEXT, with their size.
	 *
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 */
	private function listPending(OutputInterface $output): int {
		$pending = $this->narrower->pending();
		foreach ($pending as $table => $entry) {
			$output->writeln(sprintf('%s  %d column(s)  %.1f MB', $table, count($entry['columns']), ($entry['bytes'] / 1048576)));
		}

		$output->writeln(sprintf('%d table(s) hold JSON in LONGTEXT columns.', count($pending)));

		return Command::SUCCESS;
	}//end listPending()
}//end class
