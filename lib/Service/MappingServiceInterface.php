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
	 * @param Mapping $mapping The mapping object that forms the recipe for the mapping
	 * @param array   $input   The array to map (transform)
	 * @param bool    $list    Whether we want a list instead of a single item
	 *
	 * @return array The result (output) of the mapping process
	 *
	 * @throws Exception When mapping fails
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function executeMapping(Mapping $mapping, array $input, bool $list = false): array;
}//end interface
