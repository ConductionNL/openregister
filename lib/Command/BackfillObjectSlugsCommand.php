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
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use InvalidArgumentException;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Exception\SlugBackfillWriteException;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ openregister:backfill-slugs <register> <schema> [--dry-run]`.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class BackfillObjectSlugsCommand extends Command {
	/**
	 * Resolves the register argument.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Resolves the schema argument.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Wire the mappers and the backfill service.
	 *
	 * @param SchemaCatalogInterface             $catalog  Resolves the register and the schema argument.
	 * @param ObjectSlugBackfillServiceInterface $backfill Plans and writes the slugs.
	 *
	 * @return void
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		private readonly ObjectSlugBackfillServiceInterface $backfill,
	) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper = $catalog->schemas();
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, arguments and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
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

		try {
			$result = $this->backfill->run(register: $register, schema: $schema, dryRun: $dryRun);
		} catch (Throwable $e) {
			self::reportFailure(error: $e, output: $output);
			return Command::FAILURE;
		}

		$this->printResult(result: $result, output: $output);
		$output->writeln(self::summaryLine(summary: $result['summary'], dryRun: $dryRun));

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Print the duplicates and the planned or written changes of a run.
	 *
	 * @param array           $result The run's duplicates, plan and summary.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return void
	 */
	private function printResult(array $result, OutputInterface $output): void {
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
	}//end printResult()

	/**
	 * The closing summary line of a run.
	 *
	 * @param array $summary The run's counts.
	 * @param bool  $dryRun  Whether nothing was written.
	 *
	 * @return string The line to print.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `dryRun` is the documented command option.
	 */
	private static function summaryLine(array $summary, bool $dryRun): string {
		$dryRunNote = '';
		if ($dryRun === true) {
			$dryRunNote = ' (dry run — nothing written)';
		}

		return sprintf(
			'<info>scanned=%d assigned=%d collisions=%d unchanged=%d duplicates=%d skipped=%d%s</info>',
			$summary['scanned'],
			$summary['assigned'],
			$summary['collisions'],
			$summary['unchanged'],
			$summary['duplicates'],
			$summary['skipped'],
			$dryRunNote
		);
	}//end summaryLine()

	/**
	 * Print why the backfill could not finish.
	 *
	 * @param Throwable       $error  What the backfill threw.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return void
	 */
	private static function reportFailure(Throwable $error, OutputInterface $output): void {
		if ($error instanceof InvalidArgumentException) {
			$output->writeln('<error>' . $error->getMessage() . '</error>');
			return;
		}

		if ($error instanceof SlugBackfillWriteException) {
			// The chunks before the failing one stay written, are audited and evicted.
			$output->writeln('<error>' . $error->getMessage() . '</error>');
			$output->writeln(
				sprintf(
					'<error>applied=%d skipped=%d; run the command again to continue, it only touches what is left</error>',
					$error->getApplied(),
					$error->getSkipped()
				)
			);
			return;
		}

		$output->writeln('<error>The slug backfill failed: ' . $error->getMessage() . '</error>');
	}//end reportFailure()
}//end class
