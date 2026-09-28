<?php

/**
 * Class RevertController
 *
 * Controller for managing object reversion operations in the OpenRegister app.
 * Provides functionality to revert objects to previous states based on different criteria.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-11
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Class RevertController
 *
 * Handles all object reversion operations.
 *
 * @psalm-suppress UnusedClass
 */
class RevertController extends Controller {
	/**
	 * Constructor for RevertController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param RevertHandler $revertService The revert service
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RevertHandler $revertService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Revert an object to a previous state
	 *
	 * This endpoint allows reverting an object to a previous state based on different criteria:
	 * 1. DateTime - Revert to the state at a specific point in time
	 * 2. Audit Trail ID - Revert to the state after a specific audit trail entry
	 * 3. Semantic Version - Revert to a specific version of the object
	 *
	 * @param string $register The register identifier
	 * @param string $schema The schema identifier
	 * @param string $id The object ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with reverted object or error
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-11
	 */
	public function revert(string $register, string $schema, string $id): JSONResponse {
		try {
			$data = $this->request->getParams();

			// Parse the revert point.
			$until = null;
			if (($data['datetime'] ?? null) !== null) {
				$until = new DateTime($data['datetime']);
			} elseif (($data['auditTrailId'] ?? null) !== null) {
				$until = $data['auditTrailId'];
			} elseif (($data['version'] ?? null) !== null) {
				$until = $data['version'];
			}

			if ($until === null) {
				return new JSONResponse(
					data: ['error' => 'Must specify either datetime, auditTrailId, or version'],
					statusCode: 400
				);
			}

			// Determine if we should overwrite the version.
			$overwriteVersion = $data['overwriteVersion'] ?? false;

			// Revert the object.
			$revertedObject = $this->revertService->revert(
				register: $register,
				schema: $schema,
				id: $id,
				until: $until,
				overwriteVersion: $overwriteVersion
			);

			return new JSONResponse(data: $revertedObject->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse(exception: $e);
		}//end try
	}//end revert()

	/**
	 * The response for a revert that failed.
	 *
	 * An archived or frozen object refuses the revert with 409, and restored
	 * data the schema no longer accepts with 400 (openregister#4105).
	 *
	 * @param \Exception $exception What the revert threw.
	 *
	 * @return JSONResponse The error response.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function errorResponse(\Exception $exception): JSONResponse {
		if ($exception instanceof DoesNotExistException) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}

		$status = match (true) {
			$exception instanceof NotAuthorizedException => 403,
			$exception instanceof LockedException => 423,
			$exception instanceof ObjectStateWriteException => ObjectStateWriteException::HTTP_STATUS,
			$exception instanceof ValidationException => 400,
			default => 500,
		};

		return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $status);
	}//end errorResponse()
}//end class
