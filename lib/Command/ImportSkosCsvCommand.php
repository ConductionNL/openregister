<?php

/**
 * Import a SKOS concept scheme from a CSV value list.
 *
 * 🔴 THE SERVICE HAD NO CALLER. `VocabularyImportService::importCsvValueList()`
 * is specified by `skos-concept-registers` and was implemented, tested and
 * reachable from nothing — so the capability existed only in the sense that the
 * code was present. gate-57 named it an orphaned write capability.
 *
 * 🔑 A COMMAND, NOT A ROUTE. The service takes a filesystem PATH to a CSV. A
 * path is meaningless across an HTTP boundary — the file lives on the server,
 * not in the caller's request — so exposing this as an endpoint would either
 * force an upload surface nobody asked for, or accept a server-side path from a
 * client, which is a traversal invitation. Vocabulary loading is an
 * administrative act performed on the machine that holds the file.
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
 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use InvalidArgumentException;
use OCA\OpenRegister\Service\VocabularyImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ openregister:vocabulary:import-csv` — load a SKOS scheme from CSV.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
 */
class ImportSkosCsvCommand extends Command {
	/**
	 * Wire the import service.
	 *
	 * @param VocabularyImportService $vocabulary Performs the idempotent import.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	public function __construct(
		private readonly VocabularyImportService $vocabulary,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Define the command name, argument and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:vocabulary:import-csv')
			->setDescription(
				'Import a SKOS concept scheme from a CSV value list. The import is idempotent and keyed on '
				. 'concept URI, so re-running it updates rather than duplicating.'
			)
			->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file, on this server')
			->addOption('scheme-uri', null, InputOption::VALUE_REQUIRED, 'The concept scheme URI (required)')
			->addOption('scheme-title', null, InputOption::VALUE_REQUIRED, 'A human title for the scheme')
			->addOption('register', null, InputOption::VALUE_REQUIRED, 'Register slug or id to import into')
			->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Schema slug or id to import into');

	}//end configure()

	/**
	 * Run the import and report what it changed.
	 *
	 * Reports the four counts the service returns rather than a bare "done":
	 * an idempotent import's whole point is that the SECOND run changes
	 * nothing, and that is only visible if the numbers are printed.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return integer Symfony exit code.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$file = (string)$input->getArgument('file');
		$schemeUri = (string)($input->getOption('scheme-uri') ?? '');

		if ($schemeUri === '') {
			$output->writeln('<error>--scheme-uri is required: a SKOS import keyed on URI needs the scheme it belongs to.</error>');
			return Command::INVALID;
		}

		$meta = ['uri' => $schemeUri];
		foreach (['scheme-title' => 'title', 'register' => 'register', 'schema' => 'schema'] as $option => $key) {
			$value = (string)($input->getOption($option) ?? '');
			if ($value !== '') {
				$meta[$key] = $value;
			}
		}

		try {
			$result = $this->vocabulary->importCsvValueList(csvPath: $file, schemeMeta: $meta);
		} catch (InvalidArgumentException $e) {
			// A bad path or an empty file is the OPERATOR's mistake, not a
			// crash: report it as invalid input so a script can tell the two
			// apart by exit code.
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::INVALID;
		} catch (Throwable $e) {
			$output->writeln('<error>Import failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}//end try

		$output->writeln(sprintf(
			'<info>Imported scheme %s — created %d, updated %d, unchanged %d, deprecated %d.</info>',
			(string)($result['scheme'] ?? $schemeUri),
			(int)($result['created'] ?? 0),
			(int)($result['updated'] ?? 0),
			(int)($result['unchanged'] ?? 0),
			(int)($result['deprecated'] ?? 0)
		));

		return Command::SUCCESS;

	}//end execute()
}//end class
