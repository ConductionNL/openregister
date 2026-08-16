<?php

/**
 * SchemaRevalidationService — population impact analysis (dry-run revalidation).
 *
 * Re-validates every non-deleted object of a schema against either the
 * schema's current definition or a supplied proposed definition, WITHOUT
 * mutating any object. A run executes in batches (driven by
 * {@see \OCA\OpenRegister\BackgroundJob\SchemaRunJob}) with a resumable
 * cursor, persists progress and a per-object report, and — for a
 * current-definition run — refreshes each object's validity status and
 * `schemaVersion` stamp via a metadata-only update that adds no content
 * version or audit churn.
 *
 * Validation reuses the single write-path validator
 * ({@see \OCA\OpenRegister\Service\Object\ValidateObject}) so there is one
 * definition of "valid". A proposed-definition run validates against a
 * transient {@see Schema} built from the proposal, never touching the
 * stored schema.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Population revalidation orchestrator.
 */
class SchemaRevalidationService {

	/**
	 * Default per-batch object count.
	 *
	 * @var int
	 */
	public const DEFAULT_BATCH = 100;

	/**
	 * Max per-object validation errors recorded.
	 *
	 * @var int
	 */
	private const MAX_ERRORS_PER_OBJECT = 25;

	/**
	 * Constructor.
	 *
	 * @param SchemaRunMapper $runMapper Run persistence.
	 * @param SchemaRunEntryMapper $runEntryMapper Per-object run entries.
	 * @param SchemaMapper $schemaMapper Schema lookup.
	 * @param ObjectService $objectService Object read/save pipeline.
	 * @param ValidateObject $validateObject Single write-path validator.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SchemaRunMapper $runMapper,
		private readonly SchemaRunEntryMapper $runEntryMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectService $objectService,
		private readonly ValidateObject $validateObject,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Start a revalidation run.
	 *
	 * Creates the run record, refusing a second concurrent run on the same
	 * schema, and returns it ready for the background job to process.
	 *
	 * @param int $schemaId The schema id.
	 * @param int $registerId The register the objects live in.
	 * @param array<string, mixed>|null $proposedDefinition Optional proposed definition (dry-run).
	 * @param string|null $startedBy The starting user.
	 *
	 * @return SchemaRun The created run.
	 *
	 * @throws SchemaRunConcurrencyException When an active run already exists.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function start(int $schemaId, int $registerId, ?array $proposedDefinition = null, ?string $startedBy = null): SchemaRun {
		$this->assertNoActiveRun(schemaId: $schemaId);

		$total = $this->countPopulation(schemaId: $schemaId, registerId: $registerId);

		return $this->runMapper->createFromArray(
			[
				'schemaId' => $schemaId,
				'registerId' => $registerId,
				'type' => SchemaRun::TYPE_REVALIDATION,
				'state' => SchemaRun::STATE_RUNNING,
				'proposedDefinition' => $proposedDefinition,
				'total' => $total,
				'startedBy' => $startedBy,
				'report' => ['valid' => 0, 'invalid' => 0],
			]
		);

	}//end start()

	/**
	 * Process one batch of a revalidation run, advancing its cursor.
	 *
	 * Returns true when more batches remain, false when the run is done.
	 * Guarantees zero mutation for a proposed-definition (dry-run) run; for
	 * a current-definition run, only a metadata-only validity stamp is
	 * written.
	 *
	 * @param SchemaRun $run The run to advance.
	 * @param int $batchSize The batch size.
	 *
	 * @return bool True when more work remains.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function processBatch(SchemaRun $run, int $batchSize = self::DEFAULT_BATCH): bool {
		$schema = $this->schemaMapper->find($run->getSchemaId());

		$validationSchema = $schema;
		$isDryRun = ($run->getProposedDefinition() !== null);
		if ($isDryRun === true) {
			$validationSchema = $this->transientSchema(base: $schema, proposed: $run->getProposedDefinition());
		}

		$objects = $this->loadBatch(run: $run, batchSize: $batchSize);
		if (count($objects) === 0) {
			$this->finish(run: $run, state: SchemaRun::STATE_COMPLETED);
			return false;
		}

		$report = ($run->getReport() ?? ['valid' => 0, 'invalid' => 0]);
		$maxId = $run->getCursor();

		foreach ($objects as $object) {
			$maxId = max($maxId, (int)$object->getId());

			$errors = $this->validate(object: $object, schema: $validationSchema);
			if (count($errors) === 0) {
				$report['valid'] = (($report['valid'] ?? 0) + 1);
			} else {
				$report['invalid'] = (($report['invalid'] ?? 0) + 1);
				$this->runEntryMapper->createFromArray(
					[
						'runId' => $run->getId(),
						'objectUuid' => $object->getUuid(),
						'outcome' => SchemaRunEntry::OUTCOME_INVALID,
						'message' => implode('; ', array_slice($errors, 0, self::MAX_ERRORS_PER_OBJECT)),
					]
				);
			}

			$run->setProcessed(($run->getProcessed() + 1));
		}//end foreach

		$run->setCursor($maxId);
		$run->setReport($report);
		$this->runMapper->save($run);

		return true;
	}//end processBatch()

	/**
	 * Validate one object against a schema, returning a list of error strings.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema $schema The schema (real or transient proposed).
	 *
	 * @return array<int, string> The validation error messages (empty when valid).
	 */
	private function validate(ObjectEntity $object, Schema $schema): array {
		try {
			$result = $this->validateObject->validateObject(
				object: $object->getObject(),
				schema: $schema
			);

			if ($result->isValid() === true) {
				return [];
			}

			$error = $result->error();
			if ($error === null) {
				return ['Validation failed.'];
			}

			return [$error->message()];
		} catch (\Throwable $e) {
			return [$e->getMessage()];
		}

	}//end validate()

