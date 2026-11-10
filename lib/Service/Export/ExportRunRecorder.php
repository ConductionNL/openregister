<?php

/**
 * ExportRunRecorder - records a produced export, expires its file, keeps its row
 *
 * 🔴 WHY THE CLOCK IS INJECTED AND THE EXPIRY IS A COLUMN. Every export ZIP in
 * this fleet was once born 22.5 million seconds expired, because one component
 * wrote a deterministic file timestamp and the purge decided expiry by reading
 * that timestamp. Both halves had unit tests. Both passed. The cleanup test
 * hand-set the timestamps it then asserted on, so it could never have seen the
 * defect.
 *
 * So `record()` writes `expiresAt` from the retention the run was produced
 * under, `sweep()` selects on that same column, and the suite registers through
 * the real writer and then asks the real sweep, moving only the clock. Nothing
 * here reads a file's mtime.
 *
 * TWO MORE RULES THE PROPOSAL ASKS FOR, AND WHY.
 *
 * The run carries the retention it was produced under, so editing a profile
 * later does not move the deadline of a file somebody already has.
 *
 * The sweep deletes the FILE and keeps the ROW. The fact that an export
 * happened outlives the copy it made, which is exactly the question an
 * administrator is asked about a data subject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use DateTime;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Writes, lists and expires the record of a produced export.
 */
class ExportRunRecorder {

	/**
	 * How long a produced file stays when nobody says otherwise.
	 *
	 * Seven days. Long enough that a report written on Friday is still there
	 * after the weekend, short enough that a copy of a register does not sit
	 * in a Files folder for a quarter.
	 *
	 * @var int
	 */
	public const DEFAULT_RETENTION_SECONDS = 604800;

	/**
	 * The longest retention this recorder will write.
	 *
	 * Ninety days. An export is a copy of data that already has a retention
	 * rule of its own upstream, and this record is not the place to extend it.
	 *
	 * @var int
	 */
	public const MAX_RETENTION_SECONDS = 7776000;

	/**
	 * How many runs one sweep takes.
	 *
	 * @var int
	 */
	public const SWEEP_BATCH = 100;

