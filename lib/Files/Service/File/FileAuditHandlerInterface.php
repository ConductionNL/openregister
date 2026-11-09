<?php

/**
 * FileAuditHandlerInterface
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

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Writes the audit-trail rows of file actions and bulk downloads.
 *
 * The capability the file service hands out through
 * {@see \OCA\OpenRegister\Service\FileServiceInterface::getAuditHandler()}, so
 * callers outside the Files component never name the concrete handler.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface FileAuditHandlerInterface {

	/**
	 * Log a bulk download event (ZIP archive) as a single audit-trail row.
	 *
	 * @param ObjectEntity        $object  The parent object whose files were zipped.
	 * @param BulkDownloadArchive $archive The file ids, file names, ZIP name and byte total of the archive.
	 *
	 * @return AuditTrail|null The persisted audit row, or null on failure.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function logBulkDownload(ObjectEntity $object, BulkDownloadArchive $archive): ?AuditTrail;

	/**
	 * Log a file-action audit trail entry tied to the parent object.
	 *
	 * @param ObjectEntity $object The parent object the file belongs to.
	 * @param int          $fileId The file ID the action targeted.
	 * @param string       $action The namespaced action (e.g. 'file.renamed').
	 * @param array        $data   Action-specific metadata (newName, targetUuid, etc.).
	 *
	 * @return AuditTrail|null The persisted audit row, or null on failure.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function logFileAction(
		ObjectEntity $object,
		int $fileId,
		string $action,
		array $data = [],
	): ?AuditTrail;
}//end interface
