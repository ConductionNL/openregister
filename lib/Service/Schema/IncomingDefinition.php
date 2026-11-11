<?php

/**
 * OpenRegister IncomingDefinition
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * A schema definition as it is about to be written, and the renames it declares.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class IncomingDefinition {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>  $definition The incoming definition (properties/required).
	 * @param array<string, string> $renames    Declared renames, old name to new name.
	 */
	public function __construct(
		public readonly array $definition,
		public readonly array $renames = [],
	) {
	}//end __construct()
}//end class
