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
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:backfill-slugs')
			->setDescription(
				'Fill missing object slugs of one register and schema from the schema\'s objectSlugField; '
				. 'existing slugs and duplicates are reported, not changed.'
			)
			->addArgument('register', InputArgument::REQUIRED, 'Register (id, uuid or slug)')
			->addArgument('schema', InputArgument::REQUIRED, 'Schema in that register (id, uuid or slug)')
			->addOption(
				'reslug',
				null,
				InputOption::VALUE_NONE,
				'Recompute every slug from the current objectSlugField (after switching the field); '
				. 'objects with an empty source keep their slug'
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
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$reslug = (bool)$input->getOption('reslug');
		$dryRun = (bool)$input->getOption('dry-run');

		try {
			$register = $this->registerMapper->find((string)$input->getArgument('register'), _rbac: false, _multitenancy: false);
			$schema = $this->schemaMapper->find((string)$input->getArgument('schema'), _rbac: false, _multitenancy: false);
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

		$summary = $result['summary'];
		$output->writeln(
			sprintf(
				'<info>scanned=%d assigned=%d changed=%d collisions=%d unchanged=%d duplicates=%d%s</info>',
				$summary['scanned'],
				$summary['assigned'],
				$summary['changed'],
				$summary['collisions'],
				$summary['unchanged'],
				$summary['duplicates'],
				$dryRun === true ? ' (dry run — nothing written)' : ''
			)
		);

		return Command::SUCCESS;
	}//end execute()
}//end class
