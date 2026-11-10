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
 * {@see \OCA\OpenRegister\Service\ImportService::transformCsvRow()},
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
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use Throwable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\SavePolicy;
use OCA\OpenRegister\Service\Object\SaveActor;

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
class ImportPreviewService implements ImportPreviewServiceInterface {

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
	 * @param ImportPreviewStore $store The preview and row persistence, the file reader and the logger.
	 * @param ImportPreviewMatching $matching The match lookup, the saved column mapping and the row transform.
	 * @param ImportPreviewTargets $targets The register and schema lookups, schema validation and the write path.
	 */
	public function __construct(
		private readonly ImportPreviewStore $store,
		private readonly ImportPreviewMatching $matching,
		private readonly ImportPreviewTargets $targets,
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
		$filePath = ImportSourceCheck::requireFile(params: $params);
		$sourceName = (string)($params['sourceName'] ?? basename($filePath));
		$format = $this->sourceFormat(params: $params, sourceName: $sourceName);

		$register = $this->resolveRegister(value: ($params['register'] ?? null));
		$schema = $this->resolveSchema(value: ($params['schema'] ?? null));
		$policy = ConflictPolicy::resolve(policy: ($params['policy'] ?? null));
		$matchKey = MatchResolver::normaliseKey(matchKey: ($params['matchKey'] ?? null));
		$packSlug = $this->checkedPackSlug(packSlug: ($params['packSlug'] ?? null), schema: $schema);

		return $this->store->previewMapper->createFromArray(
			data: [
				'registerId' => $register->getId(),
				'schemaId' => $schema->getId(),
				'packSlug' => $packSlug,
				'policy' => $policy,
				'matchKey' => $matchKey,
				'sourceName' => $sourceName,
				'sourceFormat' => $format,
				'sourceHash' => $this->store->reader->hash(filePath: $filePath),
				'sourcePath' => $filePath,
				'state' => ImportPreview::STATE_PENDING,
				'createdBy' => $currentUser?->getUID(),
			]
		);
	}//end createPreview()

	/**
	 * The declared source format, or the one the source name implies.
	 *
	 * @param array<string, mixed> $params The preview parameters.
	 * @param string $sourceName The source's name.
	 *
	 * @return mixed The format.
	 *
	 * @throws InvalidArgumentException When no format is declared or implied.
	 */
	private function sourceFormat(array $params, string $sourceName): mixed {
		$format = ($params['format'] ?? $this->store->reader->formatFromName(fileName: $sourceName));

		if ($format === null) {
			throw new InvalidArgumentException(
				'The source format could not be determined from "'.$sourceName.'". Pass format as one of: '
				.implode(', ', SourceRowReader::FORMATS).'.'
			);
		}

		return $format;
	}//end sourceFormat()

	/**
	 * The saved column mapping a preview names, checked against the schema.
	 *
	 * @param mixed $packSlug The declared mapping slug; an empty string is none.
	 * @param Schema $schema The target schema.
	 *
	 * @return mixed The slug, or null when none is named.
	 *
	 * @throws InvalidArgumentException When the mapping is unknown or does not fit the schema.
	 */
	private function checkedPackSlug(mixed $packSlug, Schema $schema): mixed {
		if ($packSlug === '' || $packSlug === null) {
			return null;
		}

		// Resolving it here rather than at run time means an unknown
		// mapping is refused before a preview record exists to poll.
		$this->resolvePack(packSlug: (string)$packSlug, schema: $schema);

		return $packSlug;
	}//end checkedPackSlug()

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
		$this->store->previewMapper->persist(preview: $preview);

		try {
			$register = $this->resolveRegister(value: $preview->getRegisterId());
			$schema = $this->resolveSchema(value: $preview->getSchemaId());
			$pack = null;

			if ($preview->getPackSlug() !== null) {
				$pack = $this->resolvePack(packSlug: $preview->getPackSlug(), schema: $schema);
			}

			$rows = $this->store->reader->read(
				filePath: (string)$preview->getSourcePath(),
				format: (string)$preview->getSourceFormat()
			);

			// Re-deciding a preview replaces its decisions rather than adding
			// a second set beside them.
			$this->store->rowMapper->deleteByPreview(previewId: (int)$preview->getId());

			$preview->setTotal(count($rows));
			$preview->setProcessed(0);
			$preview->setToCreate(0);
			$preview->setToUpdate(0);
			$preview->setToSkip(0);
			$preview->setToRefuse(0);
			$this->store->previewMapper->persist(preview: $preview);

			$run = new ImportRun(
				preview: $preview,
				destination: new ImportDestination(register: $register, schema: $schema),
				pack: $pack
			);
			foreach ($rows as $index => $row) {
				$this->persistDecision(run: $run, row: $row);
				$preview->setProcessed($index + 1);

				if ((($index + 1) % self::PROGRESS_EVERY) === 0) {
					$this->store->previewMapper->persist(preview: $preview);
				}
			}

			$preview->setState(ImportPreview::STATE_PREVIEWED);
			$preview->setReport($this->buildReport(preview: $preview));

			return $this->store->previewMapper->persist(preview: $preview);
		} catch (Throwable $exception) {
			$preview->setState(ImportPreview::STATE_FAILED);
			$preview->setReport(['error' => $exception->getMessage()]);
			$this->store->previewMapper->persist(preview: $preview);

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

		if ($this->store->reader->isStaged(filePath: $path) === false) {
			return;
		}

		if (is_file((string)$path) === true && unlink((string)$path) === false) {
			$this->store->logger->warning(
				message: '[ImportPreviewService] The staged source could not be removed',
				context: ['previewId' => $preview->getId()]
			);
		}

		$preview->setSourcePath(null);
		$this->store->previewMapper->persist(preview: $preview);
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
		return $this->applyDecisions(run: $this->commitRun(preview: $preview, currentUser: $currentUser));
	}//end commit()

