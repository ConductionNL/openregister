<?php

/**
 * TextExtractionServiceInterface: the public surface of TextExtractionService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use Exception;

/**
 * Every public method of TextExtractionService. Callers depend on this interface, not on the class.
 */
interface TextExtractionServiceInterface {

	/**
	 * Extract text from an object by object ID
	 *
	 * This method:
	 * 1. Looks up object in the database
	 * 2. Checks if re-extraction is needed (object modified since last extraction)
	 * 3. Performs extraction if needed using ObjectHandler
	 *
	 * @param int $objectId Object ID
	 * @param bool $forceReExtract Force re-extraction even if object hasn't changed
	 *
	 * @return void
	 *
	 * @throws Exception If extraction fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flag needed for force re-extraction behavior
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function extractObject(int $objectId, bool $forceReExtract = false): void;
}//end interface
