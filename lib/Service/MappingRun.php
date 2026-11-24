<?php

/**
 * OpenRegister MappingRun
 *
 * One execution of a mapping: the recipe and the input it maps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Mapping;

/**
 * The mapping recipe and the input of one single-item mapping (ADR-011 Rule 3).
 *
 * Built by {@see MappingService::executeMapping()} and handed to the private
 * helpers that all read the same two values; it never leaves that class.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class MappingRun {

	/**
	 * Constructor.
	 *
	 * @param Mapping $mapping The mapping recipe.
	 * @param array   $input   The input of the mapping.
	 */
	public function __construct(
		public readonly Mapping $mapping,
		public readonly array $input,
	) {
	}//end __construct()
}//end class
