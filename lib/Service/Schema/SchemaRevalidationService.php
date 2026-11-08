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
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Db\SchemaRunEntryMapperInterface;
use OCA\OpenRegister\Db\SchemaRunMapperInterface;
use OCA\OpenRegister\Service\Object\ObjectValidatorInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;

/**
 * Population revalidation orchestrator.
 */
class SchemaRevalidationService {

	use SchemaRunPopulation;

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
	 * @param SchemaRunMapperInterface $runMapper Run persistence.
	 * @param SchemaRunEntryMapperInterface $runEntryMapper Per-object run entries.
	 * @param SchemaMapperInterface $schemaMapper Schema lookup.
	 * @param ObjectOperationsInterface $objectService Object read/save pipeline.
	 * @param ObjectValidatorInterface $validateObject Single write-path validator.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SchemaRunMapperInterface $runMapper,
		private readonly SchemaRunEntryMapperInterface $runEntryMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly ObjectOperationsInterface $objectService,
		private readonly ObjectValidatorInterface $validateObject,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Start a revalidation run.
	 *
	 * Creates the run record, refusing a second concurrent run on the same
	 * schema ({@see SchemaRunPopulation::assertNoActiveRun()} throws the
	 * SchemaRunConcurrencyException), and returns it ready for the background
	 * job to process.
	 *
	 * @param int $schemaId The schema id.
	 * @param int $registerId The register the objects live in.
	 * @param array<string, mixed>|null $proposedDefinition Optional proposed definition (dry-run).
	 * @param string|null $startedBy The starting user.
	 *
	 * @return SchemaRun The created run.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function start(int $schemaId, int $registerId, ?array $proposedDefinition = null, ?string $startedBy = null): SchemaRun {
		$this->assertNoActiveRun(schemaId: $schemaId);

		return $this->runMapper->createFromArray(
			[
				'schemaId' => $schemaId,
				'registerId' => $registerId,
				'type' => SchemaRun::TYPE_REVALIDATION,
				'state' => SchemaRun::STATE_RUNNING,
				'proposedDefinition' => $proposedDefinition,
				'total' => $this->countPopulation(schemaId: $schemaId, registerId: $registerId),
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
		$schema = $this->validationSchema(run: $run);
		$objects = $this->nextBatch(run: $run, batchSize: $batchSize);
		if ($objects === []) {
			return false;
		}

		$batch = new SchemaRunBatch(run: $run, schema: $schema, emptyReport: ['valid' => 0, 'invalid' => 0]);
		foreach ($objects as $object) {
			$this->revalidateOne(batch: $batch, object: $object);
		}

		return $this->saveBatch(batch: $batch);
	}//end processBatch()

	/**
	 * The schema a run validates against: the stored one, or a transient proposed one for a dry run.
	 *
	 * @param SchemaRun $run The run.
	 *
	 * @return Schema The validation schema.
	 */
	private function validationSchema(SchemaRun $run): Schema {
		$schema = $this->schemaMapper->find($run->getSchemaId());
		if ($run->getProposedDefinition() === null) {
			return $schema;
		}

		return $this->transientSchema(base: $schema, proposed: $run->getProposedDefinition());
	}//end validationSchema()

	/**
	 * Validate one object of a batch, recording it when invalid.
	 *
	 * @param SchemaRunBatch $batch  The batch.
	 * @param ObjectEntity   $object The object.
	 *
	 * @return void
	 */
	private function revalidateOne(SchemaRunBatch $batch, ObjectEntity $object): void {
		$batch->reach(object: $object);
		$errors = $this->validate(object: $object, schema: $batch->schema);
		if (count($errors) === 0) {
			$batch->tally(key: 'valid');
		} else {
			$this->recordInvalid(batch: $batch, object: $object, errors: $errors);
		}

		$batch->markProcessed();
	}//end revalidateOne()

	/**
	 * Count an invalid object and record its first errors.
	 *
	 * @param SchemaRunBatch     $batch  The batch.
	 * @param ObjectEntity       $object The object.
	 * @param array<int, string> $errors The validation errors.
	 *
	 * @return void
	 */
	private function recordInvalid(SchemaRunBatch $batch, ObjectEntity $object, array $errors): void {
		$batch->tally(key: 'invalid');
		$this->recordEntry(
			run: $batch->run,
			entry: [
				'objectUuid' => $object->getUuid(),
				'outcome' => SchemaRunEntry::OUTCOME_INVALID,
				'message' => implode('; ', array_slice($errors, 0, self::MAX_ERRORS_PER_OBJECT)),
			]
		);

	}//end recordInvalid()

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
			$result = $this->validateObject->validateObject(object: $object->getObject(), schema: $schema);
			if ($result->isValid() === true) {
				return [];
			}

			return [($result->error()?->message() ?? 'Validation failed.')];
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
}//end class
