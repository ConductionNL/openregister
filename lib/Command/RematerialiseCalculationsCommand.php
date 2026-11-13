<?php

/**
 * OpenRegister rematerialise-calculations command
 *
 * Re-evaluates every materialised calculation declared on a schema and
 * rewrites the persisted value. Used after a schema's calculation
 * expression changes so existing objects reflect the new shape without
 * waiting for the next user-driven save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Command\Support\RematerialiseEvaluation;
use OCA\OpenRegister\Command\Support\RematerialiseStorage;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\Calculation\CalculationRematerialiser;
use OCA\OpenRegister\Service\Calculation\RecomputeSelection;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-evaluate every materialised calculation declared on a (register, schema).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RematerialiseCalculationsCommand extends Command {
	/**
	 * Re-derives the rows; the endpoint uses the same service.
	 *
	 * @var CalculationRematerialiser
	 */
	private readonly CalculationRematerialiser $rematerialiser;

	/**
	 * Wire the mappers, evaluator, and object service used by the command.
	 *
	 * @param RematerialiseStorage    $storage    Register, schema and object stores.
	 * @param RematerialiseEvaluation $evaluation Evaluator and reference pre-resolvers.
	 * @param SchemaTypeConverterInterface $types      Restores the stored form of `type: string` values the read decoded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
	 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-2
	 */
	public function __construct(
		private readonly RematerialiseStorage $storage,
		RematerialiseEvaluation $evaluation,
		SchemaTypeConverterInterface $types,
	) {
		parent::__construct();
		$this->rematerialiser = new CalculationRematerialiser(storage: $storage, evaluation: $evaluation, types: $types);
	}//end __construct()

	/**
	 * Define command name, description, and arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:rematerialise-calculations')
			->setDescription(
				'Re-evaluate every materialised calculation on objects in a (register, schema) and persist the result.'
			)
			->addArgument('register', InputArgument::REQUIRED, 'Register slug, uuid or id')
			->addArgument('schema', InputArgument::REQUIRED, 'Schema slug, uuid or id')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report changes without saving');
	}//end configure()

	/**
	 * Iterate every object and re-materialise all declared calculations.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$register = $this->storage->registerMapper->find((string)$input->getArgument('register'), _multitenancy: false);
			$schema = $this->storage->schemaMapper->find((string)$input->getArgument('schema'), _multitenancy: false);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$run = new RematerialiseRun(table: new RegisterSchema(register: $register, schema: $schema), output: $output);
		$run->dryRun = (bool)$input->getOption('dry-run');
		if ($run->calculations === []) {
			$output->writeln('<comment>Schema declares no x-openregister-calculations — nothing to do.</comment>');
			return Command::SUCCESS;
		}

		if ($run->materialised === []) {
			$output->writeln('<comment>No materialised calculations declared — nothing to do.</comment>');
			return Command::SUCCESS;
		}

		$this->announce(run: $run);
		$table = new RegisterSchema(register: $register, schema: $schema);
		$this->rematerialiser->rematerialise(
			run: $run,
			entities: $this->rematerialiser->select(table: $table, selection: RecomputeSelection::all())
		);

		$output->writeln(
			sprintf('<info>Touched %d, unchanged %d, failed %d</info>', $run->touched, $run->unchanged, $run->failed)
		);
		return $run->exitCode();
	}//end execute()

	/**
	 * Say how many calculations the run re-evaluates, and on what.
	 *
	 * @param RematerialiseRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function announce(RematerialiseRun $run): void {
		$dryRunLabel = '';
		if ($run->dryRun === true) {
			$dryRunLabel = ' (dry run)';
		}

		$run->output->writeln(
			sprintf(
				'<info>Rematerialising %d calculation(s) on %s/%s%s</info>',
				count($run->materialised),
				$run->register->getSlug() ?? $run->register->getId(),
				$run->schema->getSlug() ?? $run->schema->getId(),
				$dryRunLabel
			)
		);
	}//end announce()

}//end class
