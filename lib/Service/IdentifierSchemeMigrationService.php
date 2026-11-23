<?php

/**
 * IdentifierSchemeMigrationService — rewrite issued identifiers after a scheme change.
 *
 * A schema property's `x-openregister-generated` format changed; the objects
 * already carry values in the previous format. A run reads each value back
 * through the previous format, renders the same number in the current one,
 * keeps the previous value as a foreign identifier issued by this instance
 * (so a number quoted in a letter still finds the object) and writes the new
 * value. It runs in batches from a background job and records its progress.
 * One run per sequence at a time: a unique index on the running sequence
 * refuses the second, naming the first.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\IdentifierSchemeMigrationJob;
use OCA\OpenRegister\Db\IdentifierSchemeRun;
use OCA\OpenRegister\Db\IdentifierSchemeRunMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RunningNumbersInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationInterface;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationReader;
use OCA\OpenRegister\Service\Schemas\IdentityRewriteScope;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use Throwable;

/**
 * Starts, advances and reports identifier scheme migrations.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
 */
class IdentifierSchemeMigrationService {

	/**
	 * Objects rewritten per background job run.
	 */
	public const DEFAULT_BATCH = 100;

	/**
	 * The issuer of a previous value: this instance, by its instance id.
	 */
	public const ISSUER_PREFIX = 'openregister:';

