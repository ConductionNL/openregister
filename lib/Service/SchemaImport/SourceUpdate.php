<?php

/**
 * OpenRegister source update
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
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

namespace OCA\OpenRegister\Service\SchemaImport;

/**
 * An update of an imported schema from its source: the stored import source, the current properties and the conflicts the caller confirmed.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final class SourceUpdate {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>               $importSource      The stored configuration.importSource block (with baseline).
	 * @param array<string, array<string,mixed>> $currentProperties The schema's current properties.
	 * @param array<int, string>                 $resolvedConflicts Conflict property names confirmed by the caller.
	 */
	public function __construct(
		public readonly array $importSource,
		public readonly array $currentProperties,
		public readonly array $resolvedConflicts = [],
	) {
	}//end __construct()
}//end class
