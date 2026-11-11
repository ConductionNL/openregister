<?php

/**
 * RequiresJsonBodyTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Trait;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Refuses a request body that is not JSON.
 *
 * Nextcloud's body parser may silently drop unknown content types or coerce
 * form-encoded array values inconsistently, so endpoints that must not depend
 * on those heuristics accept JSON (or no declared type) and answer 415 otherwise.
 * The using controller provides `$this->request`.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
trait RequiresJsonBodyTrait
{
	/**
	 * The 415 response for a request whose Content-Type is set and not application/json.
	 *
	 * Trailing media type parameters (charset, boundary) are ignored.
	 *
	 * @param string $route The method and route named in the refusal, e.g. `PATCH /api/x/{id}`.
	 *
	 * @return JSONResponse|null The refusal, or null to proceed.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	private function refuseNonJsonBody(string $route): ?JSONResponse {
		$contentType = (string)$this->request->getHeader('Content-Type');
		$mediaType   = strtolower(trim(explode(';', $contentType, 2)[0]));
		if ($mediaType === 'application/json' || $mediaType === '') {
			return null;
		}

		return new JSONResponse(
			data: ['error' => 'unsupported_media_type', 'reason' => $route . ' requires Content-Type: application/json'],
			statusCode: Http::STATUS_UNSUPPORTED_MEDIA_TYPE
		);
	}//end refuseNonJsonBody()
}//end trait