	/**
	 * Constructor.
	 *
	 * @param ExportRunMapper $mapper     The runs.
	 * @param IRootFolder     $rootFolder Resolves a produced file so the sweep can delete it.
	 * @param ITimeFactory    $time       The clock, injected so expiry is tested by moving it.
	 * @param LoggerInterface $logger     Logger.
	 */
	public function __construct(
		private readonly ExportRunMapper $mapper,
		private readonly IRootFolder $rootFolder,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one produced export.
	 *
	 * @param ExportRunOrigin  $origin           Who and what produced it, and the status to write.
	 * @param ExportRunContent $content          What it read and wrote out.
	 * @param ExportRunFile    $file             The file it produced or served; none by default.
	 * @param int|null         $retentionSeconds How long the file is kept, or null to keep it.
	 *
	 * @return ExportRun The recorded run.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function record(
		ExportRunOrigin $origin,
		ExportRunContent $content,
		ExportRunFile $file=new ExportRunFile(),
		?int $retentionSeconds=self::DEFAULT_RETENTION_SECONDS,
	): ExportRun {
		$now = $this->now();

		$retention = null;
		$expiresAt = null;
		if ($retentionSeconds !== null) {
			$retention = max(60, min($retentionSeconds, self::MAX_RETENTION_SECONDS));
			$expiresAt = (clone $now)->modify('+' . $retention . ' seconds');
		}

		$run = new ExportRun();
		$run->setUuid(Uuid::v4()->toRfc4122());
		$run->setSource($origin->source);
		$run->setProfile($origin->profile);
		$run->setActor($origin->actor);
		$run->setRegisterName($content->registerName);
		$run->setSchemaName($content->schemaName);
		$run->setFormat($content->format);
		$run->setFilename($file->filename);
		$run->setRowCount($content->rowCount);
		$run->setFileId($file->fileId);
		$run->setFilePath($file->filePath);
		$run->setDownloadCount($file->downloadCount);
		$run->setRetentionSeconds($retention);
		$run->setStatus($origin->status ?? ExportRun::STATUS_AVAILABLE);
		$run->setProducedAt($now);
		$run->setExpiresAt($expiresAt);
		$run->setCreated($now);
		$run->setUpdated($now);

		return $this->mapper->insert($run);
	}//end record()

	/**
	 * The runs one caller sees in the area.
	 *
	 * Kept for its caller in ExportRunsController; builds the viewer and the
	 * page and delegates to listVisible().
	 *
	 * @param string|null $actor   The caller.
	 * @param bool        $isAdmin Whether the caller is an instance administrator.
	 * @param array       $filters Equality filters on register, schema, profile, source or status.
	 * @param int         $limit   Page size.
	 * @param int         $offset  Page offset.
	 *
	 * @return array<int, array<string, mixed>> The runs, each saying whether it has expired.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `isAdmin` is the caller's role, not a mode
	 *     switch: it decides whose runs are in scope, which is the one question this method
	 *     answers. Two methods would give the scope rule two places to be wrong in.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function listFor(?string $actor, bool $isAdmin=false, array $filters=[], int $limit=50, int $offset=0): array {
		$viewer = new ExportRunViewer(actor: $actor, seesEveryRun: $isAdmin);
		return $this->listVisible(viewer: $viewer, query: new ListQuery(limit: $limit, offset: $offset, filters: $filters));
	}//end listFor()

	/**
	 * The runs one viewer sees in the area.
	 *
	 * @param ExportRunViewer $viewer Who is looking, and whether they see every run.
	 * @param ListQuery       $query  Page and equality filters on register, schema, profile, source or status.
	 *
	 * @return array<int, array<string, mixed>> The runs, each saying whether it has expired.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function listVisible(ExportRunViewer $viewer, ListQuery $query): array {
		$actor = $viewer->actor;
		if ($actor === null || $actor === '') {
			return [];
		}

		$scopedTo = $actor;
		if ($viewer->seesEveryRun === true) {
			$scopedTo = null;
		}

		$now = $this->now();

		$rows = [];
		foreach ($this->mapper->findForActor(actor: $scopedTo, filters: $query->filters, limit: $query->limit, offset: $query->offset ?? 0) as $run) {
			$row = $run->jsonSerialize();
			// An expired run is NAMED as expired rather than offered as a link
			// to nothing. A missing file and a file nobody produced look the
			// same on a list, and only one of them means the retention worked.
			$row['expired'] = ($run->isExpiredAt($now) === true || $run->getStatus() === ExportRun::STATUS_EXPIRED);
			$row['downloadable'] = ($row['expired'] === false && $run->getFileId() !== null);
			$rows[] = $row;
		}

		return $rows;
	}//end listVisible()

	/**
	 * Count one hand-over of a run's file by the register.
	 *
	 * The count belongs to the run rather than to the file, because the same
	 * file copied or moved inside Files is no longer the thing the register
	 * handed out, and a count that follows the file answers a different
	 * question.
	 *
	 * @param string $uuid The run's uuid.
	 *
	 * @return ExportRun|null The run, or null when there is no such run.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function countDownload(string $uuid): ?ExportRun {
		try {
			$run = $this->mapper->findByUuid(uuid: $uuid);
		} catch (Throwable $e) {
			return null;
		}

		$run->setDownloadCount(($run->getDownloadCount() ?? 0) + 1);
		$run->setUpdated($this->now());

		return $this->mapper->update($run);
	}//end countDownload()

	/**
	 * Delete the files of the runs whose stored expiry has passed.
	 *
	 * The row is kept and marked expired. A run whose file somebody already
	 * deleted is not a failure: the sweep still marks it, or the row would sit
	 * past its own expiry for ever.
	 *
	 * @param int|null $limit How many runs to take in this sweep.
	 *
	 * @return int How many runs were swept.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function sweep(?int $limit = null): int {
		$batch = ($limit ?? self::SWEEP_BATCH);
		$now = $this->now();

		$swept = 0;
		foreach ($this->mapper->findDueForSweep(now: $now, limit: $batch) as $run) {
			$this->deleteFileOf(run: $run);

			$run->setStatus(ExportRun::STATUS_EXPIRED);
			$run->setFileId(null);
			$run->setFilePath(null);
			$run->setUpdated($now);

			try {
				$this->mapper->update($run);
				$swept++;
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[ExportRunRecorder] Could not mark an expired export run',
					context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $run->getUuid(), 'error' => $e->getMessage()]
				);
			}
		}

		return $swept;
	}//end sweep()

	/**
	 * Delete the file a run produced, if it is still there.
	 *
	 * @param ExportRun $run The run.
	 *
	 * @return void
	 */
	private function deleteFileOf(ExportRun $run): void {
		$fileId = $run->getFileId();
		$actor = $run->getActor();
		if ($fileId === null || $actor === null || $actor === '') {
			return;
		}

		try {
			$folder = $this->rootFolder->getUserFolder($actor);
			$nodes = $folder->getById($fileId);

			foreach ($nodes as $node) {
				if (($node instanceof Node) === true) {
					$node->delete();
				}
			}
		} catch (Throwable $e) {
			// A file the owner already deleted is not an error. Saying so here
			// is what lets the sweep still mark the row, rather than leaving it
			// available for ever because its file went first.
			$this->logger->debug(
				message: '[ExportRunRecorder] Nothing to delete for an expired export run',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $run->getUuid()]
			);
		}
	}//end deleteFileOf()

	/**
	 * The current moment, from the injected clock.
	 *
	 * @return DateTime The moment.
	 */
	private function now(): DateTime {
		return new DateTime('@' . $this->time->getDateTime()->getTimestamp());
	}//end now()
}//end class
