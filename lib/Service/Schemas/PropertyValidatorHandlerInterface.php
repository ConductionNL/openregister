<?php

/**
 * PropertyValidatorHandlerInterface: what callers outside the Service layer use of PropertyValidatorHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use Exception;

/**
 * Validates a schema's property definitions against the JSON Schema vocabulary
 * the app accepts.
 */
interface PropertyValidatorHandlerInterface {

	/**
	 * Validate an entire properties object.
	 *
	 * @param array  $properties The properties object to validate.
	 * @param string $path       The current path in the schema.
	 *
	 * @throws Exception If any property definition is invalid.
	 *
	 * @return true True if all properties are valid.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function validateProperties(array $properties, string $path = ''): bool;
}//end interface