	/**
	 * Constructor.
	 *
	 * @param IdentifierSchemeRunMapper $runs       The runs and their progress.
	 * @param SchemaMapperInterface     $schemas    The schema whose property is migrated.
	 * @param ObjectOperationsInterface $objects    Reads and saves the objects.
	 * @param ForeignIdentifierRegistry $foreignIds Keeps each previous value findable.
	 * @param RunningNumbersInterface   $sequences  Keeps the counter past every rewritten number.
	 * @param IJobList                  $jobList    Queues the background job.
	 * @param IConfig                   $config     The instance id, which names the issuer.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function __construct(
		private readonly IdentifierSchemeRunMapper $runs,
		private readonly SchemaMapperInterface $schemas,
		private readonly ObjectOperationsInterface $objects,
		private readonly ForeignIdentifierRegistry $foreignIds,
		private readonly RunningNumbersInterface $sequences,
		private readonly IJobList $jobList,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Start rewriting a property's issued identifiers from a previous format.
	 *
	 * @param int         $registerId     The register of the objects.
	 * @param int         $schemaId       The schema whose property's format changed.
	 * @param string      $property       The generated identifier property.
	 * @param string      $previousFormat The format the identifiers were issued in.
	 * @param string|null $startedBy      Who starts it.
	 *
	 * @return IdentifierSchemeRun The running run, queued for the background job.
	 *
	 * @throws InvalidArgumentException When the property, the formats or the kind do not allow a rewrite.
	 * @throws IdentifierSchemeMigrationRunningException When the sequence is already being migrated.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function start(int $registerId, int $schemaId, string $property, string $previousFormat, ?string $startedBy=null): IdentifierSchemeRun {
		$schema = $this->schemas->find($schemaId);
		$current = $this->declarationOf(schema: $schema, property: $property);
		$this->previousDeclaration(schema: $schema, property: $property, previousFormat: $previousFormat);

		$sequence = $current->sequence();
		$running = $this->runs->findRunning(sequence: $sequence);
		if ($running !== null) {
			throw new IdentifierSchemeMigrationRunningException(sequence: $sequence, running: $running);
		}

		$run = new IdentifierSchemeRun();
		$run->setSequence($sequence);
		$run->setActiveSequence($sequence);
		$run->setRegisterId($registerId);
		$run->setSchemaId($schemaId);
		$run->setProperty($property);
		$run->setPreviousFormat(trim($previousFormat));
		$run->setState(IdentifierSchemeRun::STATE_RUNNING);
		$run->setTotal($this->population(run: $run));
		$run->setStartedBy($startedBy);
		$run->setStarted(new DateTime());

		$run = $this->insertRunning(run: $run);
		$this->jobList->add(IdentifierSchemeMigrationJob::class, ['run_id' => $run->getId()]);

		return $run;
	}//end start()

	/**
	 * One run, or null.
	 *
	 * @param int $id The run id.
	 *
	 * @return IdentifierSchemeRun|null
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function find(int $id): ?IdentifierSchemeRun {
		return $this->runs->findById(id: $id);
	}//end find()

	/**
	 * Rewrite the run's next batch of objects.
	 *
	 * @param IdentifierSchemeRun $run       The running run.
	 * @param int                 $batchSize The objects per batch.
	 *
	 * @return bool True when another batch may remain; false once the run stopped.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function processBatch(IdentifierSchemeRun $run, int $batchSize=self::DEFAULT_BATCH): bool {
		if ($run->getState() !== IdentifierSchemeRun::STATE_RUNNING) {
			return false;
		}

		$schema = $this->schemas->find((int)$run->getSchemaId());
		$pass = new IdentifierSchemePass(
			run: $run,
			schema: $schema,
			current: $this->declarationOf(schema: $schema, property: (string)$run->getProperty()),
			previous: $this->previousDeclaration(schema: $schema, property: (string)$run->getProperty(), previousFormat: (string)$run->getPreviousFormat())
		);

		$objects = $this->nextBatch(run: $run, batchSize: max(1, $batchSize));
		if ($objects === []) {
			$this->finish(run: $run, state: IdentifierSchemeRun::STATE_COMPLETED);
			return false;
		}

		foreach ($objects as $object) {
			$this->tally(run: $run, outcome: $this->rewriteOne(pass: $pass, object: $object));
		}

		$run->setProcessed($run->getProcessed() + count($objects));
		$this->runs->update($run);

		return true;
	}//end processBatch()

	/**
	 * Stop a run as failed, freeing its sequence for another migration.
	 *
	 * @param IdentifierSchemeRun $run   The run.
	 * @param string              $error Why it stopped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function fail(IdentifierSchemeRun $run, string $error): void {
		$run->setLastError($error);
		$this->finish(run: $run, state: IdentifierSchemeRun::STATE_FAILED);
	}//end fail()

	/**
	 * The issuer under which this instance keeps previous values.
	 *
	 * Look an old number up with
	 * `ForeignIdentifierRegistry::resolve(issuer: previousIssuer(), value: ...)`.
	 *
	 * @return string The issuer name.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function previousIssuer(): string {
		$instance = trim($this->config->getSystemValueString('instanceid', ''));
		if ($instance === '') {
			$instance = 'local';
		}

		return self::ISSUER_PREFIX.$instance;
	}//end previousIssuer()

	/**
	 * The property's current declaration, which must be a counter.
	 *
	 * @param Schema $schema   The schema.
	 * @param string $property The property.
	 *
	 * @return GeneratedIdentifierDeclarationInterface
	 *
	 * @throws InvalidArgumentException When the property is not a counter-generated identifier.
	 */
	private function declarationOf(Schema $schema, string $property): GeneratedIdentifierDeclarationInterface {
		$definition = $this->propertyOf(schema: $schema, property: $property);
		$declaration = GeneratedIdentifierDeclarationReader::fromProperty(property: $definition, path: $property);
		if ($declaration === null) {
			throw new InvalidArgumentException(sprintf('"%s" is not a generated identifier.', $property));
		}

		return self::counter(declaration: $declaration);
	}//end declarationOf()

