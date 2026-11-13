<?php

/**
 * Daily check of every published file against the digest recorded when it was published
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes the digest of every published file whose etag or size moved.
 *
 * A file whose bytes no longer match, or that can no longer be read, gets a
 * `file.digest-mismatch` audit row and an error in the log naming the file.
 * One file that cannot be checked never stops the others.
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */
class PublishedDigestVerifyJob extends TimedJob {

	/**
	 * Once a day.
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 86400;

	/**
	 * Records read per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory               $time    The clock.
	 * @param FileDigestMapper           $digests The digest records.
	 * @param PublishedDigestService     $service Verifies a file and records a mismatch.
	 * @param IRootFolder                $root    Finds a file by id.
	 * @param MagicObjectFinderInterface $objects Finds the object a file belongs to, as the system.
	 * @param LoggerInterface            $logger  Raises the mismatch for operations.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FileDigestMapper $digests,
		private readonly PublishedDigestService $service,
		private readonly IRootFolder $root,
		private readonly MagicObjectFinderInterface $objects,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Check every open record.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
	 */
	protected function run($argument): void {
		$counts = ['checked' => 0, 'changed' => 0];
		$afterId = 0;
		do {
			$page = $this->digests->findOpenAfter(afterId: $afterId, limit: self::PAGE_SIZE);
			foreach ($page as $record) {
				$afterId = (int)$record->getId();
				$counts['checked']++;
				if ($this->check(record: $record) === false) {
					$counts['changed']++;
				}
			}
		} while (count($page) === self::PAGE_SIZE);

		$this->logger->info(
			message: '[PublishedDigestVerifyJob] Checked the published files against their digests',
			context: $counts
		);
	}//end run()

	/**
	 * Check one record; report it when the file changed or cannot be read.
	 *
	 * @param FileDigest $record The open record.
	 *
	 * @return bool Whether the file is identical.
	 */
	private function check(FileDigest $record): bool {
		try {
			$file = $this->root->getFirstNodeById((int)$record->getFileId());
			if ($file instanceof File === false) {
				$this->report(record: $record, current: '', reason: 'the file is gone');
				return false;
			}

			$result = $this->service->verify(record: $record, file: $file);
		} catch (Throwable $e) {
			$this->report(record: $record, current: '', reason: 'the file cannot be read: ' . $e->getMessage());
			return false;
		}

		if ($result['verdict'] === PublishedDigestService::IDENTICAL) {
			return true;
		}

		$this->report(record: $record, current: $result['current'], reason: 'the bytes changed since publication');
		return false;
	}//end check()

	/**
	 * Log the mismatch for operations and write its audit row.
	 *
	 * @param FileDigest $record  The open record.
	 * @param string     $current The digest the file has now, '' when it has none.
	 * @param string     $reason  Why it does not match.
	 *
	 * @return void
	 */
	private function report(FileDigest $record, string $current, string $reason): void {
		$this->logger->error(
			message: '[PublishedDigestVerifyJob] A published file no longer matches its digest: ' . $reason,
			context: ['fileId' => $record->getFileId(), 'objectUuid' => $record->getObjectUuid(), 'recorded' => $record->getSha256(), 'current' => $current]
		);

		try {
			$object = $this->objects->findAcrossAllSources(
				identifier: (string)$record->getObjectUuid(),
				includeDeleted: true,
				_rbac: false,
				_multitenancy: false
			)['object'];
			if ($object instanceof ObjectEntity === true) {
				$this->service->recordMismatch(object: $object, record: $record, current: $current);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[PublishedDigestVerifyJob] The mismatch could not be written to the audit trail: ' . $e->getMessage(),
				context: ['fileId' => $record->getFileId()]
			);
		}
	}//end report()
}//end class
