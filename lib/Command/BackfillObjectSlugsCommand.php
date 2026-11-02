<?php

/**
 * OpenRegister backfill-slugs command
 *
 * Fills object slugs for the existing objects of one register and schema
 * that have none yet (#4037). Idempotent: a second run changes nothing.
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
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\SlugBackfillWriteException;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ openregister:backfill-slugs <register> <schema> [--dry-run]`.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class BackfillObjectSlugsCommand extends Command {
	/**
	 * Wire the mappers and the backfill service.
	 *
	 * @param RegisterMapper            $registerMapper Resolves the register argument.
	 * @param SchemaMapper              $schemaMapper   Resolves the schema argument.
	 * @param ObjectSlugBackfillService $backfill       Plans and writes the slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectSlugBackfillService $backfill,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, arguments and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:backfill-slugs')
			->setDescription(
				'Fill missing object slugs of one register and schema from the schema\'s objectSlugField; '
				. 'existing slugs are never changed (also not after switching the slug field) and duplicates are reported, not changed. '
				. 'No per-object version or event is written; a run that changes slugs writes one audit-trail entry that lists them. '
				. 'Run with --dry-run first'
			)
			->addArgument('register', InputArgument::REQUIRED, 'Register (id, uuid or slug)')
			->addArgument('schema', InputArgument::REQUIRED, 'Schema in that register (id, uuid or slug)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan without writing anything');
	}//end configure()

	/**
	 * Run the backfill and print the duplicate report, the plan and a summary.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int 0 on success (also when duplicates were reported), 1 on unresolved input,
	 *             a schema without objectSlugField or a failing write (the applied count is printed).
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');

		try {
			$register = $this->registerMapper->find((string)$input->getArgument('register'), _rbac: false, _multitenancy: false);
			$schemaArg = (string)$input->getArgument('schema');
			// A schema slug can be shared by several schemas (other organisations,
			// other apps); resolve it among the register's own schemas first.
			$schema = null;
			if (is_numeric($schemaArg) === false) {
				$schema = $this->schemaMapper->findBySlugInIds(slug: $schemaArg, schemaIds: $register->getSchemas() ?? []);
			}

			$schema ??= $this->schemaMapper->find($schemaArg, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$output->writeln('<error>Register or schema not found: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$inRegister = array_map('intval', $register->getSchemas() ?? []);
		if (in_array((int)$schema->getId(), $inRegister, true) === false) {
			$output->writeln(
				sprintf(
					'<error>Schema %s is not in register %s</error>',
					(string)($schema->getSlug() ?? $schema->getId()),
					(string)($register->getSlug() ?? $register->getId())
				)
			);
			return Command::FAILURE;
		}

		$result = $this->runOrReport(register: $register, schema: $schema, dryRun: $dryRun, output: $output);
		if ($result === null) {
			return Command::FAILURE;
		}

		foreach ($result['duplicates'] as $slug => $uuids) {
			$output->writeln(sprintf('<comment>duplicate "%s": %s</comment>', $slug, implode(', ', $uuids)));
		}

		foreach ($result['plan'] as $step) {
			$output->writeln(
				sprintf(
					'%s: "%s" -> "%s"',
					$step['uuid'],
					(string)($step['old'] ?? ''),
					(string)($step['new'] ?? '')
				)
			);
		}

		$summary = $result['summary'];
		$dryRunNote = '';
		if ($dryRun === true) {
			$dryRunNote = ' (dry run — nothing written)';
		}

		$output->writeln(
			sprintf(
				'<info>scanned=%d assigned=%d collisions=%d unchanged=%d duplicates=%d skipped=%d%s</info>',
				$summary['scanned'],
				$summary['assigned'],
				$summary['collisions'],
				$summary['unchanged'],
				$summary['duplicates'],
				$summary['skipped'],
				$dryRunNote
			)
		);

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Run the backfill; print the reason and answer null when it cannot finish.
	 *
	 * @param Register        $register The register.
	 * @param Schema          $schema   The schema.
	 * @param bool            $dryRun   Whether to write nothing.
	 * @param OutputInterface $output   Console output stream.
	 *
	 * @return array|null The result of the run, or null after an error was printed.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `dryRun` is the documented command option.
	 */
	private function runOrReport(Register $register, Schema $schema, bool $dryRun, OutputInterface $output): ?array {
		try {
			return $this->backfill->run(register: $register, schema: $schema, dryRun: $dryRun);
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
		} catch (SlugBackfillWriteException $e) {
			// The chunks before the failing one stay written, are audited and evicted.
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			$output->writeln(
				sprintf(
					'<error>applied=%d skipped=%d; run the command again to continue, it only touches what is left</error>',
					$e->getApplied(),
					$e->getSkipped()
				)
			);
		} catch (Throwable $e) {
			$output->writeln('<error>The slug backfill failed: ' . $e->getMessage() . '</error>');
		}

		return null;
	}//end runOrReport()
}//end class
