<?php

/**
 * File Text Extraction Background Job
 *
 * One-time background job that extracts text from uploaded files asynchronously.
 * This job is queued automatically when files are created or modified to avoid
 * blocking user requests with potentially slow text extraction operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  BackgroundJob
 * @package   OCA\OpenRegister\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

/**
 * One-time background job for file text extraction
 *
 * This job is automatically queued when files are created or modified to
 * extract text content asynchronously without blocking the user's request.
 *
 * Features:
 * - Runs once per file in the background
 * - Non-blocking: doesn't slow down file uploads
 * - Automatic retry for failed extractions
 * - Comprehensive logging and error handling
 * - Supports all file formats (PDF, DOCX, images, etc.)
 *
 * @package OCA\OpenRegister\BackgroundJob
 */
class FileTextExtractionJob extends AbstractTextExtractionJob {
	protected const ARGUMENT_KEY = 'file_id';

	protected const SOURCE_FILE = __FILE__;

	protected const DISABLED_MESSAGE = 'File extraction is disabled. Not extracting text from files.';

	/**
	 * Whether file text extraction is switched on in the fileManagement setting.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	protected function isEnabled(): bool {
		$fileManagementKey = 'fileManagement';
		$fileManagementValue = $this->config->getValueString(app: 'openregister', key: $fileManagementKey);
		$fileManagement = json_decode($fileManagementValue, true);
		return $this->config->hasKey(app: 'openregister', key: $fileManagementKey) === true
			&& $fileManagement['extractionScope'] !== 'none';
	}//end isEnabled()

	/**
	 * Extract the text of one file.
	 *
	 * @param int $id The file id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	protected function extract(int $id): void {
		$this->textExtractor->extractFile(fileId: $id);
	}//end extract()
}//end class
