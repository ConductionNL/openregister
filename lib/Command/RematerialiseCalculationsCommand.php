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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$registerRef = (string)$input->getArgument('register');
		$schemaRef = (string)$input->getArgument('schema');
		$dryRun = (bool)$input->getOption('dry-run');

		try {
			$register = $this->registerMapper->find($registerRef, _multitenancy: false);
			$schema = $this->schemaMapper->find($schemaRef, _multitenancy: false);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$calcs = $this->getCalculations(schema: $schema);
		if ($calcs === null || count($calcs) === 0) {
			$output->writeln('<comment>Schema declares no x-openregister-calculations — nothing to do.</comment>');
			return Command::SUCCESS;
		}

		$materialiseNames = [];
		foreach ($calcs as $name => $spec) {
			if (is_array($spec) === true && ($spec['materialise'] ?? false) === true) {
				$materialiseNames[] = (string)$name;
			}
		}

		if (count($materialiseNames) === 0) {
			$output->writeln('<comment>No materialised calculations declared — nothing to do.</comment>');
			return Command::SUCCESS;
		}

		$dryRunLabel = '';
		if ($dryRun === true) {
			$dryRunLabel = ' (dry run)';
		}

		$output->writeln(
			sprintf(
				'<info>Rematerialising %d calculation(s) on %s/%s%s</info>',
				count($materialiseNames),
				$register->getSlug() ?? $register->getId(),
				$schema->getSlug() ?? $schema->getId(),
				$dryRunLabel
			)
		);

		$entities = $this->magicMapper->findAllInRegisterSchemaTable(
			register: $register,
			schema: $schema,
			limit: 100000
		);

		// Declared cross-object references are pre-resolved per object so the
		// recompute path refreshes references the same way the save path does.
		$referenceSpecs = ($schema->getConfiguration()['x-openregister-references'] ?? null);
		if (is_array($referenceSpecs) === false || count($referenceSpecs) === 0) {
			$referenceSpecs = null;
		}

		// Declared aggregate-references are pre-resolved per object so the
		// recompute path refreshes save-time aggregate snapshots the same way
		// the save path does.
		$aggregateSpecs = ($schema->getConfiguration()['x-openregister-aggregate-refs'] ?? null);
		if (is_array($aggregateSpecs) === false || count($aggregateSpecs) === 0) {
			$aggregateSpecs = null;
		}

		$touched = 0;
		$unchanged = 0;
		$failed = 0;

		foreach ($entities as $entity) {
			$data = $entity->getObject() ?? [];
			$payload = $this->withSelf(data: $data, entity: $entity);

			if ($referenceSpecs !== null) {
				$payload['@ref'] = $this->references->resolveAll(
					payload: $payload,
					references: $referenceSpecs,
					register: $entity->getRegister(),
					organisation: $entity->getOrganisation()
				);
			}

			if ($aggregateSpecs !== null) {
				$outcome = $this->aggregates->resolveAllWithOutcome(
					payload: $payload,
					aggregates: $aggregateSpecs,
					registerRef: $entity->getRegister()
				);
				// An aggregate that could not be resolved is not a null to
				// compare: the row's value is unknown, so the row FAILED (live
				// pass O9 counted 205 such rows "unchanged" and exited 0).
				if ($outcome['failed'] !== []) {
					$failed++;
					foreach ($outcome['failed'] as $name => $reason) {
						$output->writeln(sprintf('  <error>! aggregate %s on %s: %s</error>', $name, (string)$entity->getUuid(), $reason));
					}

					continue;
				}

				$payload['@aggregate'] = $outcome['values'];
			}

			$stored = $data;
			$expected = [];
			$changed = false;
			foreach ($calcs as $name => $spec) {
				if (is_array($spec) === false || ($spec['materialise'] ?? false) !== true) {
					continue;
				}

				// A `sequence` reserves exactly once, on create. This command has no
				// SequenceContext, so re-evaluating would resolve the node to null and
				// `concat` would render it as "", replacing every assigned number with
				// a truncated stub ("2026-0013" -> "2026-"). Never rewrite those.
				// openregister#3075.
				if ($this->evaluator->expressionUsesSequence($spec['expression'] ?? null) === true) {
					continue;
				}

				try {
					$value = $this->evaluator->evaluate($payload, $spec['expression'] ?? null);
					if ($value instanceof DateTimeInterface) {
						$value = $value->format(DateTimeInterface::ATOM);
					}

					if (($data[(string)$name] ?? null) !== $value) {
						$expected[(string)$name] = $value;
						$changed = true;
					}
				} catch (\Throwable $e) {
					$failed++;
					$output->writeln(
						sprintf(
							'  <error>! %s on %s: %s</error>',
							(string)$name,
							(string)$entity->getUuid(),
							$e->getMessage()
						)
					);
				}//end try
			}//end foreach

			if ($changed === false) {
				$unchanged++;
				continue;
			}

			if ($dryRun === true) {
				$touched++;
				continue;
			}

			// Re-read the row: an earlier save in this run can already have
			// materialised it (saving one enrolment re-saves its siblings), and
			// saving the snapshot taken at the start would then try to change
			// the readOnly value back (live pass O11).
			$current = $this->reread(entity: $entity, register: $register, schema: $schema);
			if ($current !== null) {
				$stored = $current;
				$expected = array_filter(
					$expected,
					static fn ($value, $name): bool => ($current[$name] ?? null) !== $value,
					ARRAY_FILTER_USE_BOTH
				);
				if ($expected === []) {
					$output->writeln(
						sprintf('  <comment>%s already materialised by an earlier save in this run</comment>', (string)$entity->getUuid()),
						OutputInterface::VERBOSITY_VERBOSE
					);
					$touched++;
					continue;
				}
			}

			if ($this->saveForMaterialisation(entity: $entity, stored: $stored, expected: $expected, output: $output) === true) {
				$touched++;
				continue;
			}

			$failed++;
		}//end foreach

		$output->writeln(
			sprintf(
				'<info>Touched %d, unchanged %d, failed %d</info>',
				$touched,
				$unchanged,
				$failed
			)
		);
		$exitCode = Command::SUCCESS;
		if ($failed > 0) {
			$exitCode = Command::FAILURE;
		}

		return $exitCode;
	}//end execute()

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

	/**
	 * Read the `x-openregister-calculations` configuration block.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return array<string, mixed>|null Calculations map, or null when absent.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function getCalculations(Schema $schema): ?array {
		$config = ($schema->getConfiguration() ?? []);
		$value = ($config['x-openregister-calculations'] ?? null);
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end getCalculations()
}//end class
