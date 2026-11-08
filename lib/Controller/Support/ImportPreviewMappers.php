<?php

/**
 * OpenRegister import preview mappers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ImportPreviewMapperInterface;
use OCA\OpenRegister\Db\ImportPreviewRowMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;

/**
 * The mappers and repositories of the import preview API.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class ImportPreviewMappers {
	/**
	 * Bundle the services.
	 *
	 * @param ImportPreviewMapperInterface    $previewMapper  Preview persistence.
	 * @param ImportPreviewRowMapperInterface $rowMapper      Per-row decision persistence.
	 * @param RegisterMapperInterface         $registerMapper Register lookup.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ImportPreviewMapperInterface $previewMapper,
		public readonly ImportPreviewRowMapperInterface $rowMapper,
		public readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()
}//end class
