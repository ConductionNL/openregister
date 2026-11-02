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

use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\ObjectService;
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
	 * Wire the mappers, evaluator, and object service used by the command.
	 *
	 * @param RegisterMapper $registerMapper Register lookup mapper.
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param MagicMapper $magicMapper Magic table mapper for objects.
	 * @param ObjectService $objectService Object persistence service.
	 * @param CalculationEvaluator $evaluator Expression evaluator.
	 * @param ReferenceResolver $references Cross-object reference pre-resolver.
	 * @param AggregateReferenceResolver $aggregates Aggregate-reference pre-resolver.
	 * @param SchemaTypeConverter $types Restores the stored form of `type: string` values the read decoded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
	 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-2
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $magicMapper,
		private readonly ObjectService $objectService,
		private readonly CalculationEvaluator $evaluator,
		private readonly ReferenceResolver $references,
		private readonly AggregateReferenceResolver $aggregates,
		private readonly SchemaTypeConverter $types=new SchemaTypeConverter(),
	) {
		parent::__construct();
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
			$register = $this->registerMapper->find((string)$input->getArgument('register'), _multitenancy: false);
			$schema = $this->schemaMapper->find((string)$input->getArgument('schema'), _multitenancy: false);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$run = new RematerialiseRun(register: $register, schema: $schema, output: $output);
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
		$entities = $this->magicMapper->findAllInRegisterSchemaTable(
			register: $register,
			schema: $schema,
			limit: 100000
		);
		foreach ($entities as $entity) {
			$this->rematerialiseRow(run: $run, entity: $entity);
		}

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

	/**
	 * Re-evaluate one row's materialised calculations and save it when a value changed.
	 *
	 * @param RematerialiseRun                  $run    The run.
	 * @param \OCA\OpenRegister\Db\ObjectEntity $entity The row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function rematerialiseRow(RematerialiseRun $run, \OCA\OpenRegister\Db\ObjectEntity $entity): void {
		$payload = $this->evaluationPayload(run: $run, entity: $entity);
		if ($payload === null) {
			return;
		}

		$row = ['entity' => $entity, 'data' => ($entity->getObject() ?? []), 'payload' => $payload];
		$row['expected'] = $this->changedValues(run: $run, row: $row);
		if ($row['expected'] === []) {
			$run->unchanged++;
			return;
		}

		if ($run->dryRun === true) {
			$run->touched++;
			return;
		}

		$this->saveChangedRow(run: $run, row: $row);
	}//end rematerialiseRow()

	/**
	 * Build the payload the calculations evaluate: the row with `@self`, `@ref` and `@aggregate`.
	 *
	 * Declared cross-object references and aggregate-references are pre-resolved
	 * per object so the recompute path refreshes them the same way the save path does.
	 * An aggregate that could not be resolved is not a null to compare: the row's
	 * value is unknown, so the row FAILED and no payload is returned.
	 *
	 * @param RematerialiseRun                  $run    The run.
	 * @param \OCA\OpenRegister\Db\ObjectEntity $entity The row.
	 *
	 * @return array<string, mixed>|null The payload, or null when an aggregate failed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function evaluationPayload(RematerialiseRun $run, \OCA\OpenRegister\Db\ObjectEntity $entity): ?array {
		$payload = $this->withSelf(data: ($entity->getObject() ?? []), entity: $entity);
		if ($run->referenceSpecs !== null) {
			$payload['@ref'] = $this->references->resolveAll(
				payload: $payload,
				references: $run->referenceSpecs,
				register: $entity->getRegister(),
				organisation: $entity->getOrganisation()
			);
		}

		if ($run->aggregateSpecs === null) {
			return $payload;
		}

		$outcome = $this->aggregates->resolveAllWithOutcome(
			payload: $payload,
			aggregates: $run->aggregateSpecs,
			registerRef: $entity->getRegister()
		);
		if ($outcome['failed'] !== []) {
			$run->failed++;
			foreach ($outcome['failed'] as $name => $reason) {
				$run->output->writeln(sprintf('  <error>! aggregate %s on %s: %s</error>', $name, (string)$entity->getUuid(), $reason));
			}

			return null;
		}

		$payload['@aggregate'] = $outcome['values'];
		return $payload;
	}//end evaluationPayload()

	/**
	 * Evaluate the materialised calculations and keep the values that differ from the stored ones.
	 *
	 * A `sequence` reserves exactly once, on create. This command has no
	 * SequenceContext, so re-evaluating would resolve the node to null and
	 * `concat` would render it as "", replacing every assigned number with
	 * a truncated stub ("2026-0013" -> "2026-"). Those are never rewritten.
	 *
	 * @param RematerialiseRun     $run The run.
	 * @param array<string, mixed> $row The row: entity, stored data and payload.
	 *
	 * @return array<string, mixed> The changed values, by calculation name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function changedValues(RematerialiseRun $run, array $row): array {
		$expected = [];
		foreach ($run->materialised as $name => $spec) {
			if ($this->evaluator->expressionUsesSequence($spec['expression'] ?? null) === true) {
				continue;
			}

			try {
				$value = $this->evaluator->evaluate($row['payload'], $spec['expression'] ?? null);
				if ($value instanceof DateTimeInterface) {
					$value = $value->format(DateTimeInterface::ATOM);
				}

				if (($row['data'][(string)$name] ?? null) !== $value) {
					$expected[(string)$name] = $value;
				}
			} catch (\Throwable $e) {
				$run->failed++;
				$run->output->writeln(
					sprintf('  <error>! %s on %s: %s</error>', (string)$name, (string)$row['entity']->getUuid(), $e->getMessage())
				);
			}
		}//end foreach

		return $expected;
	}//end changedValues()

	/**
	 * Save a row whose calculations changed, unless an earlier save in this run already materialised it.
	 *
	 * The read decodes JSON held in a `type: string` property; the re-save hands
	 * it back as stored, or validation refuses the row. A `fallback` calculation
	 * gives the value only until an app's own save listener sets it, so a value
	 * the save kept is not a failure.
	 *
	 * @param RematerialiseRun     $run The run.
	 * @param array<string, mixed> $row The row: entity, stored data and the changed values.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function saveChangedRow(RematerialiseRun $run, array $row): void {
		$row = $this->withCurrentState(run: $run, row: $row);
		if ($row['expected'] === []) {
			$run->output->writeln(
				sprintf('  <comment>%s already materialised by an earlier save in this run</comment>', (string)$row['entity']->getUuid()),
				OutputInterface::VERBOSITY_VERBOSE
			);
			$run->touched++;
			return;
		}

		$stored = $this->types->restoreStringTypedValues(data: $row['data'], properties: ($run->schema->getProperties() ?? []));
		$checked = array_diff_key($row['expected'], $run->fallbacks());
		if ($this->saveForMaterialisation(entity: $row['entity'], stored: $stored, expected: $checked, output: $run->output) === true) {
			$run->touched++;
			return;
		}

		$run->failed++;
	}//end saveChangedRow()

	/**
	 * Replace the row's data with what is stored now, and drop the values it already holds.
	 *
	 * An earlier save in this run can already have materialised the row (saving
	 * one enrolment re-saves its siblings), and saving the snapshot taken at the
	 * start would then try to change the readOnly value back. When the row cannot
	 * be read again, the snapshot stays.
	 *
	 * @param RematerialiseRun     $run The run.
	 * @param array<string, mixed> $row The row: entity, stored data and the changed values.
	 *
	 * @return array<string, mixed> The row with current data and the values still to change.
	 *
	 * @spec openspec/changes/rematerialise-rereads-before-save/specs/computed-fields/spec.md
	 */
	private function withCurrentState(RematerialiseRun $run, array $row): array {
		$current = $this->reread(entity: $row['entity'], register: $run->register, schema: $run->schema);
		if ($current === null) {
			return $row;
		}

		$row['data'] = $current;
		$row['expected'] = array_filter(
			$row['expected'],
			static fn ($value, $name): bool => ($current[$name] ?? null) !== $value,
			ARRAY_FILTER_USE_BOTH
		);
		return $row;
	}//end withCurrentState()

	/**
	 * Read a row's stored data as it is now, not as the run's first read saw it.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $entity   The row from the run's first read.
	 * @param Register                          $register The register.
	 * @param Schema                            $schema   The schema.
	 *
	 * @return array<string, mixed>|null The stored data, or null when the row cannot be read again.
	 *
	 * @spec openspec/changes/rematerialise-rereads-before-save/specs/computed-fields/spec.md
	 */
	private function reread(\OCA\OpenRegister\Db\ObjectEntity $entity, Register $register, Schema $schema): ?array {
		try {
			$fresh = $this->magicMapper->find(
				identifier: (string)$entity->getUuid(),
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		return ($fresh->getObject() ?? []);
	}//end reread()

	/**
	 * Re-save a row UNCHANGED so the save path materialises it, and check it did.
	 *
	 * The command does not write the values itself: materialised calculations
	 * are declared readOnly ("never edit by hand"), and ObjectService refuses a
	 * payload that changes them (live pass O8: "Cannot modify readOnly
	 * properties"). CalculationOnSaveListener writes them on every save, as the
	 * temporal sweep relies on; the command re-saves the stored data, as the
	 * sweep does, and then reads back that each changed value arrived.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $entity   The row.
	 * @param array<string, mixed>              $stored   Its stored data.
	 * @param array<string, mixed>              $expected The values the calculations now give, by name.
	 * @param OutputInterface                   $output   The output.
	 *
	 * @return bool True when every expected value is on the saved row.
	 *
	 * @spec openspec/changes/rematerialise-writes-calculated-values/specs/computed-fields/spec.md
	 */
	private function saveForMaterialisation(
		\OCA\OpenRegister\Db\ObjectEntity $entity,
		array $stored,
		array $expected,
		OutputInterface $output
	): bool {
		try {
			// No user session under occ: write as the system (live pass O7).
			$saved = $this->objectService->saveObject(
				object: $stored,
				register: $entity->getRegister(),
				schema: $entity->getSchema(),
				uuid: $entity->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$output->writeln(sprintf('  <error>save failed on %s: %s</error>', (string)$entity->getUuid(), $e->getMessage()));
			return false;
		}

		$after = $saved->getObject() ?? [];
		$missing = [];
		foreach (array_keys($expected) as $name) {
			if (($after[$name] ?? null) === ($stored[$name] ?? null)) {
				$missing[] = $name;
			}
		}

		if ($missing === []) {
			return true;
		}

		$output->writeln(
			sprintf(
				'  <error>saved %s but the save did not materialise: %s</error>',
				(string)$entity->getUuid(),
				implode(', ', $missing)
			)
		);
		return false;
	}//end saveForMaterialisation()

	/**
	 * Inject the synthetic `@self` metadata into an evaluation payload.
	 *
	 * @param array<string, mixed> $data Object data.
	 * @param \OCA\OpenRegister\Db\ObjectEntity $entity Object entity providing metadata.
	 *
	 * @return array<string, mixed> Payload with `@self` injected.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function withSelf(array $data, \OCA\OpenRegister\Db\ObjectEntity $entity): array {
		$created = $entity->getCreated();
		$updated = $entity->getUpdated();
		$createdFormatted = null;
		if ($created !== null) {
			$createdFormatted = $created->format(DateTimeInterface::ATOM);
		}

		$updatedFormatted = null;
		if ($updated !== null) {
			$updatedFormatted = $updated->format(DateTimeInterface::ATOM);
		}

		$data['@self'] = [
			'id' => $entity->getUuid(),
			'uuid' => $entity->getUuid(),
			'register' => $entity->getRegister(),
			'schema' => $entity->getSchema(),
			'owner' => $entity->getOwner(),
			'created' => $createdFormatted,
			'updated' => $updatedFormatted,
		];
		return $data;
	}//end withSelf()
}//end class