	/**
	 * The run a commit writes: the preview, its resolved destination and the actor.
	 *
	 * @param ImportPreview $preview The previewed preview.
	 * @param IUser|null $currentUser The actor.
	 *
	 * @return ImportRun The run.
	 *
	 * @throws InvalidArgumentException When the register or schema no longer resolves.
	 */
	private function commitRun(ImportPreview $preview, ?IUser $currentUser): ImportRun {
		$register = $this->resolveRegister(value: $preview->getRegisterId());
		$schema = $this->resolveSchema(value: $preview->getSchemaId());
		return new ImportRun(
			preview: $preview,
			destination: new ImportDestination(register: $register, schema: $schema),
			currentUser: $currentUser
		);
	}//end commitRun()

	/**
	 * Write every decided row of a committable preview and record the outcome on it.
	 *
	 * @param ImportRun $run The preview being committed, its destination and actor.
	 *
	 * @return ImportPreview The preview, in state committed.
	 *
	 * @throws Throwable When the write fails; the preview is left failed.
	 */
	private function applyDecisions(ImportRun $run): ImportPreview {
		$preview = $run->preview;
		$preview->setState(ImportPreview::STATE_COMMITTING);
		$this->store->previewMapper->persist(preview: $preview);

		try {
			$this->writePendingBatches(run: $run);

			$preview->setState(ImportPreview::STATE_COMMITTED);
			$preview->setReport($this->buildReport(preview: $preview));

			return $this->store->previewMapper->persist(preview: $preview);
		} catch (Throwable $exception) {
			$preview->setState(ImportPreview::STATE_FAILED);
			$preview->setReport(['error' => $exception->getMessage()]);
			$this->store->previewMapper->persist(preview: $preview);

			throw $exception;
		}//end try
	}//end applyDecisions()

	/**
	 * Write every pending row, one batch at a time, flushing the counts after each.
	 *
	 * @param ImportRun $run The preview being committed, its destination and actor.
	 *
	 * @return void
	 */
	private function writePendingBatches(ImportRun $run): void {
		$preview = $run->preview;
		while (true) {
			$batch = $this->store->rowMapper->findPendingWrites(
				previewId: (int)$preview->getId(),
				limit: self::COMMIT_BATCH
			);

			if ($batch === []) {
				return;
			}

			$this->writeBatch(run: $run, batch: $batch);

			$this->store->previewMapper->persist(preview: $preview);
		}
	}//end writePendingBatches()

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

