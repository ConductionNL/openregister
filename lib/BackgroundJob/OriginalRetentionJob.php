<?php

/**
 * OpenRegister OriginalRetentionJob
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OriginalCustody;
use OCA\OpenRegister\Db\OriginalCustodyMapper;
use OCA\OpenRegister\Files\Service\File\FileAuditHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\Files\RedactionSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\File\DeleteFileHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes, once a day, the unredacted originals whose deletion date has
 * passed, through the file delete path and with an audit row naming the
 * original, the redacted copy and the rule.
 *
 * An original whose object is under an active legal hold, or nominated to be
 * kept (`bewaren`), stays, is reported as held and is asked again on the
 * next run. An object that cannot be read is held too: a hold that cannot
 * be checked is not assumed absent.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */
class OriginalRetentionJob extends TimedJob {

	/**
	 * The audit action of a deletion by this job.
	 */
	public const AUDIT_ACTION = 'file.original_retention_deleted';

	/**
	 * The hold reasons.
	 */
	public const HELD_LEGAL_HOLD = 'legal-hold';

	public const HELD_NOMINATION_KEEP = 'nomination-keep';

	public const HELD_OBJECT_UNREADABLE = 'object-unreadable';

	/**
	 * The nominations that keep an object permanently; the same values as
	 * Service\Archival\self::RETAIN_PERMANENTLY_ALIASES, restated
	 * so this Files job does not reach into the Compliance area.
	 */
	private const RETAIN_PERMANENTLY_ALIASES = ['retain_permanently', 'bewaren', 'blijvend_bewaren'];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory               $time       The clock.
	 * @param OriginalCustodyMapper      $custody    The custody rows.
	 * @param ObjectOperationsInterface  $objects    Reads the object of each original, without audit.
	 * @param IRootFolder                $rootFolder Resolves the original.
	 * @param DeleteFileHandler          $deleter    The file delete path.
	 * @param FileAuditHandlerInterface  $audit      Writes the audit row.
	 * @param LoggerInterface            $logger     Where the run's report goes.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly OriginalCustodyMapper $custody,
		private readonly ObjectOperationsInterface $objects,
		private readonly IRootFolder $rootFolder,
		private readonly DeleteFileHandler $deleter,
		private readonly FileAuditHandlerInterface $audit,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Delete the originals that are due, and log what was deleted and held.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob's signature.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	protected function run(mixed $argument): void {
		$now    = DateTime::createFromInterface($this->time->getDateTime());
		$report = ['deleted' => [], 'held' => [], 'gone' => [], 'failed' => []];
		foreach ($this->custody->findDue(now: $now) as $row) {
			$outcome = $this->handle(row: $row, now: $now);
			$report[$outcome['outcome']][] = $outcome['entry'];
		}

