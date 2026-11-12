<?php

/**
 * The codecs ImportService translates and maps source rows with.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\Translation\TranslationCsvCodec;

/**
 * The translation codec and the migration-pack mapping engine of an import.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportServiceCodecs {

	/**
	 * Constructor.
	 *
	 * @param TranslationCsvCodec $translationCsvCodec Projects translation columns on import.
	 * @param MappingEngine       $mappingEngine       Maps rows through a migration pack.
	 */
	public function __construct(
		public readonly TranslationCsvCodec $translationCsvCodec,
		public readonly MappingEngine $mappingEngine,
	) {
	}//end __construct()
}//end class
