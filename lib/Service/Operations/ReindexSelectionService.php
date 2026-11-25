<?php

/**
 * ReindexSelectionService: re-extract the text, file text and embeddings of a selection of objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCA\OpenRegister\BackgroundJob\ConfigurationJobHandlesInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserManager;
use RuntimeException;
use Throwable;

/**
 * Resolve, refuse or queue a selection, and run it object by object.
 *
 * The selection is resolved twice: once when it is posted, to refuse an
 * empty or oversized one with 400 while the administrator is still waiting,
 * and again when the run starts, because the objects it matches may have
 * changed in between and the run reindexes what is there now.
 *
 * The selection itself is kept in app config under the run's key rather than
 * in the job argument: a list of ids does not fit the job list's argument
 * column, and the run removes the key when it ends.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
 */
class ReindexSelectionService implements ReindexSelectionServiceInterface {

	/**
	 * Objects per batch, and how often the run writes its progress.
	 *
	 * @var integer
	 */
	public const PROGRESS_EVERY = 100;

	/**
	 * The app config key prefix the stored selections live under.
	 *
	 * @var string
	 */
	public const STORE_PREFIX = 'reindex_selection_';

	/**
	 * Constructor.
	 *
	 * @param BulkSelectionResolver          $resolver  Resolves an id list or a scoped query to uuids, and loads them.
	 * @param ObjectOperationsInterface      $objects   Runs the run's reads as the administrator who started it.
	 * @param TextExtractionServiceInterface $extractor Re-extracts an object's and a file's text.
	 * @param FileMapperInterface            $files     The files attached to an object.
	 * @param IAppConfig                     $config    Holds the selection between start and run.
	 * @param IJobList                       $jobs      Queues the run.
	 * @param IUserManager                   $users     Finds the administrator when the run starts.
	 */
	public function __construct(
		private readonly BulkSelectionResolver $resolver,
		private readonly ObjectOperationsInterface $objects,
		private readonly TextExtractionServiceInterface $extractor,
		private readonly FileMapperInterface $files,
		private readonly IAppConfig $config,
		private readonly IJobList $jobs,
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * Resolve the selection, refuse it when it is empty or too large, and queue its run.
	 *
	 * @param array<string, mixed> $params The request parameters: `register`, `schema`, `filters`, `ids`.
	 * @param string               $actor  The administrator starting it.
	 *
	 * @return array{run: string, kind: string, total: int} The queued run's key, the selection kind and the objects it holds.
	 *
	 * @throws ReindexRefusedException When the request is not exactly one selection, selects nothing or selects too much.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
	 */
	public function start(array $params, string $actor): array {
		$selection = ReindexSelection::fromRequest(params: $params);
		$total = count($this->uuidsOf(selection: $selection));

		if ($total > self::MAX_OBJECTS) {
			throw new ReindexRefusedException(
				message: 'This selection holds more than '.number_format(self::MAX_OBJECTS).' objects, the most one reindex may take. Rebuild the whole index instead.'
			);
		}

		if ($total === 0) {
			throw new ReindexRefusedException(message: 'This selection holds no objects, so there is nothing to reindex.');
		}

		$runKey = bin2hex(random_bytes(8));
		$this->config->setValueArray(app: AppIdentity::APP_ID, key: self::STORE_PREFIX.$runKey, value: $selection->toArray(), lazy: true);
		$this->jobs->add(job: ConfigurationJobHandlesInterface::REINDEX_SELECTION, argument: ['run' => $runKey, 'actor' => $actor]);

		return ['run' => $runKey, 'kind' => $selection->kind, 'total' => $total];

	}//end start()

	/**
	 * Reindex the selection a queued run stored, as the administrator who started it.
	 *
	 * @param string   $runKey     The key start() returned.
	 * @param string   $actor      The administrator who started it.
	 * @param callable $onProgress Called with the progress details at the start, every 100 objects and at the end.
	 *
	 * @return ReindexProgress The counts at the end.
	 *
	 * @throws RuntimeException When the stored selection or the administrator is gone.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
	 */
	public function run(string $runKey, string $actor, callable $onProgress): ReindexProgress {
		$key = self::STORE_PREFIX.$runKey;
		$stored = $this->config->getValueArray(app: AppIdentity::APP_ID, key: $key, default: [], lazy: true);

		try {
			if ($stored === []) {
				throw new RuntimeException('The selection of reindex run '.$runKey.' is no longer stored.');
			}

			$user = $this->users->get($actor);
			if ($user === null) {
				throw new RuntimeException('The account that started reindex run '.$runKey.' no longer exists.');
			}

			$selection = ReindexSelection::fromArray(stored: $stored);

			return $this->objects->runAs(
				$user,
				fn (): ReindexProgress => $this->reindexAll(selection: $selection, onProgress: $onProgress)
			);
		} finally {
			$this->config->deleteKey(app: AppIdentity::APP_ID, key: $key);
		}

	}//end run()

	/**
	 * Reindex every object the selection holds now, batch by batch, reporting after each batch.
	 *
	 * @param ReindexSelection $selection  The selection.
	 * @param callable         $onProgress Called with the progress details.
	 *
	 * @return ReindexProgress The counts at the end.
	 */
	private function reindexAll(ReindexSelection $selection, callable $onProgress): ReindexProgress {
		$uuids = array_slice($this->uuidsOf(selection: $selection), 0, self::MAX_OBJECTS);
		$progress = new ReindexProgress(total: count($uuids));
		$onProgress($progress->toArray());

		foreach (array_chunk($uuids, self::PROGRESS_EVERY) as $batch) {
			$this->reindexBatch(batch: $batch, selection: $selection, progress: $progress);
			$onProgress($progress->toArray());
		}

		return $progress;

	}//end reindexAll()

	/**
	 * Reindex one batch, counting each object as done or failed with its reason.
	 *
	 * @param array<int, string> $batch     The uuids of the batch.
	 * @param ReindexSelection   $selection The selection, whose register and schema scope the read.
	 * @param ReindexProgress    $progress  The counts.
	 *
	 * @return void
	 */
	private function reindexBatch(array $batch, ReindexSelection $selection, ReindexProgress $progress): void {
		$objects = $this->resolver->hydrate(uuids: $batch, scope: $selection->bulkSelection());

		foreach ($batch as $uuid) {
			$object = ($objects[$uuid] ?? null);
			if ($object === null) {
				$progress->failedOn(id: $uuid, reason: 'The object was not found, or is no longer readable.');
				continue;
			}

			try {
				$this->reindexObject(object: $object);
				$progress->succeeded();
			} catch (Throwable $failure) {
				$progress->failedOn(id: $uuid, reason: substr($failure->getMessage(), 0, 300));
			}
		}

	}//end reindexBatch()

	/**
	 * Re-extract one object's text and the text of every file attached to it.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return void
	 *
	 * @throws Throwable Whatever an extraction threw.
	 */
	private function reindexObject(ObjectEntity $object): void {
		$uuid = (string)$object->getUuid();
		$this->extractor->extractObject(objectId: (int)$object->getId(), forceReExtract: true);

		$fileIds = ($this->files->getFileIdsForObjects(uuids: [$uuid])[$uuid] ?? []);
		foreach ($fileIds as $fileId) {
			$this->extractor->reextractFile(fileId: (int)$fileId);
		}

	}//end reindexObject()

	/**
	 * The uuids a selection resolves to now, one more than the ceiling at most.
	 *
	 * @param ReindexSelection $selection The selection.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function uuidsOf(ReindexSelection $selection): array {
		$bulk = $selection->bulkSelection();

		if ($selection->kind === ReindexSelection::KIND_IDS) {
			return $this->resolver->idsOf(criteria: $bulk->criteria);
		}

		return $this->resolver->queryUuids(selection: $bulk, ceiling: self::MAX_OBJECTS);

	}//end uuidsOf()
}//end class