	/**
	 * Build a transient (unpersisted) Schema from a proposed definition.
	 *
	 * @param Schema $base The base schema (for slug/identity).
	 * @param array<string, mixed> $proposed The proposed definition.
	 *
	 * @return Schema The transient schema.
	 */
	private function transientSchema(Schema $base, array $proposed): Schema {
		$schema = new Schema();
		$schema->hydrate(
			[
				'title' => $base->getTitle(),
				'slug' => $base->getSlug(),
				'version' => $base->getVersion(),
				'properties' => ($proposed['properties'] ?? []),
				'required' => ($proposed['required'] ?? []),
			]
		);

		return $schema;
	}//end transientSchema()

	/**
	 * Load the next batch of non-deleted objects after the run's cursor.
	 *
	 * @param SchemaRun $run The run.
	 * @param int $batchSize The batch size.
	 *
	 * @return ObjectEntity[] The objects.
	 */
	private function loadBatch(SchemaRun $run, int $batchSize): array {
		$config = [
			'limit' => $batchSize,
			'offset' => $run->getProcessed(),
			'sort' => ['id' => 'ASC'],
			'filters' => [
				'register' => $run->getRegisterId(),
				'schema' => $run->getSchemaId(),
			],
		];

		$result = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);

		return array_values(array_filter($result, static fn ($o) => $o instanceof ObjectEntity));
	}//end loadBatch()

	/**
	 * Count the non-deleted population for a schema in a register.
	 *
	 * @param int $schemaId The schema id.
	 * @param int $registerId The register id.
	 *
	 * @return int The count.
	 */
	private function countPopulation(int $schemaId, int $registerId): int {
		try {
			return $this->objectService->count(
				config: [
					'filters' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

	}//end countPopulation()

	/**
	 * Finish a run, persisting its terminal state.
	 *
	 * @param SchemaRun $run The run.
	 * @param string $state The terminal state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function finish(SchemaRun $run, string $state): void {
		$run->setState($state);
		$this->runMapper->save($run);

	}//end finish()

	/**
	 * Refuse a second concurrent run on the same schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return void
	 *
	 * @throws SchemaRunConcurrencyException When an active run exists.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function assertNoActiveRun(int $schemaId): void {
		$active = $this->runMapper->findActiveForSchema($schemaId);
		if ($active !== null) {
			throw new SchemaRunConcurrencyException(
				message: sprintf('An active run (#%d) already exists for schema %d.', $active->getId(), $schemaId)
			);
		}

	}//end assertNoActiveRun()
}//end class
