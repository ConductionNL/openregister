<?php

/**
 * OpenRegister backfill-slugs command
 *
 * Fills object slugs for the existing objects of one register and schema,
 * or recomputes them after the slug field was switched (#4037). Idempotent:
 * a second run in the same mode changes nothing.
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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ openregister:backfill-slugs <register> <schema> [--reslug] [--dry-run]`.
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
				. 'by default existing slugs are left alone and duplicates are reported, not changed. '
				. 'No per-object version or event is written; a run that changes slugs writes one audit-trail entry that lists them. '
				. 'With --reslug existing slugs ARE rewritten, and links built on the old slugs stop working.'
			)
			->addArgument('register', InputArgument::REQUIRED, 'Register (id, uuid or slug)')
			->addArgument('schema', InputArgument::REQUIRED, 'Schema in that register (id, uuid or slug)')
			->addOption(
				'reslug',
				null,
				InputOption::VALUE_NONE,
				'Recompute every slug from the current objectSlugField (after switching the field); '
				. 'objects with an empty source keep their slug. This is the only way a set slug changes: '
				. 'links, bookmarks and external references that use an old slug stop working. '
				. 'An object whose new slug another object already holds keeps its current slug (with '
				. 'objectSlugSuffix it gets the suffixed slug) and is reported as kept. A run that is not a '
				. 'dry run and changes at least one slug writes one audit-trail entry listing the old and new slug of the changed objects. '
				. 'Run with --dry-run first'
			)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan without writing anything');
	}//end configure()

	/**
	 * Run the backfill and print the duplicate report, the plan and a summary.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int 0 on success (also when duplicates were reported), 1 on unresolved input
	 *             or a schema without objectSlugField.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$reslug = (bool)$input->getOption('reslug');
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
			$result = $this->backfill->run(register: $register, schema: $schema, reslug: $reslug, dryRun: $dryRun);
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
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

		foreach ($result['kept'] as $kept) {
			$output->writeln(
				sprintf(
					'<comment>kept %s: "%s" (wanted "%s", held by %s)</comment>',
					$kept['uuid'],
					$kept['slug'],
					$kept['wanted'],
					$kept['holder']
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
				'<info>scanned=%d assigned=%d changed=%d collisions=%d kept=%d unchanged=%d duplicates=%d skipped=%d%s</info>',
				$summary['scanned'],
				$summary['assigned'],
				$summary['changed'],
				$summary['collisions'],
				$summary['kept'],
				$summary['unchanged'],
				$summary['duplicates'],
				$summary['skipped'],
				$dryRunNote
			)
		);

		return Command::SUCCESS;
	}//end execute()
}//end class
