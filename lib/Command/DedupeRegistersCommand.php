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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
	 * Wire the mappers used by the command.
	 *
	 * @param RegisterMapper $registerMapper Register lookup/mutation mapper.
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param MagicMapper $magicMapper Magic table resolver for object counts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $magicMapper,
	) {
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Default to dry-run: a destructive sweep must be opted into with --apply (OPS-9).
		// --dry-run is accepted as an explicit no-op alias for the default behaviour.
		$apply = (bool)$input->getOption('apply');
		$dryRun = ($apply === false);
		$force = (bool)$input->getOption('force');

		if ($dryRun === true) {
			$output->writeln(
				'<comment>Running in DRY-RUN mode — no registers will be deleted. '
				. 'Re-run with --apply to perform deletions.</comment>'
			);
		}

		$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);

		// Group by lowercased slug.
		$bySlug = [];
		foreach ($registers as $register) {
			$slug = $register->getSlug();
			if ($slug === null || $slug === '') {
				continue;
			}

			$bySlug[strtolower($slug)][] = $register;
		}

		$duplicateGroups = 0;
		$deleted = 0;
		$keptNonEmpty = 0;

		foreach ($bySlug as $slug => $group) {
			if (count($group) < 2) {
				continue;
			}

			$duplicateGroups++;

			// Sort by id ASC: lowest-id is canonical (matches find() resolution).
			usort($group, static fn (Register $a, Register $b) => ((int)$a->getId() <=> (int)$b->getId()));
			$canonical = array_shift($group);

			$output->writeln(
				sprintf(
					'<info>Slug "%s": keeping canonical register id=%d, %d duplicate(s)</info>',
					$slug,
					(int)$canonical->getId(),
					count($group)
				)
			);

			foreach ($group as $duplicate) {
				$objectCount = $this->countObjectsForRegister(register: $duplicate);

				if ($objectCount > 0 && $force === false) {
					$keptNonEmpty++;
					$output->writeln(
						sprintf(
							'  <comment>SKIP duplicate id=%d (owns %d object(s) — re-run with --force to drop, or merge manually)</comment>',
							(int)$duplicate->getId(),
							$objectCount
						)
					);
					continue;
				}

				if ($dryRun === true) {
					$output->writeln(
						sprintf(
							'  <comment>WOULD DELETE duplicate id=%d (objects=%d)</comment>',
							(int)$duplicate->getId(),
							$objectCount
						)
					);
					continue;
				}

				try {
					$this->registerMapper->delete($duplicate);
				} catch (\Throwable $e) {
					// RegisterMapper::delete() applies its own attached-object
					// guard. If it refuses, keep the duplicate rather than
					// aborting the whole sweep.
					$keptNonEmpty++;
					$output->writeln(
						sprintf(
							'  <comment>SKIP duplicate id=%d (delete refused: %s)</comment>',
							(int)$duplicate->getId(),
							$e->getMessage()
						)
					);
					continue;
				}

				$deleted++;
				$output->writeln(
					sprintf(
						'  <info>DELETED duplicate id=%d (objects=%d)</info>',
						(int)$duplicate->getId(),
						$objectCount
					)
				);
			}//end foreach
		}//end foreach

		if ($duplicateGroups === 0) {
			$output->writeln('<info>No duplicate registers found.</info>');
			return Command::SUCCESS;
		}

		$suffix = '';
		if ($dryRun === true) {
			$suffix = ' (dry run — no writes performed)';
		}

		$output->writeln(
			sprintf(
				'<info>Done. Duplicate slug groups=%d, deleted=%d, kept-non-empty=%d%s</info>',
				$duplicateGroups,
				$deleted,
				$keptNonEmpty,
				$suffix
			)
		);

		return Command::SUCCESS;
	}//end execute()

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
