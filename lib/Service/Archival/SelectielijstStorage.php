<?php

/**
 * Where the selectielijst lives, and how its rows are read and written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;

/**
 * The selectielijst store: the settings that name its register, schema and
 * version in use, the catalog its rows are read through, and the writer that
 * stores a row.
 *
 * An ADR-011 Rule 4 aggregate, autowired. Its consumers are
 * SelectielijstResolver, SelectielijstImportService and SelectionListSeeder.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class SelectielijstStorage {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ArchivalSettingsReaderInterface $settings Names the selectielijst register, schema and version in use.
	 * @param SchemaCatalogInterface          $catalog  The register, schema and object mappers.
	 * @param SaveObjectWriterInterface       $writer   Stores a row.
	 */
	public function __construct(
		public readonly ArchivalSettingsReaderInterface $settings,
		public readonly SchemaCatalogInterface $catalog,
		public readonly SaveObjectWriterInterface $writer,
	) {
	}//end __construct()
}//end class
