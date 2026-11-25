<?php

/**
 * OpenRegister api:conformance command
 *
 * Prints a register's conformance to the Dutch API design rules, one line per rule.
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
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\Oas\NlGovConformanceService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print a register's conformance report as a table or as JSON, optionally after probing its responses.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
class ApiConformanceCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param NlGovConformanceService $conformance The report and the probe.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function __construct(
		private readonly NlGovConformanceService $conformance,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:api:conformance')
			->setDescription(description: 'Report how a register\'s API conforms to the Dutch API design rules.')
			->addArgument(name: 'register', mode: InputArgument::REQUIRED, description: 'The register id, uuid or slug.')
			->addOption(name: 'probe', mode: InputOption::VALUE_NONE, description: 'Probe the register\'s responses first.')
			->addOption(name: 'output', mode: InputOption::VALUE_REQUIRED, description: 'table or json.', default: 'table');
	}//end configure()

	/**
	 * Print the report.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Command::SUCCESS, or Command::FAILURE when the register is unknown or the report cannot be made.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$register = (string) $input->getArgument('register');
		try {
			if ($input->getOption('probe') === true) {
				$report = $this->conformance->probe(registerId: $register);
			} else {
				$report = $this->conformance->report(registerId: $register);
			}
		} catch (DoesNotExistException $e) {
			$output->writeln(messages: sprintf('<error>Register "%s" not found.</error>', $register));
			return Command::FAILURE;
		} catch (\Throwable $e) {
			$output->writeln(messages: sprintf('<error>The report cannot be made: %s</error>', $e->getMessage()));
			return Command::FAILURE;
		}

		if ($input->getOption('output') === 'json') {
			$output->writeln(messages: (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return Command::SUCCESS;
		}

		$this->printTable(report: $report, output: $output);

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Print one row per rule and a line of counts.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param OutputInterface      $output Console output.
	 *
	 * @return void
	 */
	private function printTable(array $report, OutputInterface $output): void {
		$output->writeln(messages: sprintf('NLGov REST API Design Rules %s', $report['ruleset']));
		$table = new Table(output: $output);
		$table->setHeaders(['Rule', 'Kind', 'Result', 'Findings']);
		foreach ($report['rules'] as $rule) {
			$table->addRow([$rule['id'], $rule['kind'], $rule['result'], count($rule['findings'])]);
		}

		$table->render();

		$counts = [];
		foreach ($report['counts'] as $result => $count) {
			$counts[] = $result.': '.$count;
		}

		$output->writeln(messages: implode(', ', $counts));
	}//end printTable()
}//end class
