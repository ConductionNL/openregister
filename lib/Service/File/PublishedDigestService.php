<?php

/**
 * PublishedDigestService
 *
 * Records and seals the SHA-256 of a file at the moment it becomes public.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Closure;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Records a published file's digest and seals it into the audit chain.
 *
 * Publishing is one unit of work. The digest row, the file's metadata, the
 * `file.published` audit row and the public share are written in one database
 * transaction, with the share last. When any of them cannot be written, the
 * transaction is rolled back and the file stays private: no seal, no
 * publication. The digest itself is computed before the transaction opens,
 * by streaming the file, so a large file neither sits in memory nor holds a
 * transaction open while it is read.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */
class PublishedDigestService {

	/**
	 * The audit action of a publication.
	 *
	 * @var string
	 */
	public const ACTION = 'file.published';

	/**
	 * The files metadata key carrying the digest of the open publication.
	 *
	 * This is the file-metadata form of `published.sha256`. It is namespaced
	 * like the app's other files metadata key (`openregister-risk-level`)
	 * because the files metadata keys are shared by every app on the instance.
	 *
	 * @var string
	 */
	public const METADATA_KEY = 'openregister-published-sha256';

	/**
	 * How many bytes one read of the file hashes.
	 *
	 * @var int
	 */
	private const CHUNK_BYTES = 1048576;

	/**
	 * Constructor.
	 *
	 * @param FileDigestMapper          $digests         The digest records.
	 * @param AuditTrailMapperInterface $auditTrails     The sealing audit insert path.
	 * @param IFilesMetadataManager     $metadataManager The files metadata store.
	 * @param IDBConnection             $db              The connection the publication is one transaction on.
	 * @param LoggerInterface           $logger          The logger.
	 */
	public function __construct(
		private readonly FileDigestMapper $digests,
		private readonly AuditTrailMapperInterface $auditTrails,
		private readonly IFilesMetadataManager $metadataManager,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Make a file public with its digest recorded and sealed, or not at all.
	 *
	 * Order inside the transaction: the digest row (closing the record of an
	 * earlier publication), the file's metadata, the audit row, and only then
	 * `$makePublic`. A failure at any step rolls all of them back, so the
	 * share is never created without its seal, and a seal is never kept for a
	 * share that could not be created.
	 *
	 * @param ObjectEntity $object     The object the file belongs to.
	 * @param File         $file       The file being published.
	 * @param Closure      $makePublic Creates the public share; throws when it cannot.
	 *
	 * @return FileDigest The record of this publication.
	 *
	 * @throws Exception When the digest cannot be computed, recorded or sealed, or the share cannot be created.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
	 */
	public function publishSealed(ObjectEntity $object, File $file, Closure $makePublic): FileDigest {
		$record = $this->newRecord(object: $object, file: $file);
		$auditRow = $this->auditRow(object: $object, record: $record);
		$record->setAuditTrailUuid($auditRow->getUuid());

		$this->db->beginTransaction();
		try {
			$record = $this->recordDigest(record: $record);
			$this->sealDigest(auditRow: $auditRow);
			$makePublic();
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			$this->logger->error(
				message: '[PublishedDigestService] The file stays private: ' . $e->getMessage(),
				context: ['fileId' => $record->getFileId(), 'objectUuid' => $record->getObjectUuid()]
			);
			throw $e;
		}

		return $record;
	}//end publishSealed()

	/**
	 * Close the open record of a file that stopped being public.
	 *
	 * Called after the share is gone. A failure here is logged, not thrown:
	 * the file is already private, which is the safe state, and refusing the
	 * unpublication would only keep the caller from learning that.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
	 */
	public function closePublication(int $fileId): void {
		try {
			$this->digests->closeOpenForFile(fileId: $fileId, at: new DateTime());
			$this->removeMetadata(fileId: $fileId);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[PublishedDigestService] Could not close the digest record of an unpublished file: ' . $e->getMessage(),
				context: ['fileId' => $fileId]
			);
		}
	}//end closePublication()

