<?php

/**
 * Object Text Extraction Background Job
 *
 * One-time background job that extracts text from OpenRegister objects asynchronously.
 * This job is queued automatically when objects are created or modified to avoid
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
 * One-time background job for object text extraction
 *
 * This job is automatically queued when objects are created or modified to
 * extract text content asynchronously without blocking the user's request.
 *
 * Features:
 * - Runs once per object in the background
 * - Non-blocking: doesn't slow down object saves
 * - Automatic retry for failed extractions
 * - Comprehensive logging and error handling
 * - Extracts text from object properties, metadata, and relationships
 *
 * @package OCA\OpenRegister\BackgroundJob
 */
class ObjectTextExtractionJob extends AbstractTextExtractionJob {
	protected const ARGUMENT_KEY = 'object_id';

	protected const SOURCE_FILE = __FILE__;

	protected const DISABLED_MESSAGE = 'Object extraction is disabled. Not extracting text from objects.';

	/**
	 * Whether object text extraction is switched on in the objectManagement setting.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function isEnabled(): bool {
		$objMgmtValue = $this->config->getValueString(app: 'openregister', key: 'objectManagement', default: '{}');
		$objectSettings = json_decode($objMgmtValue, true);
		return ($objectSettings['objectExtractionMode'] ?? 'background') !== 'none';
	}//end isEnabled()

	/**
	 * Extract the text of one object.
	 *
	 * @param int $id The object id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function extract(int $id): void {
		$this->textExtractor->extractObject(objectId: $id, forceReExtract: false);
	}//end extract()
}//end class
