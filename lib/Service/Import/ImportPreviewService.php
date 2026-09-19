<?php

/**
 * The previewed import: decide every row, write none, then apply what was
 * decided.
 *
 * A preview produced by a different code path than the write is a preview
 * that can be wrong, so there is one path here and it runs twice: once in a
 * mode that decides and commits nothing, and once that applies the decisions
 * it kept (D-1). Between the two, the operator reads how many rows would be
 * created, updated, skipped and refused, and why each refusal was refused.
 *
 * The decisive steps are the import path's own. A saved column mapping is
 * applied by {@see \OCA\OpenRegister\Service\MigrationPack\MappingEngine},
 * the row becomes an object through
 * {@see \OCA\OpenRegister\Service\ImportService::transformCsvRowToObject()},
 * it is validated by {@see \OCA\OpenRegister\Service\Object\ValidateObject}
 * and written by {@see \OCA\OpenRegister\Service\ObjectService::saveObjects()}.
 * What this service adds is the step that was missing: the decision, taken
 * against a declared conflict policy and a declared match key, kept, shown,
 * and only then applied.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
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
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Previews an import, then commits the preview.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The preview stands where
 * the reader, the mapping, the validator, the match lookup and the write path
 * meet. Splitting it would put the decision in one class and the reason for
 * the decision in another.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same reason, counted a
 * second way. Two lifecycles over one record, each with its own refusals.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every dependency is one the
 * preview must share with the write path, which is the point: a preview that
 * resolved its own mapping or its own validator would describe an import
 * nobody runs.
 * @SuppressWarnings(PHPMD.StaticAccess) ConflictPolicy, MatchResolver's key
 * normaliser and SchemaMappingCheck are pure functions of their arguments.
 * Injecting them would let a caller substitute the rule that says a row
 * matching two objects is refused.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
class ImportPreviewService {

	/**
	 * How many rows one commit batch writes.
	 *
	 * @var int
	 */
	public const COMMIT_BATCH = 100;

	/**
	 * How often the preview's progress counters are flushed while it walks.
	 *
	 * @var int
	 */
	private const PROGRESS_EVERY = 50;

	/**
	 * Constructor.
	 *
	 * @param ImportPreviewMapper $previewMapper Preview persistence.
	 * @param ImportPreviewRowMapper $rowMapper Per-row decision persistence.
	 * @param SourceRowReader $reader The file reader.
	 * @param MatchResolver $matchResolver The match lookup.
	 * @param MappingEngine $mappingEngine The saved column mapping.
	 * @param MigrationPackService $packService Saved mappings.
	 * @param ImportService $importService The row transform the write path uses.
	 * @param ObjectService $objectService The write path.
	 * @param ValidateObject $validateObject Schema validation.
	 * @param RegisterMapper $registerMapper Register lookup.
	 * @param SchemaMapper $schemaMapper Schema lookup.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ImportPreviewMapper $previewMapper,
		private readonly ImportPreviewRowMapper $rowMapper,
		private readonly SourceRowReader $reader,
		private readonly MatchResolver $matchResolver,
		private readonly MappingEngine $mappingEngine,
		private readonly MigrationPackService $packService,
		private readonly ImportService $importService,
		private readonly ObjectService $objectService,
		private readonly ValidateObject $validateObject,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create the preview record. Nothing is decided and nothing is written
	 * yet: this only fixes what the preview is of.
	 *
	 * @param array<string, mixed> $params Preview parameters: register, schema,
	 *                                     filePath, sourceName, format, policy,
	 *                                     matchKey, packSlug.
	 * @param IUser|null $currentUser The actor.
	 *
	 * @return ImportPreview The created preview, in state pending.
	 *
	 * @throws InvalidArgumentException When a parameter is missing or unknown.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function createPreview(array $params, ?IUser $currentUser = null): ImportPreview {
		$filePath = (string)($params['filePath'] ?? '');

		if ($filePath === '' || is_file($filePath) === false) {
			throw new InvalidArgumentException('A source file is required to preview an import.');
		}

		$sourceName = (string)($params['sourceName'] ?? basename($filePath));
		$format = ($params['format'] ?? $this->reader->formatFromName(fileName: $sourceName));

		if ($format === null) {
			throw new InvalidArgumentException(
				'The source format could not be determined from "'.$sourceName.'". Pass format as one of: '
				.implode(', ', SourceRowReader::FORMATS).'.'
			);
		}

		$register = $this->resolveRegister(value: ($params['register'] ?? null));
		$schema = $this->resolveSchema(value: ($params['schema'] ?? null));
		$policy = ConflictPolicy::resolve(policy: ($params['policy'] ?? null));
		$matchKey = MatchResolver::normaliseKey(matchKey: ($params['matchKey'] ?? null));
		$packSlug = ($params['packSlug'] ?? null);

		if ($packSlug === '') {
			$packSlug = null;
		}

		if ($packSlug !== null) {
			// Resolving it here rather than at run time means an unknown
			// mapping is refused before a preview record exists to poll.
			$this->resolvePack(packSlug: (string)$packSlug, schema: $schema);
		}

		return $this->previewMapper->createFromArray(
			data: [
				'registerId' => $register->getId(),
				'schemaId' => $schema->getId(),
				'packSlug' => $packSlug,
				'policy' => $policy,
				'matchKey' => $matchKey,
				'sourceName' => $sourceName,
				'sourceFormat' => $format,
				'sourceHash' => $this->reader->hash(filePath: $filePath),
				'sourcePath' => $filePath,
				'state' => ImportPreview::STATE_PENDING,
				'createdBy' => $currentUser?->getUID(),
			]
		);
	}//end createPreview()

	/**
	 * Decide every row of a pending preview, and write nothing.
	 *
	 * @param ImportPreview $preview The pending preview.
	 *
	 * @return ImportPreview The preview, in state previewed.
	 *
	 * @throws InvalidArgumentException When the source can no longer be read.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function runPreview(ImportPreview $preview): ImportPreview {
		$preview->setState(ImportPreview::STATE_PREVIEWING);
		$this->previewMapper->persist(preview: $preview);

		try {
			$register = $this->resolveRegister(value: $preview->getRegisterId());
			$schema = $this->resolveSchema(value: $preview->getSchemaId());
			$pack = null;

			if ($preview->getPackSlug() !== null) {
				$pack = $this->resolvePack(packSlug: $preview->getPackSlug(), schema: $schema);
			}

			$rows = $this->reader->read(
				filePath: (string)$preview->getSourcePath(),
				format: (string)$preview->getSourceFormat()
			);

			// Re-deciding a preview replaces its decisions rather than adding
			// a second set beside them.
			$this->rowMapper->deleteByPreview(previewId: (int)$preview->getId());

			$preview->setTotal(count($rows));
			$preview->setProcessed(0);
			$preview->setToCreate(0);
			$preview->setToUpdate(0);
			$preview->setToSkip(0);
			$preview->setToRefuse(0);
			$this->previewMapper->persist(preview: $preview);

			foreach ($rows as $index => $row) {
				$decision = $this->decideRow(
					row: $row,
					preview: $preview,
					register: $register,
					schema: $schema,
					pack: $pack
				);

				$this->persistDecision(preview: $preview, row: $row, decision: $decision);
				$preview->setProcessed($index + 1);

				if ((($index + 1) % self::PROGRESS_EVERY) === 0) {
					$this->previewMapper->persist(preview: $preview);
				}
			}

			$preview->setState(ImportPreview::STATE_PREVIEWED);
			$preview->setReport($this->buildReport(preview: $preview));

			return $this->previewMapper->persist(preview: $preview);
		} catch (Throwable $exception) {
			$preview->setState(ImportPreview::STATE_FAILED);
			$preview->setReport(['error' => $exception->getMessage()]);
			$this->previewMapper->persist(preview: $preview);

			throw $exception;
		} finally {
			// The decisions are kept, the file is not: a staged copy of a
			// municipal migration file sitting in the temp directory after the
			// preview is done is data nobody asked to keep.
			$this->releaseSource(preview: $preview);
		}//end try
	}//end runPreview()

	/**
	 * Remove a staged copy of the source, and forget where it was.
	 *
	 * @param ImportPreview $preview The preview.
	 *
	 * @return void
	 */
	private function releaseSource(ImportPreview $preview): void {
		$path = $preview->getSourcePath();

		if ($this->reader->isStaged(filePath: $path) === false) {
			return;
		}

		if (is_file((string)$path) === true && unlink((string)$path) === false) {
			$this->logger->warning(
				message: '[ImportPreviewService] The staged source could not be removed',
				context: ['previewId' => $preview->getId()]
			);
		}

		$preview->setSourcePath(null);
		$this->previewMapper->persist(preview: $preview);
	}//end releaseSource()

	/**
	 * Create and run a preview in one call.
	 *
	 * @param array<string, mixed> $params Preview parameters.
	 * @param IUser|null $currentUser The actor.
	 *
	 * @return ImportPreview The preview, in state previewed.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function preview(array $params, ?IUser $currentUser = null): ImportPreview {
		return $this->runPreview(preview: $this->createPreview(params: $params, currentUser: $currentUser));
	}//end preview()

	/**
	 * Apply the decisions a preview made.
	 *
	 * The source hash is required, not optional: a commit that cannot say
	 * which file it is committing cannot claim the decisions still describe
	 * it. A hash that differs from the preview's is refused with nothing
	 * written (D-1).
	 *
	 * @param ImportPreview $preview The previewed preview.
	 * @param string|null $sourceHash The sha256 of the file being committed.
	 * @param IUser|null $currentUser The actor.
	 *
	 * @return ImportPreview The preview, in state committed.
	 *
	 * @throws ImportPreviewRefusedException When the state or the hash refuses the commit.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function commit(ImportPreview $preview, ?string $sourceHash, ?IUser $currentUser = null): ImportPreview {
		$this->assertCommittable(preview: $preview, sourceHash: $sourceHash);

		$register = $this->resolveRegister(value: $preview->getRegisterId());
		$schema = $this->resolveSchema(value: $preview->getSchemaId());

		$preview->setState(ImportPreview::STATE_COMMITTING);
		$this->previewMapper->persist(preview: $preview);

		try {
			while (true) {
				$batch = $this->rowMapper->findPendingWrites(
					previewId: (int)$preview->getId(),
					limit: self::COMMIT_BATCH
				);

				if ($batch === []) {
					break;
				}

				$this->writeBatch(
					preview: $preview,
					batch: $batch,
					register: $register,
					schema: $schema,
					currentUser: $currentUser
				);

				$this->previewMapper->persist(preview: $preview);
			}

			$preview->setState(ImportPreview::STATE_COMMITTED);
			$preview->setReport($this->buildReport(preview: $preview));

			return $this->previewMapper->persist(preview: $preview);
		} catch (Throwable $exception) {
			$preview->setState(ImportPreview::STATE_FAILED);
			$preview->setReport(['error' => $exception->getMessage()]);
			$this->previewMapper->persist(preview: $preview);

			throw $exception;
		}//end try
	}//end commit()

	/**
	 * Refuse a commit that cannot honestly run.
	 *
	 * @param ImportPreview $preview The preview.
	 * @param string|null $sourceHash The hash the caller offered.
	 *
	 * @return void
	 *
	 * @throws ImportPreviewRefusedException When the commit is refused.
	 */
	private function assertCommittable(ImportPreview $preview, ?string $sourceHash): void {
		if ($preview->isCommittable() === false) {
			throw new ImportPreviewRefusedException(
				'This import is in state "'.(string)$preview->getState().'". Only a completed preview can be committed.'
			);
		}

		if ($sourceHash === null || $sourceHash === '') {
			throw new ImportPreviewRefusedException(
				'A commit must name the source file it is committing. Send the file again, or pass its sha256 as sourceHash.'
			);
		}

		if (hash_equals((string)$preview->getSourceHash(), $sourceHash) === false) {
			throw new ImportPreviewRefusedException(
				'The source file changed since it was previewed, so the preview no longer describes it. '
				.'Preview the new file and commit that.'
			);
		}
	}//end assertCommittable()

	/**
	 * Write one batch of decided rows.
	 *
	 * @param ImportPreview $preview The preview being committed.
	 * @param array<int, ImportPreviewRow> $batch The rows to write.
	 * @param Register $register The target register.
	 * @param Schema $schema The target schema.
	 * @param IUser|null $currentUser The actor.
	 *
	 * @return void
	 */
	private function writeBatch(
		ImportPreview $preview,
		array $batch,
		Register $register,
		Schema $schema,
		?IUser $currentUser,
	): void {
		$objects = [];
		foreach ($batch as $row) {
			$objects[] = ($row->getPayload() ?? []);
		}

		try {
			$this->objectService->saveObjects(
				objects: $objects,
				register: $register,
				schema: $schema,
				validation: false,
				events: false
			);

			$this->stampApplied(preview: $preview, rows: $batch);

			return;
		} catch (Throwable $exception) {
			// A batch that threw says nothing about which row was at fault, and
			// a per-row outcome is the whole point of a previewed import. Walk
			// the batch one row at a time so every row gets its own verdict.
			$this->logger->warning(
				message: '[ImportPreviewService] Batch write failed, isolating rows: '.$exception->getMessage(),
				context: ['previewId' => $preview->getId()]
			);
		}//end try

		foreach ($batch as $row) {
			$this->writeRow(
				preview: $preview,
				row: $row,
				register: $register,
				schema: $schema,
				currentUser: $currentUser
			);
		}
	}//end writeBatch()

	/**
	 * Write one decided row, and record its own outcome.
	 *
	 * @param ImportPreview $preview The preview being committed.
	 * @param ImportPreviewRow $row The row.
	 * @param Register $register The target register.
	 * @param Schema $schema The target schema.
	 * @param IUser|null $currentUser The actor.
	 *
	 * @return void
	 */
	private function writeRow(
		ImportPreview $preview,
		ImportPreviewRow $row,
		Register $register,
		Schema $schema,
		?IUser $currentUser,
	): void {
		try {
			$this->objectService->saveObject(
				object: ($row->getPayload() ?? []),
				register: $register,
				schema: $schema,
				_validation: false,
				currentUser: $currentUser
			);

			$this->stampApplied(preview: $preview, rows: [$row]);
		} catch (Throwable $exception) {
			$row->setReason('The write failed: '.$exception->getMessage());
			$row->setAppliedAt(new DateTime());
			$this->rowMapper->persist(row: $row);
			$preview->setFailed(($preview->getFailed() + 1));
		}//end try
	}//end writeRow()

	/**
	 * Stamp rows as written, which is what a retry reads.
	 *
	 * @param ImportPreview $preview The preview being committed.
	 * @param array<int, ImportPreviewRow> $rows The rows that were written.
	 *
	 * @return void
	 */
	private function stampApplied(ImportPreview $preview, array $rows): void {
		$now = new DateTime();

		foreach ($rows as $row) {
			$row->setAppliedAt($now);
			$this->rowMapper->persist(row: $row);
		}

		$preview->setApplied(($preview->getApplied() + count($rows)));
	}//end stampApplied()

	/**
	 * Decide one row: map it, transform it, validate it, match it, and apply
	 * the policy. Nothing here writes.
	 *
	 * @param array{row: int, data: array<string, mixed>} $row The source row.
	 * @param ImportPreview $preview The preview.
	 * @param Register $register The target register.
	 * @param Schema $schema The target schema.
	 * @param array<string, mixed>|null $pack The saved mapping, if any.
	 *
	 * @return array{decision: string, reason: string|null, targetUuid: string|null,
	 *               candidates: array<int, string>, payload: array<string, mixed>} The decision.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One row, five ways it can
	 * be refused, each naming its own reason.
	 */
	private function decideRow(
		array $row,
		ImportPreview $preview,
		Register $register,
		Schema $schema,
		?array $pack,
	): array {
		$data = $row['data'];

		if ($pack !== null) {
			$mapped = $this->mappingEngine->mapRow(pack: $pack, sourceRow: $data, rowNumber: $row['row']);

			if (($mapped['errors'] ?? []) !== []) {
				$messages = [];
				foreach ($mapped['errors'] as $error) {
					$messages[] = 'source column "'.$error['source'].'": '.$error['message'];
				}

				return $this->refusal(reason: 'The column mapping refused this row. '.implode('; ', $messages));
			}

			$data = $mapped['data'];
		}

		try {
			$object = $this->importService->transformCsvRowToObject(
				rowData: $data,
				register: $register,
				schema: $schema,
				currentUser: null
			);
		} catch (Throwable $exception) {
			return $this->refusal(reason: 'The row could not be mapped onto the schema: '.$exception->getMessage());
		}

		$validation = $this->validateObject->validateObject(object: $object, schema: $schema);

		if ($validation->isValid() === false) {
			return $this->refusal(
				reason: 'The row does not validate against the schema: '
					.$this->validateObject->generateErrorMessage(result: $validation),
				payload: $object
			);
		}

		try {
			$candidates = $this->matchResolver->resolve(
				object: $object,
				matchKey: ($preview->getMatchKey() ?? []),
				register: $register,
				schema: $schema
			);
		} catch (MatchLookupFailedException $exception) {
			return $this->refusal(reason: $exception->getMessage(), payload: $object);
		}

		$decision = ConflictPolicy::decide(policy: (string)$preview->getPolicy(), candidates: $candidates);

		if ($decision['decision'] === ImportPreviewRow::DECISION_UPDATE) {
			$object['@self']['id'] = $decision['targetUuid'];
		}

		if ($decision['decision'] === ImportPreviewRow::DECISION_CREATE) {
			// A CREATE ROW GETS ITS UUID HERE, NOT AT WRITE TIME.
			//
			// writeBatch() hands the batch to saveObjects() and, on any throw,
			// walks the whole batch row by row on the stated assumption that a
			// throw means nothing was written. That does not hold underneath:
			// MagicBulkHandler::bulkUpsert() commits each CHUNK in its own
			// transaction and rolls back only the failing one, so when chunk 3
			// fails, chunks 1-2 are already durable. The row-by-row retry then
			// re-sent them - and a CREATE payload carried no id, so saveObject()
			// minted a NEW uuid and the committed rows were duplicated, then
			// reported as succeeded because for those rows the retry did work.
			//
			// UPDATE rows were never affected: their targetUuid above makes the
			// retry idempotent by uuid. This gives CREATE rows the same property
			// rather than inventing a second mechanism. `new ObjectEntity()`
			// honours a supplied uuid (SaveObject::createObject), so the retry
			// upserts the row it already wrote instead of adding a twin.
			//
			// It also closes the re-run half: findPendingWrites() still sees an
			// unstamped row after a failed commit, and a later commit() used to
			// duplicate it again. With the uuid on the stored payload, the
			// re-run targets the same object.
			$object['@self']['id'] = Uuid::v4()->toRfc4122();
		}

		return [
			'decision' => $decision['decision'],
			'reason' => $decision['reason'],
			'targetUuid' => $decision['targetUuid'],
			'candidates' => $candidates,
			'payload' => $object,
		];
	}//end decideRow()

	/**
	 * A refusal with its reason, in the shape decideRow returns.
	 *
	 * @param string $reason Why the row is refused.
	 * @param array<string, mixed> $payload The object as far as it got.
	 *
	 * @return array{decision: string, reason: string, targetUuid: null, candidates: array<int, string>, payload: array<string, mixed>} The refusal.
	 */
	private function refusal(string $reason, array $payload = []): array {
		return [
			'decision' => ImportPreviewRow::DECISION_REFUSE,
			'reason' => $reason,
			'targetUuid' => null,
			'candidates' => [],
			'payload' => $payload,
		];
	}//end refusal()

	/**
	 * Persist one decision and move the preview's counts.
	 *
	 * @param ImportPreview $preview The preview.
	 * @param array{row: int, data: array<string, mixed>} $row The source row.
	 * @param array<string, mixed> $decision The decision decideRow returned.
	 *
	 * @return void
	 */
	private function persistDecision(ImportPreview $preview, array $row, array $decision): void {
		$entity = new ImportPreviewRow();
		$entity->setPreviewId((int)$preview->getId());
		$entity->setRowNumber((int)$row['row']);
		$entity->setDecision((string)$decision['decision']);
		$entity->setReason($decision['reason']);
		$entity->setTargetUuid($decision['targetUuid']);
		$entity->setCandidates($decision['candidates']);
		$entity->setPayload($decision['payload']);

		$this->rowMapper->persist(row: $entity);

		match ($decision['decision']) {
			ImportPreviewRow::DECISION_CREATE => $preview->setToCreate(($preview->getToCreate() + 1)),
			ImportPreviewRow::DECISION_UPDATE => $preview->setToUpdate(($preview->getToUpdate() + 1)),
			ImportPreviewRow::DECISION_SKIP => $preview->setToSkip(($preview->getToSkip() + 1)),
			default => $preview->setToRefuse(($preview->getToRefuse() + 1)),
		};
	}//end persistDecision()

	/**
	 * The summary an operator reads, with every refusal's reason.
	 *
	 * @param ImportPreview $preview The preview.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function buildReport(ImportPreview $preview): array {
		$refusals = [];

		foreach ($this->rowMapper->findByPreview(
			previewId: (int)$preview->getId(),
			decision: ImportPreviewRow::DECISION_REFUSE
		) as $row) {
			$refusals[] = [
				'row' => $row->getRowNumber(),
				'reason' => $row->getReason(),
				'candidates' => ($row->getCandidates() ?? []),
			];
		}

		return [
			'policy' => $preview->getPolicy(),
			'matchKey' => ($preview->getMatchKey() ?? []),
			'counts' => [
				'created' => $preview->getToCreate(),
				'updated' => $preview->getToUpdate(),
				'skipped' => $preview->getToSkip(),
				'refused' => $preview->getToRefuse(),
			],
			'applied' => $preview->getApplied(),
			'failed' => $preview->getFailed(),
			'refusals' => $refusals,
		];
	}//end buildReport()

	/**
	 * Resolve a register by id, uuid or slug.
	 *
	 * @param mixed $value The register reference.
	 *
	 * @return Register The register.
	 *
	 * @throws InvalidArgumentException When it resolves to nothing.
	 */
	private function resolveRegister(mixed $value): Register {
		if ($value instanceof Register) {
			return $value;
		}

		if ($value === null || $value === '') {
			throw new InvalidArgumentException('A register is required to preview an import.');
		}

		try {
			return $this->registerMapper->find($value);
		} catch (DoesNotExistException $exception) {
			throw new InvalidArgumentException('Register "'.(string)$value.'" was not found.', 0, $exception);
		}
	}//end resolveRegister()

	/**
	 * Resolve a schema by id, uuid or slug.
	 *
	 * @param mixed $value The schema reference.
	 *
	 * @return Schema The schema.
	 *
	 * @throws InvalidArgumentException When it resolves to nothing.
	 */
	private function resolveSchema(mixed $value): Schema {
		if ($value instanceof Schema) {
			return $value;
		}

		if ($value === null || $value === '') {
			throw new InvalidArgumentException('A schema is required to preview an import.');
		}

		try {
			return $this->schemaMapper->find($value);
		} catch (DoesNotExistException $exception) {
			throw new InvalidArgumentException('Schema "'.(string)$value.'" was not found.', 0, $exception);
		}
	}//end resolveSchema()

	/**
	 * Resolve a saved column mapping, and refuse one that names a property
	 * the schema does not have.
	 *
	 * @param string $packSlug The mapping's slug.
	 * @param Schema $schema The schema it is applied against.
	 *
	 * @return array<string, mixed> The mapping definition.
	 *
	 * @throws InvalidArgumentException When the mapping is unknown or does not fit the schema.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	private function resolvePack(string $packSlug, Schema $schema): array {
		try {
			$definition = $this->packService->findByPackSlug(packSlug: $packSlug)->getDefinitionArray();
		} catch (DoesNotExistException $exception) {
			throw new InvalidArgumentException('Column mapping "'.$packSlug.'" was not found.', 0, $exception);
		}

		$unknown = SchemaMappingCheck::unknownTargets(definition: $definition, schema: $schema);

		if ($unknown !== []) {
			$noun = 'properties';
			if (count($unknown) === 1) {
				$noun = 'a property';
			}

			throw new InvalidArgumentException(
				'Column mapping "'.$packSlug.'" maps onto '.$noun
				.' the schema does not have: '.implode(', ', $unknown).'.'
			);
		}

		return $definition;
	}//end resolvePack()
}//end class
