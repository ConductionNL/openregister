<?php

/**
 * The handlers that follow a file through its life.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * The history, batch and preview handlers of the file service.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileLifecycleHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FileHistoryHandlers $history            The versioning, lock and audit handlers
	 * @param FileBatchHandler    $fileBatchHandler   File batch handler
	 * @param FilePreviewHandler  $filePreviewHandler File preview handler
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly FileHistoryHandlers $history,
		public readonly FileBatchHandler $fileBatchHandler,
		public readonly FilePreviewHandler $filePreviewHandler,
	) {
	}//end __construct()
}//end class
