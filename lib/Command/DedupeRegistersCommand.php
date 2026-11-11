<?php

/**
 * OpenRegister dedupe-registers command
 *
 * Detects and merges duplicate registers that share a (case-insensitive) slug.
 * Environment churn (repeated app re-installs / config re-imports) can leave
 * several `openregister_registers` rows with the same slug, which makes
 * RegisterMapper::find($slug) ambiguous. find() now resolves deterministically
 * (lowest-id wins), but the orphan duplicates linger; this command detects them
 * and merges the empty ones into the canonical (lowest-id) register.
 *
 * A duplicate is only auto-deleted when it owns NO objects in any of its magic
 * tables. Non-empty duplicates are reported and left untouched (unless --force)
 * so data is never silently dropped.
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Detect and merge duplicate registers sharing a slug.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DedupeRegistersCommand extends Command {
	/**
	 * Register lookup/mutation mapper.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema lookup mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Magic table resolver for object counts.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $magicMapper;

	/**
	 * Wire the mappers used by the command.
	 *
	 * @param SchemaCatalogInterface $catalog Register, schema and magic table mappers.
	 *
	 * @return void
	 */
	public function __construct(SchemaCatalogInterface $catalog) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper = $catalog->schemas();
		$this->magicMapper = $catalog->tables();
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:registers:dedupe')
			->setDescription(
				'Detect and merge duplicate registers sharing a slug (keeps the lowest-id canonical register).'
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Actually delete the duplicate registers. Without this flag the command runs in '
				. 'dry-run mode and only reports what it WOULD delete.'
			)
			->addOption(
				'dry-run',
				null,
				InputOption::VALUE_NONE,
				'Report duplicates without deleting anything (this is the default; kept for clarity)'
			)
			->addOption(
				'force',
				null,
				InputOption::VALUE_NONE,
				'Also delete duplicate registers that still own objects (DANGEROUS — drops those objects). '
				. 'Has no effect without --apply.'
			);
	}//end configure()

	/**
	 * Group registers by slug, report duplicates, and delete empty ones.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Default to dry-run: a destructive sweep must be opted into with --apply (OPS-9).
		// --dry-run is accepted as an explicit no-op alias for the default behaviour.
		$apply = (bool)$input->getOption('apply');
		$run = new DedupeRegistersRun(dryRun: ($apply === false), force: (bool)$input->getOption('force'));

		self::announceDryRun(run: $run, output: $output);

		$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);

		foreach ($this->groupBySlug(registers: $registers) as $group) {
			$output->writeln($this->dedupeGroup(group: $group, run: $run));
		}

		if ($run->duplicateGroups === 0) {
			$output->writeln('<info>No duplicate registers found.</info>');
			return Command::SUCCESS;
		}

		$output->writeln(self::doneLine(run: $run));

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Say, on a dry run, that nothing will be deleted.
	 *
	 * @param DedupeRegistersRun $run    The run.
	 * @param OutputInterface    $output The console output.
	 *
	 * @return void
	 */
	private static function announceDryRun(DedupeRegistersRun $run, OutputInterface $output): void {
		if ($run->dryRun === true) {
			$output->writeln(
				'<comment>Running in DRY-RUN mode — no registers will be deleted. '
				. 'Re-run with --apply to perform deletions.</comment>'
			);
		}
	}//end announceDryRun()

	/**
	 * The closing line: the duplicate groups, the deletions and the duplicates kept for holding objects.
	 *
	 * @param DedupeRegistersRun $run The finished run.
	 *
	 * @return string The line.
	 */
	private static function doneLine(DedupeRegistersRun $run): string {
		$suffix = '';
		if ($run->dryRun === true) {
			$suffix = ' (dry run — no writes performed)';
		}

		return sprintf(
			'<info>Done. Duplicate slug groups=%d, deleted=%d, kept-non-empty=%d%s</info>',
			$run->duplicateGroups,
			$run->deleted,
			$run->keptNonEmpty,
			$suffix
		);
	}//end doneLine()

	/**
	 * Group registers by lowercased slug, ignoring registers without one.
	 *
	 * @param iterable<Register> $registers Every register.
	 *
	 * @return array<string, array<int, Register>> Registers per lowercased slug, in input order.
	 */
	private function groupBySlug(iterable $registers): array {
		$bySlug = [];
		foreach ($registers as $register) {
			$slug = $register->getSlug();
			if ($slug === null || $slug === '') {
				continue;
			}

			$bySlug[strtolower($slug)][] = $register;
		}

		return $bySlug;
	}//end groupBySlug()

	/**
	 * Keep the lowest-id register of a slug group and handle every other one.
	 *
	 * @param array<int, Register> $group The registers sharing one lowercased slug.
	 * @param DedupeRegistersRun   $run   The run's flags and tally.
	 *
	 * @return array<int, string> The lines to print, in order.
	 */
	private function dedupeGroup(array $group, DedupeRegistersRun $run): array {
		if (count($group) < 2) {
			return [];
		}

		$run->duplicateGroups++;

		// Sort by id ASC: lowest-id is canonical (matches find() resolution).
		usort($group, static fn (Register $a, Register $b) => ((int)$a->getId() <=> (int)$b->getId()));
		$canonical = array_shift($group);

		$lines = [
			sprintf(
				'<info>Slug "%s": keeping canonical register id=%d, %d duplicate(s)</info>',
				strtolower((string)$canonical->getSlug()),
				(int)$canonical->getId(),
				count($group)
			),
		];

		foreach ($group as $duplicate) {
			$lines[] = $this->dedupeOne(duplicate: $duplicate, run: $run);
		}

		return $lines;
	}//end dedupeGroup()

	/**
	 * Apply the object guard to one duplicate, then delete it unless this is a dry run.
	 *
	 * @param Register           $duplicate The duplicate register.
	 * @param DedupeRegistersRun $run       The run's flags and tally.
	 *
	 * @return string The line reporting what happened to it.
	 */
	private function dedupeOne(Register $duplicate, DedupeRegistersRun $run): string {
		$objectCount = $this->countObjectsForRegister(register: $duplicate);
		$id = (int)$duplicate->getId();

		if ($objectCount > 0 && $run->force === false) {
			$run->keptNonEmpty++;
			return sprintf(
				'  <comment>SKIP duplicate id=%d (owns %d object(s) — re-run with --force to drop, or merge manually)</comment>',
				$id,
				$objectCount
			);
		}

		if ($run->dryRun === true) {
			return sprintf('  <comment>WOULD DELETE duplicate id=%d (objects=%d)</comment>', $id, $objectCount);
		}

		$refusal = $this->deleteDuplicate(duplicate: $duplicate, run: $run);
		if ($refusal !== null) {
			return sprintf('  <comment>SKIP duplicate id=%d (delete refused: %s)</comment>', $id, $refusal);
		}

		return sprintf('  <info>DELETED duplicate id=%d (objects=%d)</info>', $id, $objectCount);
	}//end dedupeOne()

	/**
	 * Delete one duplicate, keeping it when the mapper's own guard refuses.
	 *
	 * @param Register           $duplicate The duplicate register.
	 * @param DedupeRegistersRun $run       The run's tally.
	 *
	 * @return string|null The refusal message, or null when it was deleted.
	 */
	private function deleteDuplicate(Register $duplicate, DedupeRegistersRun $run): ?string {
		try {
			$this->registerMapper->delete($duplicate);
		} catch (\Throwable $e) {
			// RegisterMapper::delete() applies its own attached-object
			// guard. If it refuses, keep the duplicate rather than
			// aborting the whole sweep.
			$run->keptNonEmpty++;
			return $e->getMessage();
		}

		$run->deleted++;
		return null;
	}//end deleteDuplicate()

	/**
	 * Count objects owned by a register across all its magic tables.
	 *
	 * @param Register $register The register to count objects for.
	 *
	 * @return int Total object count across the register's magic tables.
	 */
	private function countObjectsForRegister(Register $register): int {
		$total = 0;

		foreach ($register->getSchemas() as $schemaRef) {
			try {
				$schema = $this->schemaMapper->find($schemaRef, _multitenancy: false);
			} catch (\Throwable $e) {
				continue;
			}

			if ($this->magicMapper->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
				continue;
			}

			try {
				$total += $this->magicMapper->countObjectsInRegisterSchemaTable(
					query: [],
					register: $register,
					schema: $schema
				);
			} catch (\Throwable $e) {
				continue;
			}
		}//end foreach

		return $total;
	}//end countObjectsForRegister()
}//end class
