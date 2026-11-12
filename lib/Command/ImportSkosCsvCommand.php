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
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
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
	 * @param VocabularyImporterInterface $vocabulary Performs the idempotent import.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	public function __construct(
		private readonly VocabularyImporterInterface $vocabulary,
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
		$schemeUri = (string)($input->getOption('scheme-uri') ?? '');

		if ($schemeUri === '') {
			$output->writeln('<error>--scheme-uri is required: a SKOS import keyed on URI needs the scheme it belongs to.</error>');
			return Command::INVALID;
		}

		return $this->importValueList(input: $input, output: $output);

	}//end execute()

	/**
	 * Import the CSV value list and report the outcome.
	 *
	 * @param InputInterface  $input  The file argument and the scheme options.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int SUCCESS, INVALID for an operator mistake, FAILURE for any other failure.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	private function importValueList(InputInterface $input, OutputInterface $output): int {
		$file = (string)$input->getArgument('file');
		$meta = self::schemeMeta(input: $input);

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

		$output->writeln(self::summary(result: $result, schemeUri: $meta['uri']));

		return Command::SUCCESS;

	}//end importValueList()

	/**
	 * The scheme metadata: the URI, and each of title, register and schema that was given.
	 *
	 * @param InputInterface $input The console input.
	 *
	 * @return array<string, string> The scheme metadata.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	private static function schemeMeta(InputInterface $input): array {
		$meta = ['uri' => (string)($input->getOption('scheme-uri') ?? '')];
		foreach (['scheme-title' => 'title', 'register' => 'register', 'schema' => 'schema'] as $option => $key) {
			$value = (string)($input->getOption($option) ?? '');
			if ($value !== '') {
				$meta[$key] = $value;
			}
		}

		return $meta;

	}//end schemeMeta()

	/**
	 * The closing line: the scheme and its created, updated, unchanged and deprecated counts.
	 *
	 * @param array<string, mixed> $result    The importer's result.
	 * @param string               $schemeUri The scheme URI, named when the result names no scheme.
	 *
	 * @return string The line to print.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	private static function summary(array $result, string $schemeUri): string {
		return sprintf(
			'<info>Imported scheme %s — created %d, updated %d, unchanged %d, deprecated %d.</info>',
			(string)($result['scheme'] ?? $schemeUri),
			(int)($result['created'] ?? 0),
			(int)($result['updated'] ?? 0),
			(int)($result['unchanged'] ?? 0),
			(int)($result['deprecated'] ?? 0)
		);

	}//end summary()
}//end class