	/**
	 * The property's declaration as it was, with the previous format.
	 *
	 * @param Schema $schema         The schema.
	 * @param string $property       The property.
	 * @param string $previousFormat The previous format.
	 *
	 * @return GeneratedIdentifierDeclarationInterface
	 *
	 * @throws InvalidArgumentException When the previous format is empty, unchanged or unusable.
	 */
	private function previousDeclaration(Schema $schema, string $property, string $previousFormat): GeneratedIdentifierDeclarationInterface {
		$definition = $this->propertyOf(schema: $schema, property: $property);
		$annotation = (array)($definition[GeneratedIdentifierDeclaration::ANNOTATION] ?? []);
		$previousFormat = trim($previousFormat);
		if ($previousFormat === '' || $previousFormat === (string)($annotation['format'] ?? '')) {
			throw new InvalidArgumentException('Give the previous format of the identifier; it must differ from the current one.');
		}

		$definition[GeneratedIdentifierDeclaration::ANNOTATION] = array_replace($annotation, ['format' => $previousFormat]);
		try {
			$declaration = GeneratedIdentifierDeclarationReader::fromProperty(property: $definition, path: $property);
		} catch (Throwable $e) {
			throw new InvalidArgumentException(sprintf('The previous format is not usable: %s', $e->getMessage()), previous: $e);
		}

		return self::counter(declaration: $declaration);
	}//end previousDeclaration()

	/**
	 * A schema property's definition.
	 *
	 * @param Schema $schema   The schema.
	 * @param string $property The property.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When the schema has no such property.
	 */
	private function propertyOf(Schema $schema, string $property): array {
		$definition = (($schema->getProperties() ?? [])[$property] ?? null);
		if (is_array($definition) === false) {
			throw new InvalidArgumentException(sprintf('Schema %d has no property "%s".', (int)$schema->getId(), $property));
		}

		return $definition;
	}//end propertyOf()

	/**
	 * A counter declaration; a random code carries no number to rewrite.
	 *
	 * @param GeneratedIdentifierDeclarationInterface|null $declaration The declaration.
	 *
	 * @return GeneratedIdentifierDeclarationInterface
	 *
	 * @throws InvalidArgumentException When it is absent or random.
	 */
	private static function counter(?GeneratedIdentifierDeclarationInterface $declaration): GeneratedIdentifierDeclarationInterface {
		if ($declaration === null || $declaration->isRandom() === true) {
			throw new InvalidArgumentException('A scheme change rewrites counter identifiers; a random code has no number to carry over.');
		}

		return $declaration;
	}//end counter()

	/**
	 * The objects of the schema when the run starts, 0 when they cannot be counted.
	 *
	 * @param IdentifierSchemeRun $run The run.
	 *
	 * @return int
	 */
	private function population(IdentifierSchemeRun $run): int {
		try {
			return $this->objects->count(config: ['filters' => self::filters(run: $run)]);
		} catch (Throwable) {
			return 0;
		}
	}//end population()

	/**
	 * Store a new running run; a concurrent one on the same sequence wins.
	 *
	 * @param IdentifierSchemeRun $run The run.
	 *
	 * @return IdentifierSchemeRun The stored run.
	 *
	 * @throws IdentifierSchemeMigrationRunningException When another run took the sequence first.
	 * @throws DbException When the store fails for another reason.
	 */
	private function insertRunning(IdentifierSchemeRun $run): IdentifierSchemeRun {
		try {
			return $this->runs->insert($run);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			$sequence = (string)$run->getSequence();
			throw new IdentifierSchemeMigrationRunningException(
				sequence: $sequence,
				running: $this->runs->findRunning(sequence: $sequence),
				previous: $e
			);
		}
	}//end insertRunning()

