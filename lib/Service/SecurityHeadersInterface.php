<?php

/**
 * SecurityHeadersInterface: what the user API's responses ask of SecurityService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Adding the security headers to a JSON response. Only the method the user API's responses call.
 */
interface SecurityHeadersInterface {

	/**
	 * Add security headers to response
	 *
	 * @param JSONResponse $response The response to add headers to
	 *
	 * @return JSONResponse The response with added security headers
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-cors-policy-must-be-enforced-per-consumer-and-prevent-csrf
	 */
	public function addSecurityHeaders(JSONResponse $response): JSONResponse;
}//end interface
