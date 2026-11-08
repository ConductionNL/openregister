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
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\RevertHandlerInterface;
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
	 * The status each refusal of the handler answers with, checked in this order; any other is a 500.
	 *
	 * @var array<class-string<\Exception>, int>
	 */
	private const REFUSAL_STATUS = [
		NotAuthorizedException::class => 403,
		LockedException::class => 423,
		ObjectStateWriteException::class => ObjectStateWriteException::HTTP_STATUS,
		ValidationException::class => 400,
	];

	/**
	 * Constructor for RevertController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param RevertHandlerInterface $revertService The revert service
	 * @param ObjectOperationsInterface|null $objectService Renders the reverted object as every object read does
	 *                                          (property rules, write-only, explicit reveal); null only in tests
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RevertHandlerInterface $revertService,
		private readonly ?ObjectOperationsInterface $objectService = null,
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
			return $this->revertTo(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), data: $this->request->getParams());
		} catch (\Exception $e) {
			return self::refusal(exception: $e);
		}
	}//end revert()

	/**
	 * Revert an object to the point the request names, and answer the read.
	 *
	 * @param ObjectLinkRoute      $route The register identifier, the schema identifier and the object ID
	 * @param array<string, mixed> $data  The request parameters
	 *
	 * @return JSONResponse The reverted object, or 400 when no revert point is named
	 *
	 * @throws \Exception Every refusal of the handler, mapped by refusal()
	 */
	private function revertTo(ObjectLinkRoute $route, array $data): JSONResponse {
		$until = self::revertPoint(data: $data);
		if ($until === null) {
			return new JSONResponse(data: ['error' => 'Must specify either datetime, auditTrailId, or version'], statusCode: 400);
		}

		$revertedObject = $this->revertService->revert(
			register: $route->register,
			schema: $route->schema,
			id: $route->id,
			until: $until,
			overwriteVersion: ($data['overwriteVersion'] ?? false)
		);

		return $this->answer(revertedObject: $revertedObject);
	}//end revertTo()

	/**
	 * The revert point the request names: a point in time, an audit trail entry or a version, in that order.
	 *
	 * @param array<string, mixed> $data The request parameters
	 *
	 * @return DateTime|mixed|null The revert point, or null when none is named
	 *
	 * @throws \Exception When the datetime cannot be read
	 */
	private static function revertPoint(array $data): mixed {
		if (($data['datetime'] ?? null) !== null) {
			return new DateTime($data['datetime']);
		}

		if (($data['auditTrailId'] ?? null) !== null) {
			return $data['auditTrailId'];
		}

		return ($data['version'] ?? null);
	}//end revertPoint()

	/**
	 * The answer to a revert: a read of the object.
	 *
	 * Rendered under the caller's rules, never the raw stored entity (an
	 * explicit-reveal value stays masked).
	 *
	 * @param ObjectEntity $revertedObject The reverted object
	 *
	 * @return JSONResponse The 200
	 */
	private function answer(ObjectEntity $revertedObject): JSONResponse {
		if ($this->objectService !== null) {
			return new JSONResponse(data: $this->objectService->renderEntity(entity: $revertedObject, _rbac: true));
		}

		return new JSONResponse(data: $revertedObject->jsonSerialize());
	}//end answer()

	/**
	 * A refusal as its status: each keeps its own rather than collapsing into a 500.
	 *
	 * A frozen object refuses a revert as it refuses any write (#4105); a
	 * validation failure means the restored data no longer fits the current schema.
	 *
	 * @param \Exception $exception The refusal
	 *
	 * @return JSONResponse The mapped response
	 */
	private static function refusal(\Exception $exception): JSONResponse {
		if ($exception instanceof DoesNotExistException) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}

		$status = 500;
		foreach (self::REFUSAL_STATUS as $class => $mapped) {
			if ($exception instanceof $class) {
				$status = $mapped;
				break;
			}
		}

		return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $status);
	}//end refusal()
}//end class
