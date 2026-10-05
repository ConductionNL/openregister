<?php

/**
 * The admin action that resets one part of one schema to what its app shipped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineResetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin only (no NoAdminRequired): GET previews, POST applies, one named part each.
 *
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */
class ShippedBaselineController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                      $appName The app name.
	 * @param IRequest                    $request The request.
	 * @param ShippedBaselineResetService $reset   The reset.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ShippedBaselineResetService $reset,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What resetting one part would change. Writes nothing.
	 *
	 * @param string $id   The schema id, uuid or slug.
	 * @param string $path The part, as a dotted path.
	 *
	 * @return JSONResponse The preview, 404 for an unknown schema.
	 *
	 * @NoCSRFRequired
	 * @auth admin-only a preview shows a schema's shipped authorization, which only an administrator reads
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function preview(string $id, string $path=''): JSONResponse {
		try {
			return new JSONResponse(data: $this->reset->preview(schema: $id, path: $path));
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'No schema ' . $id], statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end preview()

	/**
	 * Reset one part to the shipped value, as the signed-in administrator.
	 *
	 * @param string $id   The schema id, uuid or slug.
	 * @param string $path The part, as a dotted path.
	 *
	 * @return JSONResponse The outcome; 409 when nothing was reset, with the reason.
	 *
	 * @auth admin-only a reset changes who may read a schema's objects, so only an administrator applies it, with the CSRF check on
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function reset(string $id, string $path=''): JSONResponse {
		try {
			$outcome = $this->reset->reset(schema: $id, path: $path);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'No schema ' . $id], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($outcome['applied'] === false) {
			return new JSONResponse(data: $outcome, statusCode: Http::STATUS_CONFLICT);
		}

		return new JSONResponse(data: $outcome);
	}//end reset()
}//end class