	/**
	 * The run's next objects, in id order after those already visited.
	 *
	 * @param IdentifierSchemeRun $run       The run.
	 * @param int                 $batchSize The batch size.
	 *
	 * @return ObjectEntity[]
	 */
	private function nextBatch(IdentifierSchemeRun $run, int $batchSize): array {
		$found = $this->objects->findAll(
			config: [
				'limit'   => $batchSize,
				'offset'  => $run->getProcessed(),
				'sort'    => ['id' => 'ASC'],
				'filters' => self::filters(run: $run),
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_filter($found, static fn ($candidate): bool => $candidate instanceof ObjectEntity));
	}//end nextBatch()

	/**
	 * The register and schema filter of a run.
	 *
	 * @param IdentifierSchemeRun $run The run.
	 *
	 * @return array{register: int, schema: int}
	 */
	private static function filters(IdentifierSchemeRun $run): array {
		return ['register' => (int)$run->getRegisterId(), 'schema' => (int)$run->getSchemaId()];
	}//end filters()

	/**
	 * Rewrite one object's identifier: keep the old value, then save the new one.
	 *
	 * @param IdentifierSchemePass $pass   The run and its two declarations.
	 * @param ObjectEntity         $object The object.
	 *
	 * @return string `rewritten`, `skipped` or `failed`.
	 */
	private function rewriteOne(IdentifierSchemePass $pass, ObjectEntity $object): string {
		$property = (string)$pass->run->getProperty();
		$data = ($object->getObject() ?? []);
		$old = (string)($data[$property] ?? '');
		$parsed = null;
		if ($old !== '') {
			$parsed = $pass->previous->parse(value: $old);
		}

		if ($parsed === null) {
			return 'skipped';
		}

		$moment = self::momentOf(object: $object, period: $parsed['period']);
		$new = $pass->current->render(sequenceValue: $parsed['sequence'], moment: $moment);
		if ($new === $old) {
			return 'skipped';
		}

		try {
			$this->foreignIds->record(objectUuid: (string)$object->getUuid(), issuer: $this->previousIssuer(), value: $old);
			$this->save(pass: $pass, object: $object, data: array_replace($data, [$property => $new]));
			$this->sequences->advanceTo(
				'gen:'.$pass->current->sequence().'|'.$pass->current->periodAt(moment: $moment),
				reserved: $parsed['sequence']
			);
		} catch (Throwable $e) {
			$pass->run->setLastError(sprintf('%s: %s', (string)$object->getUuid(), $e->getMessage()));
			return 'failed';
		}

		return 'rewritten';
	}//end rewriteOne()

	/**
	 * Save the rewritten object; the rewrite scope lets the frozen value change.
	 *
	 * @param IdentifierSchemePass $pass   The run and its schema.
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $data   The object data with the new value.
	 *
	 * @return void
	 */
	private function save(IdentifierSchemePass $pass, ObjectEntity $object, array $data): void {
		IdentityRewriteScope::run(
			fn (): ObjectEntity => $this->objects->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: (int)$pass->run->getRegisterId(), schema: $pass->schema),
					uuid: $object->getUuid()
				),
				_rbac: false,
				_multitenancy: false
			)
		);
	}//end save()

	/**
	 * The moment the number was issued: the object's creation, in the parsed year.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $period The period the previous value carried, '' for none.
	 *
	 * @return DateTimeImmutable
	 */
	private static function momentOf(ObjectEntity $object, string $period): DateTimeImmutable {
		$created = DateTimeImmutable::createFromInterface($object->getCreated() ?? new DateTime());
		if (preg_match('/^\d{4}$/', $period) !== 1) {
			return $created;
		}

		return $created->setDate((int)$period, (int)$created->format('n'), (int)$created->format('j'));
	}//end momentOf()

	/**
	 * Count one object's outcome on the run.
	 *
	 * @param IdentifierSchemeRun $run     The run.
	 * @param string              $outcome `rewritten`, `skipped` or `failed`.
	 *
	 * @return void
	 */
	private function tally(IdentifierSchemeRun $run, string $outcome): void {
		match ($outcome) {
			'rewritten' => $run->setRewritten($run->getRewritten() + 1),
			'failed' => $run->setFailed($run->getFailed() + 1),
			default => $run->setSkipped($run->getSkipped() + 1),
		};
	}//end tally()

	/**
	 * Stop a run and free its sequence.
	 *
	 * @param IdentifierSchemeRun $run   The run.
	 * @param string              $state Completed or failed.
	 *
	 * @return void
	 */
	private function finish(IdentifierSchemeRun $run, string $state): void {
		$run->setState($state);
		$run->setActiveSequence(null);
		$run->setFinished(new DateTime());
		$this->runs->update($run);
	}//end finish()
}//end class
