<?php

/**
 * FileAuditHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
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

namespace OCA\OpenRegister\Files\Service\File;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handles file download audit logging.
 *
 * Creates audit trail entries for all file downloads (authenticated and anonymous),
 * tracks download counts, and logs bulk downloads.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileAuditHandler {
	/**
	 * Constructor for FileAuditHandler.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper for persisting entries.
	 * @param IUserSession $userSession User session for current user context.
	 * @param IRequest $request Request object for IP and user-agent.
	 * @param LoggerInterface $logger Logger for logging operations.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly IUserSession $userSession,
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log a bulk download event (ZIP archive) as a SINGLE audit-trail row.
	 *
	 * One audit row per ZIP — not one per included file — to match the
	 * file-actions spec requirement that bulk download is recorded as a
	 * single auditable event. The fileIds + fileNames array is captured
	 * in the `changed` payload so consumers can reconstruct the contents
	 * of the archive.
	 *
	 * @param ObjectEntity        $object  The parent object whose files were zipped.
	 * @param BulkDownloadArchive $archive The file ids, file names, ZIP name and byte total of the archive.
	 *
	 * @return AuditTrail|null The persisted audit row, or null on failure.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-audit-trail-immutable/tasks.md#task-4
	 */
	public function logBulkDownload(ObjectEntity $object, BulkDownloadArchive $archive): ?AuditTrail {
		$fileIds = $archive->fileIds;
		$fileNames = $archive->fileNames;
		$zipName = $archive->zipName;
		$totalBytes = $archive->totalBytes;

		try {
			$auditTrail = new AuditTrail();
			$auditTrail->setUuid((string)Uuid::v4());
			$auditTrail->setObject($object->getId());
			$auditTrail->setObjectUuid($object->getUuid());
			$auditTrail->setRegister($object->getRegister());
			$auditTrail->setSchema($object->getSchema());
			$auditTrail->setAction('file.bulk_downloaded');
			$auditTrail->setChanged(
				[
					'fileIds' => array_values($fileIds),
					'fileNames' => array_values($fileNames),
					'fileCount' => count($fileIds),
					'zipName' => $zipName,
					'totalBytes' => $totalBytes,
				]
			);

			$user = $this->userSession->getUser();
			if ($user !== null) {
				$auditTrail->setUser($user->getUID());
				$auditTrail->setUserName($user->getDisplayName());
			}

			if ($user === null) {
				// Anonymous bulk downloads still get attributed by IP + UA so the
				// download can be traced back even without a logged-in user.
				$remote = $this->request->getRemoteAddress();
				$auditTrail->setUser('Anonymous');
				$auditTrail->setUserName('Anonymous (' . $remote . ')');
			}

			$auditTrail->setCreated(new DateTime());
			// Expiry follows the record's retention, not a flat 30 days (or#4101).
			$this->auditTrailMapper->applyRetentionExpiry(auditTrail: $auditTrail, objectEntity: $object);
			$auditTrail->setSize(14);

			return $this->auditTrailMapper->insert($auditTrail);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[FileAuditHandler] Failed to log bulk download: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}//end try
	}//end logBulkDownload()

	/**
	 * Log a file-action audit trail entry tied to the parent object.
	 *
	 * Creates an `AuditTrail` row whose `action` field is the namespaced
	 * file event (e.g. `file.renamed`, `file.locked`, `file.version_restored`)
	 * and whose `changed` payload carries action-specific metadata.
	 *
	 * The audit row is keyed off the parent ObjectEntity (object/objectUuid/
	 * register/schema columns) so file events surface in the same audit
	 * timeline as object updates -- this matches how the existing
	 * `createAuditTrail` flow stamps rows.
	 *
	 * Failures are swallowed and logged: audit logging must never break
	 * the underlying file operation.
	 *
	 * @param ObjectEntity $object The parent object the file belongs to.
	 * @param int $fileId The file ID the action targeted.
	 * @param string $action The namespaced action (e.g. 'file.renamed').
	 * @param array $data Action-specific metadata (newName, targetUuid, etc.).
	 *
	 * @return AuditTrail|null The persisted audit row, or null on failure.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-audit-trail-immutable/tasks.md#task-4
	 */
	public function logFileAction(
		ObjectEntity $object,
		int $fileId,
		string $action,
		array $data = [],
	): ?AuditTrail {
		try {
			$auditTrail = new AuditTrail();
			$auditTrail->setUuid((string)Uuid::v4());
			$auditTrail->setObject($object->getId());
			$auditTrail->setObjectUuid($object->getUuid());
			$auditTrail->setRegister($object->getRegister());
			$auditTrail->setSchema($object->getSchema());
			$auditTrail->setAction($action);
			$auditTrail->setChanged(
				[
					'fileId' => $fileId,
					'data' => $data,
				]
			);

			// User context.
			$user = $this->userSession->getUser();
			if ($user !== null) {
				$auditTrail->setUser($user->getUID());
				$auditTrail->setUserName($user->getDisplayName());
			}

			if ($user === null) {
				$auditTrail->setUser('System');
				$auditTrail->setUserName('System');
			}

			$auditTrail->setCreated(new DateTime());
			// Expiry follows the record's retention, not a flat 30 days (or#4101).
			$this->auditTrailMapper->applyRetentionExpiry(auditTrail: $auditTrail, objectEntity: $object);
			// Minimum default size from AuditTrailMapper::createAuditTrail.
			$auditTrail->setSize(14);

			return $this->auditTrailMapper->insert($auditTrail);
		} catch (Exception $e) {
			// Audit logging should never break the file operation.
			$this->logger->warning(
				message: '[FileAuditHandler] Failed to log file action ' . $action . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}//end try

	}//end logFileAction()

}//end class
