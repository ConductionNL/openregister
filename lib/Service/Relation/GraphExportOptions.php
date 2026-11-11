<?php

/**
 * How far a relation graph export reaches, and in which language it names relations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * The depth asked for and the BCP-47 tag of a graph export.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class GraphExportOptions {
	/**
	 * Describe the export.
	 *
	 * @param int    $depth    The depth asked for.
	 * @param string $language The BCP-47 tag.
	 */
	public function __construct(
		public readonly int $depth = 1,
		public readonly string $language = 'nl',
	) {
	}//end __construct()
}//end class
