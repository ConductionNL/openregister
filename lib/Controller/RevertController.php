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
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Controller\Support\RevertControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\RevertPoint;
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
	 * The status and fixed sentence each refusal of the handler answers with,
	 * checked in this order; any other is a 500. No exception text reaches a body.
	 *
	 * @var array<class-string<\Exception>, array{0: int, 1: string}>
	 */
	private const REFUSALS = [
		DoesNotExistException::class => [404, 'Record not found.'],
		NotAuthorizedException::class => [403, 'You may not restore this record.'],
		LockedException::class => [423, 'This record is locked by someone else.'],
		ObjectStateWriteException::class => [ObjectStateWriteException::HTTP_STATUS, 'This record is archived or frozen and cannot be changed.'],
		HookStoppedException::class => [422, 'A rule on this record refused the restore.'],
	];

	/**
	 * The sentence a revert that failed for any other reason answers with.
	 */
	private const FAILED = 'The record could not be restored.';

	/**
	 * The sentence a restored version the current schema refuses answers with.
	 */
	private const NO_LONGER_VALID = 'This version does not fit the record\'s schema as it is now.';

	/**
	 * The revert service, the read renderer and the logger
	 *
	 * @var RevertControllerServices
	 */
	private readonly RevertControllerServices $services;

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request  The request object
	 * @param RevertControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		RevertControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->services = $services;
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
	 * @return JSONResponse JSON response with reverted object or error
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-11
	 */
	public function revert(string $register, string $schema, string $id): JSONResponse {
		return $this->revertOrRefuse(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
	}//end revert()

	/**
	 * Revert the object the route names to the requested point, or answer the refusal.
	 *
	 * @param ObjectLinkRoute $route The object as the route names it.
	 *
	 * @return JSONResponse The reverted object, or the refusal.
	 */
	private function revertOrRefuse(ObjectLinkRoute $route): JSONResponse {
		try {
			return $this->revertTo(route: $route, data: $this->request->getParams());
		} catch (\Exception $e) {
			return $this->refusal(exception: $e);
		}
	}//end revertOrRefuse()

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

		$revertedObject = $this->services->revertService->revert(
			register: $route->register,
			schema: $route->schema,
			id: $route->id,
			point: new RevertPoint(until: $until, overwriteVersion: ($data['overwriteVersion'] ?? false))
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
		if ($this->services->objectService !== null) {
			return new JSONResponse(data: $this->services->objectService->renderEntity(entity: $revertedObject, _rbac: true));
		}

		return new JSONResponse(data: $revertedObject->jsonSerialize());
	}//end answer()

	/**
	 * A refusal as its status and a fixed sentence: each keeps its own status
	 * rather than collapsing into a 500, and no exception text is answered.
	 *
	 * A frozen object refuses a revert as it refuses any write (#4105). A
	 * restored version the current schema refuses answers 422 with the
	 * validation errors, the shape an edit gets. Anything else is a 500 whose
	 * request id is also on the log entry holding the exception.
	 *
	 * @param \Exception $exception The refusal
	 *
	 * @return JSONResponse The mapped response
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	private function refusal(\Exception $exception): JSONResponse {
		if ($exception instanceof ValidationException) {
			return $this->validationRefusal(exception: $exception);
		}

		foreach (self::REFUSALS as $class => [$status, $sentence]) {
			if ($exception instanceof $class) {
				$data = ['error' => $sentence];
				if ($exception instanceof HookStoppedException) {
					$data['errors'] = $exception->getErrors();
				}

				return new JSONResponse(data: $data, statusCode: $status);
			}
		}

		$requestId = (string) $this->request->getId();
		$this->services->logger?->error(
			message: '[RevertController] A revert failed',
			context: ['requestId' => $requestId, 'exception' => $exception]
		);

		return new JSONResponse(data: ['error' => self::FAILED, 'requestId' => $requestId], statusCode: 500);
	}//end refusal()

	/**
	 * The 422 of a restored version the current schema refuses: the validation
	 * errors as an edit answers them, under a fixed sentence.
	 *
	 * @param ValidationException $exception The validation failure
	 *
	 * @return JSONResponse The 422
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	private function validationRefusal(ValidationException $exception): JSONResponse {
		if ($this->services->objectService !== null) {
			$edit = $this->services->objectService->handleValidationException(exception: $exception)->getData();

			return new JSONResponse(data: ['error' => self::NO_LONGER_VALID, 'errors' => ($edit['errors'] ?? [])], statusCode: 422);
		}

		return new JSONResponse(
			data: ['error' => self::NO_LONGER_VALID, 'errors' => [['message' => $exception->getMessage()]]],
			statusCode: 422
		);
	}//end validationRefusal()
}//end class
