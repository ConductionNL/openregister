<?php

/**
 * ValidationErrorResponseInterface: turns a validation failure into a response.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The error response of ValidateObject.
 *
 * Callers that need only this role depend on it, not on the whole class.
 */
interface ValidationErrorResponseInterface {

	/**
	 * Handles validation exceptions by formatting them into a JSON response.
	 *
	 * @param ValidationException|CustomValidationException $exception The validation exception.
	 *
	 * @return JSONResponse JSON error response with validation errors and 400 status code.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function handleValidationException(ValidationException|CustomValidationException $exception): JSONResponse;
}//end interface