		ImportSourceCheck::assertSameSource(preview: $preview, sourceHash: $sourceHash);
	}//end assertCommittable()

	/**
	 * Write one batch of decided rows.
	 *
	 * @param ImportRun $run The preview being committed, its destination and actor.
	 * @param array<int, ImportPreviewRow> $batch The rows to write.
	 *
	 * @return void
	 */
	private function writeBatch(ImportRun $run, array $batch): void {
		if ($this->writeBulk(run: $run, batch: $batch) === true) {
			return;
		}

		foreach ($batch as $row) {
			$this->writeRow(run: $run, row: $row);
		}
	}//end writeBatch()

	/**
	 * Write a batch in one bulk save, and stamp each row's outcome.
	 *
	 * @param ImportRun $run The preview being committed, its destination and actor.
	 * @param array<int, ImportPreviewRow> $batch The rows to write.
	 *
	 * @return bool True when every row got its outcome; false when the batch
	 *              must be walked row by row instead.
	 */
	private function writeBulk(ImportRun $run, array $batch): bool {
		$preview = $run->preview;
		$destination = $run->destination;
		$objects = ImportRowPayload::payloadsOf(batch: $batch);

		try {
			$result = $this->targets->objectService->saveObjects(
				objects: $objects,
				register: $destination->register,
				schema: $destination->schema,
				validation: false,
				events: false
			);

			$refused = $this->refusalsIn(batch: $batch, invalid: ($result['invalid'] ?? []));
			if ($refused !== null) {
				$this->stampRefusedRows(preview: $preview, rows: $this->withRefusalReasons(batch: $batch, refused: $refused));
				$this->stampApplied(preview: $preview, rows: array_diff_key($batch, $refused));

				return true;
			}
		} catch (Throwable $exception) {
			// A batch that threw says nothing about which row was at fault, and
			// a per-row outcome is the whole point of a previewed import. Walk
			// the batch one row at a time so every row gets its own verdict.
			$this->store->logger->warning(
				message: '[ImportPreviewService] Batch write failed, isolating rows: '.$exception->getMessage(),
				context: ['previewId' => $preview->getId()]
			);
		}//end try

		return false;
	}//end writeBulk()

	/**
	 * The refused rows of a bulk save, each carrying the reason it was refused.
	 *
	 * @param array<int, ImportPreviewRow> $batch The rows sent.
	 * @param array<int, string> $refused The reason per refused row, keyed like the batch.
	 *
	 * @return array<int, ImportPreviewRow> The refused rows, in refusal order.
	 */
	private function withRefusalReasons(array $batch, array $refused): array {
		$rows = [];
		foreach ($refused as $key => $reason) {
			$batch[$key]->setReason('The write failed: '.$reason);
			$rows[] = $batch[$key];
		}

		return $rows;
	}//end withRefusalReasons()

	/**
	 * Stamp the refused rows of a bulk save as failed.
	 *
	 * @param ImportPreview $preview The preview being committed.
	 * @param array<int, ImportPreviewRow> $rows The refused rows, reasons set.
	 *
	 * @return void
	 */
	private function stampRefusedRows(ImportPreview $preview, array $rows): void {
		foreach ($rows as $row) {
			$this->stampFailed(preview: $preview, row: $row);
		}
	}//end stampRefusedRows()

	/**
	 * Write one decided row, and record its own outcome.
	 *
	 * @param ImportRun $run The preview being committed, its destination and actor.
	 * @param ImportPreviewRow $row The row.
	 *
	 * @return void
	 */
	private function writeRow(ImportRun $run, ImportPreviewRow $row): void {
		try {
			$this->targets->objectService->saveObject(
				object: ($row->getPayload() ?? []),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $run->destination->register, schema: $run->destination->schema),
					policy: new SavePolicy(validation: false),
					actor: new SaveActor(currentUser: $run->currentUser)
				)
			);

			$this->stampApplied(preview: $run->preview, rows: [$row]);
		} catch (Throwable $exception) {
			$row->setReason('The write failed: '.$exception->getMessage());
			$this->stampFailed(preview: $run->preview, row: $row);
		}//end try
	}//end writeRow()

	/**
	 * The reason per batch row that the bulk save refused.
	 *
	 * @param array<int, ImportPreviewRow> $batch   The rows sent.
	 * @param array<int, mixed>            $invalid The refused items of the save result.
	 *
	 * @return array<int, string>|null The reasons, keyed like the batch, or null when a
	 *                                 refusal matches no row, so no row can be stamped as written.
	 */
	private function refusalsIn(array $batch, array $invalid): ?array {
		$refused = [];
		foreach ($invalid as $item) {
			$key = ImportRowPayload::batchKeyOf(batch: $batch, object: ($item['object'] ?? $item));
			if ($key === null) {
				$this->store->logger->warning(message: '[ImportPreviewService] A refused row matches no row of its batch');
				return null;
			}

			$refused[$key] = (string)($item['error'] ?? 'Validation failed');
		}

		return $refused;
	}//end refusalsIn()

	/**
	 * Stamp a row that was not written, with the reason it already carries.
	 *
	 * @param ImportPreview    $preview The preview being committed.
	 * @param ImportPreviewRow $row     The row.
	 *
	 * @return void
	 */
	private function stampFailed(ImportPreview $preview, ImportPreviewRow $row): void {
		$row->setAppliedAt(new DateTime());
		$this->store->rowMapper->persist(row: $row);
		$preview->setFailed(($preview->getFailed() + 1));
	}//end stampFailed()

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
			$this->store->rowMapper->persist(row: $row);
		}

		$preview->setApplied(($preview->getApplied() + count($rows)));
	}//end stampApplied()

	/**
	 * Decide one row: map it, transform it, validate it, match it, and apply
	 * the policy. Nothing here writes.
	 *
	 * @param array{row: int, data: array<string, mixed>} $row The source row.
	 * @param ImportRun $run The preview, its destination and saved mapping, if any.
	 *
	 * @return array{decision: string, reason: string|null, targetUuid: string|null,
	 *               candidates: array<int, string>, payload: array<string, mixed>} The decision.
	 *
	 */
	private function decideRow(array $row, ImportRun $run): array {
		$data = $row['data'];
		$pack = $run->pack;

		if ($pack !== null) {
			$mapped = $this->matching->mappingEngine->mapRow(pack: $pack, sourceRow: $data, rowNumber: $row['row']);

			if (($mapped['errors'] ?? []) !== []) {
				return ImportRowPayload::refusal(reason: ImportRowPayload::mappingRefusal(errors: $mapped['errors']));
			}

			$data = $mapped['data'];
		}

		return $this->decideMappedRow(data: $data, run: $run);
	}//end decideRow()

	/**
	 * Decide a row after its column mapping: transform it and validate it.
	 *
	 * @param array<string, mixed> $data The (mapped) source row data.
	 * @param ImportRun $run The preview and its destination.
	 *
	 * @return array{decision: string, reason: string|null, targetUuid: string|null,
	 *               candidates: array<int, string>, payload: array<string, mixed>} The decision.
	 */
	private function decideMappedRow(array $data, ImportRun $run): array {
		$destination = $run->destination;
		try {
			$object = $this->matching->importService->transformCsvRow(
				rowData: $data,
				target: new RowImportTarget(table: new RegisterSchema(register: $destination->register, schema: $destination->schema))
			);
		} catch (Throwable $exception) {
			return ImportRowPayload::refusal(reason: 'The row could not be mapped onto the schema: '.$exception->getMessage());
		}

		$validation = $this->targets->validateObject->validateObject(object: $object, schema: $destination->schema);

		if ($validation->isValid() === false) {
			return ImportRowPayload::refusal(
				reason: 'The row does not validate against the schema: '
					.$this->targets->validateObject->generateErrorMessage(result: $validation),
				payload: $object
			);
		}

		return $this->decideValidObject(object: $object, run: $run);
	}//end decideMappedRow()

	/**
	 * Decide a valid object: match it, and apply the policy.
	 *
	 * @param array<string, mixed> $object The transformed, valid object.
	 * @param ImportRun $run The preview and its destination.
	 *
	 * @return array{decision: string, reason: string|null, targetUuid: string|null,
	 *               candidates: array<int, string>, payload: array<string, mixed>} The decision.
	 */
	private function decideValidObject(array $object, ImportRun $run): array {
		$preview = $run->preview;
		$destination = $run->destination;
		try {
			$candidates = $this->matching->matchResolver->matchesOf(
				query: new MatchQuery(
					object: $object,
					matchKey: ($preview->getMatchKey() ?? []),
					table: new RegisterSchema(register: $destination->register, schema: $destination->schema)
				)
			);
		} catch (MatchLookupFailedException $exception) {
			return ImportRowPayload::refusal(reason: $exception->getMessage(), payload: $object);
		}

		$decision = ConflictPolicy::decide(policy: (string)$preview->getPolicy(), candidates: $candidates);

		return [
			'decision' => $decision['decision'],
			'reason' => $decision['reason'],
			'targetUuid' => $decision['targetUuid'],
			'candidates' => $candidates,
			'payload' => ImportRowPayload::withTargetId(object: $object, decision: $decision),
		];
	}//end decideValidObject()

	/**
	 * Decide one row, persist the decision and move the preview's counts.
	 *
	 * @param ImportRun $run The preview, its destination and saved mapping, if any.
	 * @param array{row: int, data: array<string, mixed>} $row The source row.
	 *
	 * @return void
	 */
	private function persistDecision(ImportRun $run, array $row): void {
		$decision = $this->decideRow(row: $row, run: $run);
		$preview = $run->preview;

		$entity = new ImportPreviewRow();
		$entity->setPreviewId((int)$preview->getId());
		$entity->setSourceRow((int)$row['row']);
		$entity->setDecision((string)$decision['decision']);
		$entity->setReason($decision['reason']);
		$entity->setTargetUuid($decision['targetUuid']);
		$entity->setCandidates($decision['candidates']);
		$entity->setPayload($decision['payload']);

		$this->store->rowMapper->persist(row: $entity);

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

		foreach ($this->store->rowMapper->findByPreview(
			previewId: (int)$preview->getId(),
			query: ListQuery::unbounded(filters: ['decision' => ImportPreviewRow::DECISION_REFUSE])
		) as $row) {
			$refusals[] = [
				'row' => $row->getSourceRow(),
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
			return $this->targets->catalog->registers()->find($value);
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
			return $this->targets->catalog->schemas()->find($value);
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
			$definition = $this->matching->packService->findByPackSlug(packSlug: $packSlug)->getDefinitionArray();
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
