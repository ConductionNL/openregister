<?php

/**
 * The handlers that cache, preview, export and upload configurations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The cache, preview, export and upload handlers of the configuration service.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationTransferHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param CacheHandler   $cacheHandler   Cache handler for configuration caching
	 * @param PreviewHandler $previewHandler Preview handler for preview operations
	 * @param ExportHandler  $exportHandler  Export handler for export operations
	 * @param UploadHandler  $uploadHandler  Upload handler for file upload operations
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly CacheHandler $cacheHandler,
		public readonly PreviewHandler $previewHandler,
		public readonly ExportHandler $exportHandler,
		public readonly UploadHandler $uploadHandler,
	) {
	}//end __construct()
}//end class
