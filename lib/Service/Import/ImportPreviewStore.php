<?php

/**
 * OpenRegister Import Preview Store
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
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

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use Psr\Log\LoggerInterface;

/**
 * Where a preview lives: the preview record, its per-row decisions, the source file it reads and the log.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final readonly class ImportPreviewStore {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ImportPreviewMapper $previewMapper Preview persistence.
	 * @param ImportPreviewRowMapper $rowMapper Per-row decision persistence.
	 * @param SourceRowReader $reader The file reader.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		public ImportPreviewMapper $previewMapper,
		public ImportPreviewRowMapper $rowMapper,
		public SourceRowReader $reader,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