	/**
	 * The SHA-256 and byte count of a file, read as a stream in fixed chunks.
	 *
	 * @param File $file The file.
	 *
	 * @return array{sha256: string, size: int} The lowercase hex digest and the bytes it covers.
	 *
	 * @throws Exception When the file cannot be read.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
	 */
	public function digestOf(File $file): array {
		try {
			$handle = $file->fopen('r');
		} catch (Throwable $e) {
			throw new Exception('The file was not published: its digest could not be computed: ' . $e->getMessage(), 0, $e);
		}

		if (is_resource($handle) === false) {
			throw new Exception('The file was not published: its digest could not be computed: the file cannot be opened for reading');
		}

		try {
			$context = hash_init('sha256');
			$size = 0;
			do {
				$read = hash_update_stream($context, $handle, self::CHUNK_BYTES);
				$size += $read;
			} while ($read > 0);

			return ['sha256' => hash_final($context), 'size' => $size];
		} finally {
			fclose($handle);
		}
	}//end digestOf()

	/**
	 * A new, unsaved record of a publication happening now.
	 *
	 * @param ObjectEntity $object The object the file belongs to.
	 * @param File         $file   The file.
	 *
	 * @return FileDigest The record.
	 *
	 * @throws Exception When the file cannot be read.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
	 */
	private function newRecord(ObjectEntity $object, File $file): FileDigest {
		$digest = $this->digestOf(file: $file);

		$record = new FileDigest();
		$record->setUuid(Uuid::v4()->toRfc4122());
		$record->setFileId((int)$file->getId());
		$record->setObjectUuid($object->getUuid());
		$record->setSha256($digest['sha256']);
		$record->setSize($digest['size']);
		$record->setEtag($file->getEtag());
		$record->setPublishedAt(new DateTime());

		return $record;
	}//end newRecord()

	/**
	 * The `file.published` audit row of a record: the file id, the object uuid, the digest and the size.
	 *
	 * Built through the mapper's own row builder, so it names the actor, the
	 * register, the schema and the retention of the object like any other row
	 * about that object.
	 *
	 * @param ObjectEntity $object The object the file belongs to.
	 * @param FileDigest   $record The record.
	 *
	 * @return AuditTrail The row, not yet inserted.
	 */
	private function auditRow(ObjectEntity $object, FileDigest $record): AuditTrail {
		$row = $this->auditTrails->buildEntry(change: new AuditedChange(old: $object, new: $object, action: self::ACTION));
		$row->setChanged(
			array_merge(
				($row->getChanged() ?? []),
				[
					'fileId' => $record->getFileId(),
					'objectUuid' => $record->getObjectUuid(),
					'sha256' => $record->getSha256(),
					'size' => $record->getSize(),
				]
			)
		);

		return $row;
	}//end auditRow()

	/**
	 * Insert the record, close the earlier open ones, and write the file's metadata.
	 *
	 * @param FileDigest $record The new record.
	 *
	 * @return FileDigest The inserted record.
	 *
	 * @throws Exception When any of it cannot be written.
	 */
	private function recordDigest(FileDigest $record): FileDigest {
		try {
			$record = $this->digests->insert(entity: $record);
			$this->digests->closeOpenForFile(fileId: (int)$record->getFileId(), at: $record->getPublishedAt(), keepId: $record->getId());

			$metadata = $this->metadataManager->getMetadata((int)$record->getFileId(), true);
			$metadata->setString(self::METADATA_KEY, (string)$record->getSha256());
			$this->metadataManager->saveMetadata($metadata);
		} catch (Throwable $e) {
			throw new Exception('The file was not published: its digest could not be recorded: ' . $e->getMessage(), 0, $e);
		}

		return $record;
	}//end recordDigest()

	/**
	 * Write the audit row through the sealing insert path.
	 *
	 * @param AuditTrail $auditRow The row.
	 *
	 * @return void
	 *
	 * @throws Exception When the row cannot be written.
	 */
	private function sealDigest(AuditTrail $auditRow): void {
		try {
			$this->auditTrails->insertAuditTrails(entries: [$auditRow]);
		} catch (Throwable $e) {
			throw new Exception('The file was not published: its digest could not be sealed in the audit trail: ' . $e->getMessage(), 0, $e);
		}
	}//end sealDigest()

	/**
	 * Remove the digest from the file's metadata.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return void
	 */
	private function removeMetadata(int $fileId): void {
		try {
			$metadata = $this->metadataManager->getMetadata($fileId);
		} catch (FilesMetadataNotFoundException $e) {
			return;
		}

		if ($metadata->hasKey(self::METADATA_KEY) === false) {
			return;
		}

		$metadata->unset(self::METADATA_KEY);
		$this->metadataManager->saveMetadata($metadata);
	}//end removeMetadata()
}//end class
