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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
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
	 * @param string      $source           What produced it, for example `scheduled-report`.
	 * @param string      $actor            Who asked for it.
	 * @param string      $format           csv, json and so on.
	 * @param int         $rowCount         How many rows went out.
	 * @param string|null $profile          The profile or report it came from.
	 * @param string|null $filename         The name it was written or served under.
	 * @param string|null $registerName     The register it read.
	 * @param string|null $schemaName       The schema it read.
	 * @param int|null    $fileId           The Nextcloud file it produced, when it produced one.
	 * @param string|null $filePath         Where that file was written.
	 * @param int|null    $retentionSeconds How long the file is kept, or null to keep it.
	 * @param int         $downloadCount    How often the register has already served it.
	 * @param string|null $status           The status to write, defaulting to available.
	 *
	 * @return ExportRun The recorded run.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A run names what it was, who made it,
	 *     what it read and how long it lives; every one of those is on the record the
	 *     proposal asks for, and grouping them into an array would only hide them.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function record(
		string $source,
		string $actor,
		string $format,
		int $rowCount,
		?string $profile = null,
		?string $filename = null,
		?string $registerName = null,
		?string $schemaName = null,
		?int $fileId = null,
		?string $filePath = null,
		?int $retentionSeconds = self::DEFAULT_RETENTION_SECONDS,
		int $downloadCount = 0,
		?string $status = null,
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
		$run->setSource($source);
		$run->setProfile($profile);
		$run->setActor($actor);
		$run->setRegisterName($registerName);
		$run->setSchemaName($schemaName);
		$run->setFormat($format);
		$run->setFilename($filename);
		$run->setRowCount($rowCount);
		$run->setFileId($fileId);
		$run->setFilePath($filePath);
		$run->setDownloadCount($downloadCount);
		$run->setRetentionSeconds($retention);
		$run->setStatus($status ?? ExportRun::STATUS_AVAILABLE);
		$run->setProducedAt($now);
		$run->setExpiresAt($expiresAt);
		$run->setCreated($now);
		$run->setUpdated($now);

		return $this->mapper->insert($run);
	}//end record()

	/**
	 * Record one row appended to a file that grows, as ONE run per file.
	 *
	 * The whole-set extract writes one row per object into one file per
	 * schema. Recording a run per row would list a single file thousands of
	 * times; recording none would leave the biggest export of all
	 * unaccounted for. So the run is keyed on the file id: the first row opens
	 * it, every later row grows its count. A run whose file was swept no
	 * longer carries the id, so a file written again opens a new run.
	 *
	 * @param string      $source           What produced it, for example `whole-set`.
	 * @param string      $actor            Who the extract runs as.
	 * @param string      $format           csv, json and so on.
	 * @param string|null $profile          The profile it came from.
	 * @param string      $filename         The file's name.
	 * @param int         $fileId           The Nextcloud file it appends to.
	 * @param string      $filePath         Where that file lives.
	 * @param int|null    $retentionSeconds How long the file is kept, or null to keep it.
	 *
	 * @return ExportRun The run, opened or grown.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The same record fields as record(),
	 *     minus the ones an append decides itself.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-a-produced-export-is-recorded-as-a-run
	 */
	public function recordAppended(
		string $source,
		string $actor,
		string $format,
		?string $profile,
		string $filename,
		int $fileId,
		string $filePath,
		?int $retentionSeconds,
	): ExportRun {
		$run = $this->mapper->findOpenForFile(fileId: $fileId);
		if ($run === null) {
			return $this->record(
				source: $source,
				actor: $actor,
				format: $format,
				rowCount: 1,
				profile: $profile,
				filename: $filename,
				fileId: $fileId,
				filePath: $filePath,
				retentionSeconds: $retentionSeconds
			);
		}

		$run->setRowCount(($run->getRowCount() ?? 0) + 1);
		$run->setUpdated($this->now());

		return $this->mapper->update($run);
	}//end recordAppended()

	/**
	 * Resolve a run to the file the register may hand back.
	 *
	 * The maker of a run gets its file, and so does a caller who sees every
	 * run. Anybody else gets the same answer as for a uuid nobody has, so a
	 * refusal never tells a stranger that the run exists. An expired run, or
	 * one whose file was deleted in Files, answers gone: the row outlives the
	 * file, and the list names it expired rather than offering a dead link.
	 *
	 * @param string $uuid         The run.
	 * @param string $callerUid    Who asks.
	 * @param bool   $seesEveryRun Whether the caller sees every run (from ExportRightService).
	 *
	 * @return array{status: int, error?: string, run?: ExportRun, file?: File} 200 with the run
	 *     and its file, or 404/410 with an error.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The caller's scope, answered by
	 *     ExportRightService, as in listFor().
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-downloads-are-counted-on-the-run
	 */
	public function openForDownload(string $uuid, string $callerUid, bool $seesEveryRun): array {
		$missing = ['status' => 404, 'error' => 'No export with this id.'];
		try {
			$run = $this->mapper->findByUuid(uuid: $uuid);
		} catch (Throwable $e) {
			return $missing;
		}

		if ($seesEveryRun === false && $run->getActor() !== $callerUid) {
			return $missing;
		}

		$gone = ['status' => 410, 'error' => 'This export has expired. Its file was removed; the record stays.'];
		$fileId = $run->getFileId();
		$actor = (string)$run->getActor();
		if ($run->getStatus() === ExportRun::STATUS_EXPIRED || $fileId === null || $actor === '') {
			return $gone;
		}

		$file = $this->fileOf(actor: $actor, fileId: $fileId);
		if ($file === null) {
			return ['status' => 410, 'error' => 'The file of this export is no longer in its owner\'s files.'];
		}

		return ['status' => 200, 'run' => $run, 'file' => $file];
	}//end openForDownload()

	/**
	 * The file a run produced, in its maker's files, if it is still there.
	 *
	 * @param string $actor  The run's maker.
	 * @param int    $fileId The file id.
	 *
	 * @return File|null The file, or null when it is gone.
	 */
	private function fileOf(string $actor, int $fileId): ?File {
		try {
			$nodes = $this->rootFolder->getUserFolder($actor)->getById($fileId);
		} catch (Throwable $e) {
			return null;
		}

		foreach ($nodes as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}//end fileOf()

	/**
	 * The runs one caller sees in the area.
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
	public function listFor(
		?string $actor,
		bool $isAdmin = false,
		array $filters = [],
		int $limit = 50,
		int $offset = 0,
	): array {
		if ($actor === null || $actor === '') {
			return [];
		}

		$scopedTo = $actor;
		if ($isAdmin === true) {
			$scopedTo = null;
		}

		$now = $this->now();

		$rows = [];
		foreach ($this->mapper->findForActor(actor: $scopedTo, filters: $filters, limit: $limit, offset: $offset) as $run) {
			$row = $run->jsonSerialize();
			// An expired run is NAMED as expired rather than offered as a link
			// to nothing. A missing file and a file nobody produced look the
			// same on a list, and only one of them means the retention worked.
			$row['expired'] = ($run->isExpiredAt($now) === true || $run->getStatus() === ExportRun::STATUS_EXPIRED);
			$row['downloadable'] = ($row['expired'] === false && $run->getFileId() !== null);
			$rows[] = $row;
		}

		return $rows;
	}//end listFor()

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
