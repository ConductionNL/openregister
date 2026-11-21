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

use OCA\OpenRegister\Service\SharedSchemaDedupeServiceInterface;
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
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
class DedupeSharedSchemasCommand extends Command {

	/**
	 * The options after --write and --register, in declaration order: name => [mode, description].
	 */
	private const SPLIT_OPTIONS = [
		'keep' => [
			(InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
			'Name the owner of a schema attribution could not settle: '
			. '<schemaId>:<registerId>, or a bare <registerId> for all of them. Repeatable.',
		],
		'strict' => [
			InputOption::VALUE_NONE,
			'Refuse any split whose source table has a column with no destination.',
		],
	];

	/**
	 * Wire the dedupe service.
	 *
	 * @param SharedSchemaDedupeServiceInterface $dedupe The shared-schema repair service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SharedSchemaDedupeServiceInterface $dedupe,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:registers:dedupe-shared-schemas')
			->setDescription(
				'Split schema entities shared by several registers so each owns its own (dry run by default)'
			);
		RegisterRepairOptions::declareOn(
			command: $this,
			registerDescription: 'Limit to shared schemas involving this register id.'
		);
		foreach (self::SPLIT_OPTIONS as $name => [$mode, $description]) {
			$this->addOption(name: $name, shortcut: null, mode: $mode, description: $description);
		}
	}//end configure()

	/**
	 * Run the inspection, and the repair when --write is given.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$plan = $this->planFor(input: $input, output: $output);
		if ($plan === null) {
			return Command::INVALID;
		}

		if ($plan === []) {
			$output->writeln('<info>No schema is shared by more than one register. Nothing to do.</info>');
			return Command::SUCCESS;
		}

		$this->renderPlan(output: $output, plan: $plan);

		if ((bool)$input->getOption('write') === false) {
			return $this->reportDryRun(output: $output, unattributed: self::countUnattributed(plan: $plan));
		}

		return $this->writePlan(run: new SharedSchemaSplitRun(output: $output, strict: (bool)$input->getOption('strict')), plan: $plan);
	}//end execute()

	/**
	 * Inspect the shared schemas for the operator's --register and --keep.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return array<int, array<string, mixed>>|null The plan, or null after printing why --keep cannot be parsed.
	 */
	private function planFor(InputInterface $input, OutputInterface $output): ?array {
		try {
			$keep = $this->dedupe->parseKeep(raw: (array)$input->getOption('keep'));
		} catch (RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return null;
		}

		return $this->dedupe->inspect(registerId: RegisterRepairOptions::registerId(input: $input), keep: $keep);
	}//end planFor()

	/**
	 * Execute the plan, or refuse while a schema has no owner.
	 *
	 * @param SharedSchemaSplitRun             $run  The split pass.
	 * @param array<int, array<string, mixed>> $plan The inspection plan.
	 *
	 * @return int The exit code.
	 */
	private function writePlan(SharedSchemaSplitRun $run, array $plan): int {
		$unattributed = self::countUnattributed(plan: $plan);
		if ($unattributed > 0) {
			return $this->refuseUnattributed(output: $run->output, unattributed: $unattributed);
		}

		return $this->applyPlan(run: $run, plan: $plan);
	}//end writePlan()

	/**
	 * Refuse to write while a schema has no owner.
	 *
	 * @param OutputInterface $output       The console output.
	 * @param int             $unattributed How many schemas could not be attributed.
	 *
	 * @return int The exit code.
	 */
	private function refuseUnattributed(OutputInterface $output, int $unattributed): int {
		$output->writeln('');
		$output->writeln(
			sprintf(
				'<error>Refusing to write: %d schema(s) are unattributed. '
				. 'Name their owner with --keep <schemaId>:<registerId>.</error>',
				$unattributed
			)
		);
		return Command::FAILURE;
	}//end refuseUnattributed()

	/**
	 * Print how many schemas are shared, then render every plan entry.
	 *
	 * @param OutputInterface                  $output The console.
	 * @param array<int, array<string, mixed>> $plan   The dedupe plan.
	 *
	 * @return void
	 */
	private function renderPlan(OutputInterface $output, array $plan): void {
		$output->writeln(
			sprintf('<comment>%d schema(s) are shared by more than one register:</comment>', count($plan))
		);
		$output->writeln('');

		foreach ($plan as $entry) {
			$this->renderSchema(output: $output, entry: $entry);
		}
	}//end renderPlan()

	/**
	 * Count the plan entries without an owner.
	 *
	 * @param array<int, array<string, mixed>> $plan The dedupe plan.
	 *
	 * @return int The number of entries without an owner.
	 */
	private static function countUnattributed(array $plan): int {
		$unattributed = 0;
		foreach ($plan as $entry) {
			if ($entry['owner'] === null) {
				$unattributed++;
			}
		}

		return $unattributed;
	}//end countUnattributed()

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

		RegisterRepairOptions::writeDryRunNotice(output: $output);

		return Command::SUCCESS;
	}//end reportDryRun()

	/**
	 * Execute every planned split.
	 *
	 * @param SharedSchemaSplitRun             $run  The split pass.
	 * @param array<int, array<string, mixed>> $plan The inspection plan.
	 *
	 * @return int The exit code.
	 */
	private function applyPlan(SharedSchemaSplitRun $run, array $plan): int {
		$run->output->writeln('');
		foreach ($plan as $entry) {
			foreach (array_keys($entry['splits']) as $registerId) {
				$this->applySplit(run: $run, split: new PlannedSchemaSplit(entry: $entry, registerId: (int)$registerId));
			}
		}

		return $this->reportSplits(run: $run);
	}//end applyPlan()

