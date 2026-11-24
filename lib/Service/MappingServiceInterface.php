<?php

/**
 * MappingServiceInterface: what callers outside the Service component ask of MappingService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\Mapping;

/**
 * Runs a mapping recipe over an input array.
 */
interface MappingServiceInterface {

	/**
	 * Maps (transforms) an array (input) to a different array (output).
	 *
	 * One item; {@see executeListMapping()} maps a list.
	 *
	 * @param Mapping $mapping The mapping object that forms the recipe for the mapping
	 * @param array   $input   The array to map (transform)
	 *
	 * @return array The result (output) of the mapping process
	 *
	 * @throws Exception When mapping fails
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function executeMapping(Mapping $mapping, array $input): array;

	/**
	 * Map every item of a list, passing the extra values of a `listInput` wrapper down to each item.
	 *
	 * @param Mapping $mapping The mapping recipe.
	 * @param array   $input   The list, or a `listInput` wrapper with extra values.
	 *
	 * @return array The mapped items, keyed as in the list.
	 *
	 * @throws Exception When mapping an item fails
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function executeListMapping(Mapping $mapping, array $input): array;
}//end interface
