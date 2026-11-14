<?php

/**
 * The admin decision on a part an app upgrade kept local: list them, accept the shipped definition for one.
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
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ShippedBaseline\ShippedConflictServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin only (no NoAdminRequired): GET lists the pending conflicts, POST accepts the shipped definition for one.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#requirement-an-upgrade-never-silently-discards-a-local-change-req-lca-003
 */
class ShippedConflictController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                          $appName   The app name.
	 * @param IRequest                        $request   The request.
	 * @param ShippedConflictServiceInterface $conflicts The pending conflicts and their acceptance.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ShippedConflictServiceInterface $conflicts,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The parts the last upgrade of a schema kept local, with both definitions. Writes nothing.
	 *
	 * @param string $id The schema id, uuid or slug.
	 *
	 * @return JSONResponse The pending conflicts, 404 for an unknown schema.
	 *
	 * @NoCSRFRequired
	 *
	 * @auth admin-only the list shows a schema's shipped and local authorization, which only an administrator reads
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#requirement-an-upgrade-never-silently-discards-a-local-change-req-lca-003
	 */
	public function conflicts(string $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->conflicts->pending(schema: $id));
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'No schema ' . $id], statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end conflicts()

	/**
	 * Accept the shipped definition for one conflicting part, as the signed-in administrator.
	 *
	 * @param string $id   The schema id, uuid or slug.
	 * @param string $path The conflicting part, as a dotted path.
	 *
	 * @return JSONResponse The outcome; 409 when nothing was accepted, with the reason.
	 *
	 * @auth admin-only accepting can change who may read a schema's objects, so only an administrator applies it, with the CSRF check on
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#requirement-an-upgrade-never-silently-discards-a-local-change-req-lca-003
	 */
	public function accept(string $id, string $path=''): JSONResponse {
		try {
			$outcome = $this->conflicts->accept(schema: $id, path: $path);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'No schema ' . $id], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($outcome['applied'] === false) {
			return new JSONResponse(data: $outcome, statusCode: Http::STATUS_CONFLICT);
		}

		return new JSONResponse(data: $outcome);
	}//end accept()
}//end class
