<?php

/**
 * OpenRegister dedupe-shared-schemas command
 *
 * Reports — and on explicit request repairs — schema entities that more than one
 * register co-owns, splitting each non-canonical register onto its own entity
 * and moving its object rows with it.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\SharedSchemaDedupeService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Split schema entities that several registers wrongly share.
 *
 * DRY RUN BY DEFAULT. `--write` is required to change anything.
 *
 * This is the counterpart to `openregister:registers:relink-schemas`: that
 * command ADDS linkage a register lost, this one SPLITS linkage a register was
 * never meant to have. Both mutate the `schemas` boundary, so both show the
 * operator the whole change first and then ask them to opt in.
 *
 * The command REFUSES to write a schema it could not attribute. Guessing an
 * owner is what produced the damage in the first place — an import resolving a
 * slug globally and landing on someone else's entity — so a schema whose
 * referencing registers' configurations do not single one owner out is reported
 * and skipped until `--keep` names one.
 *
 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
 */
class DedupeSharedSchemasCommand extends Command {

	/**
	 * Wire the dedupe service.
	 *
	 * @param SharedSchemaDedupeService $dedupe The shared-schema repair service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SharedSchemaDedupeService $dedupe,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:registers:dedupe-shared-schemas')
			->setDescription(
				'Split schema entities shared by several registers so each owns its own (dry run by default)'
			)
			->addOption(
				'write',
				null,
				InputOption::VALUE_NONE,
				'Apply the changes. Without this flag nothing is modified.'
			)
			->addOption(
				'register',
				null,
				InputOption::VALUE_REQUIRED,
				'Limit to shared schemas involving this register id.'
			)
			->addOption(
				'keep',
				null,
				(InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
				'Name the owner of a schema attribution could not settle: '
				. '<schemaId>:<registerId>, or a bare <registerId> for all of them. Repeatable.'
			)
			->addOption(
				'strict',
				null,
				InputOption::VALUE_NONE,
				'Refuse any split whose source table has a column with no destination.'
			);
	}//end configure()

	/**
	 * Run the inspection, and the repair when --write is given.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int The exit code.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$write  = (bool)$input->getOption('write');
		$strict = (bool)$input->getOption('strict');

		$registerId = $input->getOption('register');
		if ($registerId !== null) {
			$registerId = (int)$registerId;
		}

		try {
			$keep = $this->dedupe->parseKeep(raw: (array)$input->getOption('keep'));
		} catch (RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::INVALID;
		}

		$plan = $this->dedupe->inspect(registerId: $registerId, keep: $keep);

		if ($plan === []) {
			$output->writeln('<info>No schema is shared by more than one register. Nothing to do.</info>');
			return Command::SUCCESS;
		}

		$output->writeln(
			sprintf('<comment>%d schema(s) are shared by more than one register:</comment>', count($plan))
		);
		$output->writeln('');

		$unattributed = 0;
		foreach ($plan as $entry) {
			$this->renderSchema(output: $output, entry: $entry);
			if ($entry['owner'] === null) {
				$unattributed++;
			}
		}

		if ($write === false) {
			return $this->reportDryRun(output: $output, unattributed: $unattributed);
		}

		if ($unattributed > 0) {
			$output->writeln('');
			$output->writeln(
				sprintf(
					'<error>Refusing to write: %d schema(s) are unattributed. '
					. 'Name their owner with --keep <schemaId>:<registerId>.</error>',
					$unattributed
				)
			);
			return Command::FAILURE;
		}

		return $this->applyPlan(output: $output, plan: $plan, strict: $strict);
	}//end execute()

	/**
	 * Report the outcome of a dry run.
	 *
	 * @param OutputInterface $output       The console output.
	 * @param int             $unattributed How many schemas could not be attributed.
	 *
	 * @return int The exit code.
	 */
	private function reportDryRun(OutputInterface $output, int $unattributed): int {
		$output->writeln('');
		if ($unattributed > 0) {
			$output->writeln(
				sprintf(
					'<comment>%d schema(s) are unattributed and would be SKIPPED. '
					. 'Name their owner with --keep <schemaId>:<registerId>.</comment>',
					$unattributed
				)
			);
		}

		$output->writeln('<comment>DRY RUN — nothing was changed. Re-run with --write to apply.</comment>');

		return Command::SUCCESS;
	}//end reportDryRun()

