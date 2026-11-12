<?php

/**
 * OpenRegister Import Preview Matching
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

use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPackService;

/**
 * How a source row becomes a candidate object: the match lookup, the saved column mapping and the row transform.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final readonly class ImportPreviewMatching {

	/**
	 * Bundle the collaborators.
	 *
	 * @param MatchResolver $matchResolver The match lookup.
	 * @param MappingEngine $mappingEngine The saved column mapping.
	 * @param MigrationPackService $packService Saved mappings.
	 * @param ImportService $importService The row transform the write path uses.
	 */
	public function __construct(
		public MatchResolver $matchResolver,
		public MappingEngine $mappingEngine,
		public MigrationPackService $packService,
		public ImportService $importService,
	) {
	}//end __construct()
}//end class