		$this->logger->info(
			message: '[OriginalRetentionJob] Originals past their retention handled',
			context: [
				'deleted' => count($report['deleted']),
				'held' => $report['held'],
				'gone' => count($report['gone']),
				'failed' => $report['failed'],
			]
		);
	}//end run()

	/**
	 * Delete, hold or skip one original.
	 *
	 * @param OriginalCustody $row The custody row.
	 * @param DateTime        $now The run's moment.
	 *
	 * @return array{outcome: string, entry: array<string, int|string>}
	 */
	private function handle(OriginalCustody $row, DateTime $now): array {
		$entry  = ['originalFileId' => $row->getOriginalFileId()];
		$object = $this->objectOf(row: $row);
		$held   = $this->holdReason(object: $object);
		if ($held !== null) {
			// Still scheduled: once the hold is released or the nomination
			// changes, a later run deletes it.
			$this->close(row: $row, status: OriginalCustody::STATUS_SCHEDULED, now: $now, reason: $held);
			return ['outcome' => 'held', 'entry' => $entry + ['reason' => $held]];
		}

		$file = $this->originalFile(fileId: $row->getOriginalFileId());
		if ($file === null) {
			$this->close(row: $row, status: OriginalCustody::STATUS_GONE, now: $now, reason: null);
			return ['outcome' => 'gone', 'entry' => $entry];
		}

		try {
			$deleted = $this->deleter->deleteFile(file: $file, object: $object);
		} catch (Throwable $error) {
			$deleted = false;
			$entry['error'] = $error->getMessage();
		}

		if ($deleted === false) {
			// Left scheduled: the next run tries again.
			return ['outcome' => 'failed', 'entry' => $entry];
		}

		$this->writeAudit(row: $row, object: $object);
		$this->close(row: $row, status: OriginalCustody::STATUS_DELETED, now: $now, reason: null);

		return ['outcome' => 'deleted', 'entry' => $entry];
	}//end handle()

	/**
	 * The object the original belongs to, or null when it cannot be read.
	 *
	 * @param OriginalCustody $row The custody row.
	 *
	 * @return ObjectEntity|null
	 */
	private function objectOf(OriginalCustody $row): ?ObjectEntity {
		if ($row->getObjectUuid() === '') {
			return null;
		}

		try {
			return $this->objects->findSilent(id: $row->getObjectUuid(), _rbac: false, _multitenancy: false);
		} catch (Throwable $unreadable) {
			return null;
		}
	}//end objectOf()

	/**
	 * Why the original must stay, or null when it may go.
	 *
	 * @param ObjectEntity|null $object The object, or null when it could not be read.
	 *
	 * @return string|null One of the HELD_ constants, or null.
	 */
	private function holdReason(?ObjectEntity $object): ?string {
		if ($object === null) {
			return self::HELD_OBJECT_UNREADABLE;
		}

		if ($object->hasActiveLegalHold() === true) {
			return self::HELD_LEGAL_HOLD;
		}

		$nominations = [
			(($object->getRetention() ?? [])['archiefnominatie'] ?? null),
			(($object->getTmlo() ?? [])['archiefnominatie'] ?? null),
		];
		foreach ($nominations as $nomination) {
			if (is_string($nomination) === true
				&& in_array(strtolower($nomination), self::RETAIN_PERMANENTLY_ALIASES, true) === true
			) {
				return self::HELD_NOMINATION_KEEP;
			}
		}

		return null;
	}//end holdReason()

	/**
	 * The original file, or null when it is gone.
	 *
	 * @param int $fileId The original's id.
	 *
	 * @return File|null
	 */
	private function originalFile(int $fileId): ?File {
		try {
			$node = ($this->rootFolder->getById($fileId)[0] ?? null);
		} catch (Throwable $unreachable) {
			return null;
		}

		if (($node instanceof File) === false) {
			return null;
		}

		return $node;
	}//end originalFile()

	/**
	 * The audit row of a deletion: the original, the redacted copy and the rule.
	 *
	 * @param OriginalCustody $row    The custody row.
	 * @param ObjectEntity    $object The object.
	 *
	 * @return void
	 */
	private function writeAudit(OriginalCustody $row, ObjectEntity $object): void {
		try {
			$this->audit->logFileAction(
				object: $object,
				fileId: $row->getOriginalFileId(),
				action: self::AUDIT_ACTION,
				data: [
					'originalFileId' => $row->getOriginalFileId(),
					'redactedCopyFileId' => $row->getCopyFileId(),
					'rule' => 'anonymisation.' . RedactionSettingsInterface::ORIGINAL_RETENTION_KEY,
					'retention' => $row->getRetention(),
					'deleteAfter' => $row->getDeleteAfter()?->format(DATE_ATOM),
				]
			);
		} catch (Throwable $error) {
			$this->logger->error(
				message: '[OriginalRetentionJob] The original was deleted but its audit row could not be written',
				context: ['originalFileId' => $row->getOriginalFileId(), 'error' => $error->getMessage()]
			);
		}
	}//end writeAudit()

	/**
	 * Record what the job did with a row.
	 *
	 * @param OriginalCustody $row    The custody row.
	 * @param string          $status The new status.
	 * @param DateTime        $now    The run's moment.
	 * @param string|null     $reason Why it was held.
	 *
	 * @return void
	 */
	private function close(OriginalCustody $row, string $status, DateTime $now, ?string $reason): void {
		$row->setStatus($status);
		$row->setOutcomeAt($now);
		$row->setOutcomeReason($reason);
		$this->custody->update(entity: $row);
	}//end close()
}//end class