	/**
	 * Apply one split and print its outcome; a failure is counted, not thrown.
	 *
	 * @param SharedSchemaSplitRun $run   The split pass.
	 * @param PlannedSchemaSplit   $split The register to split off.
	 *
	 * @return void
	 */
	private function applySplit(SharedSchemaSplitRun $run, PlannedSchemaSplit $split): void {
		try {
			$result = $this->split(run: $run, split: $split);
			$run->output->writeln($this->describeSplit(split: $split, result: $result));
			$run->split++;
		} catch (Throwable $e) {
			$run->output->writeln(self::describeFailure(split: $split, error: $e));
			$run->failed++;
		}
	}//end applySplit()

	/**
	 * Describe a split that failed.
	 *
	 * @param PlannedSchemaSplit $split The register that was to be split off.
	 * @param Throwable          $error Why it failed.
	 *
	 * @return string The line to print.
	 */
	private static function describeFailure(PlannedSchemaSplit $split, Throwable $error): string {
		return sprintf(
			'  <error>failed</error> register %d / schema %d: %s',
			$split->registerId,
			$split->entry['schemaId'],
			$error->getMessage()
		);
	}//end describeFailure()

	/**
	 * Split one register off, strictly when the run asks for it.
	 *
	 * @param SharedSchemaSplitRun $run   The split pass.
	 * @param PlannedSchemaSplit   $split The register to split off.
	 *
	 * @return array<string, mixed> The service's outcome.
	 */
	private function split(SharedSchemaSplitRun $run, PlannedSchemaSplit $split): array {
		if ($run->strict === true) {
			return $this->dedupe->applySplitStrictly(entry: $split->entry, target: $split->registerId);
		}

		return $this->dedupe->applySplit(entry: $split->entry, target: $split->registerId);
	}//end split()

	/**
	 * Print the totals of the split pass.
	 *
	 * @param SharedSchemaSplitRun $run The split pass.
	 *
	 * @return int The exit code.
	 */
	private function reportSplits(SharedSchemaSplitRun $run): int {
		$run->output->writeln('');
		$run->output->writeln(sprintf('<info>%d split(s) applied; %d failure(s).</info>', $run->split, $run->failed));

		if ($run->failed === 0) {
			return Command::SUCCESS;
		}

		return Command::FAILURE;
	}//end reportSplits()

	/**
	 * Render one applied split.
	 *
	 * The backup table is named explicitly because it is the operator's only
	 * route back to a column the mapping could not carry across.
	 *
	 * @param PlannedSchemaSplit   $split  The register that was split off.
	 * @param array<string, mixed> $result The service's outcome.
	 *
	 * @return string The line to print.
	 */
	private function describeSplit(PlannedSchemaSplit $split, array $result): string {
		$line = sprintf(
			'  <info>split</info> register %d: schema %d -> %d (%d row(s) moved)',
			$split->registerId,
			$split->entry['schemaId'],
			$result['newSchemaId'],
			$result['rows']
		);

		return $line . self::describeLeftovers(result: $result);
	}//end describeSplit()

	/**
	 * Describe what a split left behind: the backup table and the columns that stayed in it.
	 *
	 * @param array<string, mixed> $result The service's outcome.
	 *
	 * @return string The suffix for the split line; empty when nothing was left behind.
	 */
	private static function describeLeftovers(array $result): string {
		$suffix = '';
		if ($result['backup'] !== null) {
			$suffix .= sprintf(', source kept as %s', $result['backup']);
		}

		if ($result['unmapped'] !== []) {
			$suffix .= sprintf(
				"\n      <comment>%d column(s) had no destination and stayed in the backup: %s</comment>",
				count($result['unmapped']),
				implode(', ', $result['unmapped'])
			);
		}

		return $suffix;
	}//end describeLeftovers()

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
		$output->writeln($this->describeSchema(entry: $entry));

		$output->writeln(sprintf('      attribution: %s%s', $entry['status'], $this->describeOwner(entry: $entry)));

		foreach ($entry['splits'] as $registerId => $split) {
			$output->writeln($this->describePlannedSplit(registerId: (int)$registerId, split: $split));
			$this->renderUnmapped(output: $output, unmapped: $split['unmapped']);
		}

		$output->writeln('');
	}//end renderSchema()

	/**
	 * Describe a shared schema and the registers that reference it.
	 *
	 * @param array<string, mixed> $entry One inspect() plan entry.
	 *
	 * @return string The line to print.
	 */
	private function describeSchema(array $entry): string {
		return sprintf(
			'  schema %d (%s) — referenced by registers [%s]',
			$entry['schemaId'],
			$entry['schemaSlug'],
			implode(', ', $entry['registerIds'])
		);
	}//end describeSchema()

	/**
	 * Describe one planned split: the register, the new schema's source and its table.
	 *
	 * @param int                  $registerId The register to split off.
	 * @param array<string, mixed> $split      The plan's split for that register.
	 *
	 * @return string The line to print.
	 */
	private function describePlannedSplit(int $registerId, array $split): string {
		return sprintf(
			'      - register %d (%s) -> new schema from %s (%s, %s)',
			$registerId,
			$split['registerSlug'],
			$split['path'],
			$split['table'],
			$this->describeRows(rows: (int)$split['rows'])
		);
	}//end describePlannedSplit()

	/**
	 * Name the source columns a planned split would leave without a destination.
	 *
	 * @param OutputInterface $output   The console output.
	 * @param array<string>   $unmapped The columns with no destination.
	 *
	 * @return void
	 */
	private function renderUnmapped(OutputInterface $output, array $unmapped): void {
		if ($unmapped === []) {
			return;
		}

		$output->writeln(
			sprintf(
				'          <comment>%d column(s) would have no destination: %s</comment>',
				count($unmapped),
				implode(', ', $unmapped)
			)
		);
	}//end renderUnmapped()

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