	/**
	 * Execute every planned split.
	 *
	 * @param OutputInterface           $output The console output.
	 * @param array<int, array<string, mixed>> $plan   The inspection plan.
	 * @param bool                      $strict Whether unmapped columns refuse the move.
	 *
	 * @return int The exit code.
	 */
	private function applyPlan(OutputInterface $output, array $plan, bool $strict): int {
		$split  = 0;
		$failed = 0;

		$output->writeln('');
		foreach ($plan as $entry) {
			foreach (array_keys($entry['splits']) as $registerId) {
				try {
					$result = $this->dedupe->applySplit(
						entry: $entry,
						target: (int)$registerId,
						strict: $strict
					);
					$output->writeln($this->describeSplit(entry: $entry, registerId: (int)$registerId, result: $result));
					$split++;
				} catch (Throwable $e) {
					$output->writeln(
						sprintf(
							'  <error>failed</error> register %d / schema %d: %s',
							$registerId,
							$entry['schemaId'],
							$e->getMessage()
						)
					);
					$failed++;
				}//end try
			}
		}//end foreach

		$output->writeln('');
		$output->writeln(sprintf('<info>%d split(s) applied; %d failure(s).</info>', $split, $failed));

		if ($failed === 0) {
			return Command::SUCCESS;
		}

		return Command::FAILURE;
	}//end applyPlan()

	/**
	 * Render one applied split.
	 *
	 * The backup table is named explicitly because it is the operator's only
	 * route back to a column the mapping could not carry across.
	 *
	 * @param array<string, mixed> $entry      The plan entry.
	 * @param int                  $registerId The register that was split off.
	 * @param array<string, mixed> $result     The service's outcome.
	 *
	 * @return string The line to print.
	 */
	private function describeSplit(array $entry, int $registerId, array $result): string {
		$line = sprintf(
			'  <info>split</info> register %d: schema %d -> %d (%d row(s) moved)',
			$registerId,
			$entry['schemaId'],
			$result['newSchemaId'],
			$result['rows']
		);

		if ($result['backup'] !== null) {
			$line .= sprintf(', source kept as %s', $result['backup']);
		}

		if ($result['unmapped'] !== []) {
			$line .= sprintf(
				"\n      <comment>%d column(s) had no destination and stayed in the backup: %s</comment>",
				count($result['unmapped']),
				implode(', ', $result['unmapped'])
			);
		}

		return $line;
	}//end describeSplit()

	/**
	 * Render one shared schema's findings.
	 *
	 * Row counts are printed per split because they separate a split that only
	 * repoints configuration from one that moves live data. The attribution
	 * status is printed verbatim so the operator can see WHY a schema is about to
	 * be attributed the way it is, rather than being handed a verdict.
	 *
	 * @param OutputInterface      $output The console output.
	 * @param array<string, mixed> $entry  One inspect() plan entry.
	 *
	 * @return void
	 */
	private function renderSchema(OutputInterface $output, array $entry): void {
		$output->writeln(
			sprintf(
				'  schema %d (%s) — referenced by registers [%s]',
				$entry['schemaId'],
				$entry['schemaSlug'],
				implode(', ', $entry['registerIds'])
			)
		);

		$output->writeln(sprintf('      attribution: %s%s', $entry['status'], $this->describeOwner(entry: $entry)));

		foreach ($entry['splits'] as $registerId => $split) {
			$output->writeln(
				sprintf(
					'      - register %d (%s) -> new schema from %s (%s, %s)',
					$registerId,
					$split['registerSlug'],
					$split['path'],
					$split['table'],
					$this->describeRows(rows: (int)$split['rows'])
				)
			);

			if ($split['unmapped'] !== []) {
				$output->writeln(
					sprintf(
						'          <comment>%d column(s) would have no destination: %s</comment>',
						count($split['unmapped']),
						implode(', ', $split['unmapped'])
					)
				);
			}
		}//end foreach

		$output->writeln('');
	}//end renderSchema()

	/**
	 * Describe the resolved owner, or the reason there is none.
	 *
	 * @param array<string, mixed> $entry One inspect() plan entry.
	 *
	 * @return string The suffix to print after the status.
	 */
	private function describeOwner(array $entry): string {
		if ($entry['owner'] === null) {
			return ' — <error>UNATTRIBUTED, will be skipped</error>';
		}

		return sprintf(' — owner: register %d (%s)', $entry['owner'], $entry['ownerSource']);
	}//end describeOwner()

	/**
	 * Describe a row count, distinguishing "empty" from "no table".
	 *
	 * @param int $rows The count, or -1 when the table is absent.
	 *
	 * @return string The description.
	 */
	private function describeRows(int $rows): string {
		if ($rows < 0) {
			return 'no table';
		}

		return sprintf('%d row(s)', $rows);
	}//end describeRows()
}//end class
