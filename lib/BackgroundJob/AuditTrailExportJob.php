<?php

/**
 * An audit trail export too large to answer in the request.
 *
 * Past {@see AuditTrailExportJob::INLINE_LIMIT} rows the audit page's export
 * is queued as this job. It selects the rows through the same keyset query
 * and the same filters as the list, renders them with the chain fields
 * (`hash`, `previousHash`) on every row, saves the file in the requester's
 * Files under "Audit exports" and notifies them where it is.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailPageQuery;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Notification\IManager as INotificationManager;
use RuntimeException;

/**
 * Renders a large audit export into the requester's Files.
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
 */
class AuditTrailExportJob extends RecordedQueuedJob {

	/**
	 * The most rows an export answers inside the request.
	 *
	 * @var int
	 */
	public const INLINE_LIMIT = 10000;

	/**
	 * The most rows one queued export writes. Past it the file says it was
	 * cut, in its name and in the notification, rather than ending quietly.
	 *
	 * @var int
	 */
	public const JOB_LIMIT = 500000;

	/**
	 * The folder in the requester's Files.
	 *
	 * @var string
	 */
	public const FOLDER = 'Audit exports';

	/**
	 * The notification subject.
	 *
	 * @var string
	 */
	public const SUBJECT = 'audit_export_ready';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time          The clock.
	 * @param JobRunRecorder       $recorder      Records the run.
	 * @param AuditTrailPageQuery  $pageQuery     Selects the rows.
	 * @param LogService           $logService    Renders them.
	 * @param IRootFolder          $rootFolder    The requester's Files.
	 * @param INotificationManager $notifications Tells the requester.
	 */
	public function __construct(
		ITimeFactory $time,
		JobRunRecorder $recorder,
		private readonly AuditTrailPageQuery $pageQuery,
		private readonly LogService $logService,
		private readonly IRootFolder $rootFolder,
		private readonly INotificationManager $notifications,
	) {
		parent::__construct(time: $time, recorder: $recorder);
	}//end __construct()

	/**
	 * Run the export.
	 *
	 * @param mixed $argument `actor`, `filters`, `search`, `format`, `includeChanges`.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the requester or the format is missing.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	protected function runRecorded(mixed $argument): void {
		$this->deliver(argument: (array)$argument);
	}//end runRecorded()

	/**
	 * Select, render, save and notify.
	 *
	 * @param array<string, mixed> $argument The job argument.
	 *
	 * @return string The path of the saved file in the requester's Files.
	 *
	 * @throws RuntimeException When the requester or the format is missing.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	public function deliver(array $argument): string {
		$actor = (string)($argument['actor'] ?? '');
		$format = strtolower((string)($argument['format'] ?? 'csv'));
		if ($actor === '') {
			throw new RuntimeException('An audit export job carries no requester.');
		}

		$collected = $this->pageQuery->collect(
			filters: (array)($argument['filters'] ?? []),
			search: ($argument['search'] ?? null),
			ceiling: self::JOB_LIMIT
		);

		$file = $this->logService->exportRows(
			format: $format,
			logs: $collected['results'],
			config: ['includeChanges' => (bool)($argument['includeChanges'] ?? true)]
		);

		$filename = $file['filename'];
		if ($collected['truncated'] === true) {
			$filename = preg_replace('/(\.[a-z]+)$/', '_first-' . self::JOB_LIMIT . '$1', $filename);
		}

		$userFolder = $this->rootFolder->getUserFolder($actor);
		if ($userFolder->nodeExists(self::FOLDER) === false) {
			$userFolder->newFolder(self::FOLDER);
		}

		$folder = $userFolder->get(self::FOLDER);
		if ($folder instanceof Folder === false) {
			throw new RuntimeException('"' . self::FOLDER . '" in the requester\'s Files is not a folder.');
		}

		$folder->newFile($filename, $file['content']);

		$notification = $this->notifications->createNotification();
		$notification->setApp('openregister')
			->setUser($actor)
			->setDateTime(new DateTime())
			->setObject('audit_export', $filename)
			->setSubject(
				self::SUBJECT,
				[
					'folder' => self::FOLDER . '/',
					'filename' => $filename,
					'rows' => count($collected['results']),
					'truncated' => $collected['truncated'],
				]
			);
		$this->notifications->notify($notification);

		return self::FOLDER . '/' . $filename;
	}//end deliver()
}//end class
