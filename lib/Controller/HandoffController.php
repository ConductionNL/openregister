<?php

/**
 * OpenRegister Handoff Controller
 *
 * REST surface for the semantic-object-handoff engine (ADR-051): handoff
 * availability for an object and handoff execution. Both endpoints are
 * `#[NoAdminRequired]` with a per-object authorization guard in the method
 * body (ADR-005, gate no-admin-idor): the service loads the object through
 * `ObjectService` under the caller's RBAC (read for availability, write on
 * source + create on target for execute) — an unauthorized caller gets a
 * typed 403/404 before any write. No `#[PublicPage]`. `#[NoCSRFRequired]`
 * matches the rest of the OR `/api/*` surface (basic-auth API consumers —
 * Newman, sibling-app service calls); authentication + per-object RBAC stay
 * fully enforced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\HandoffControllerServices;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Handoff\HandoffServiceInterface;
use OCA\OpenRegister\Service\Handoff\HandoffSourceRef;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Handoff availability + execution endpoints.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: Handoff REST surface)
 */
class HandoffController extends Controller {

	/**
	 * The generic 500 message per endpoint.
	 *
	 * @var array<string, string>
	 */
	private const INTERNAL_ERROR_MESSAGES = [
		'availability' => 'Handoff availability failed.',
		'execute' => 'Handoff execution failed.',
	];

	/**
	 * The handoff engine.
	 *
	 * @var HandoffServiceInterface
	 */
	private readonly HandoffServiceInterface $handoffService;

	/**
	 * Structured logging.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request  Request (injected by NC).
	 * @param HandoffControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		HandoffControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->handoffService = $services->handoffService;
		$this->logger = $services->logger;
	}//end __construct()

	/**
	 * GET /api/objects/{register}/{schema}/{id}/handoffs
	 *
	 * Availability: every handoff the object's schema declares, each with
	 * state `available` (naming the resolved provider schema), `unavailable`
	 * (machine-readable reason for the "provider not installed" UI copy), or
	 * `queued` (a parked queue-mode entry exists).
	 *
	 * Per-object guard: the service loads the object via `ObjectService`
	 * under the caller's RBAC — read denial surfaces as 403/404 here.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object id/uuid.
	 *
	 * @return JSONResponse `{handoffs: [...]}` or a typed error payload.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Availability endpoint with provider present)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function availability(string $register, string $schema, string $id): JSONResponse {
		return $this->availabilityForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end availability()

	/**
	 * availability() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse `{handoffs: [...]}` or a typed error payload.
	 */
	private function availabilityForObject(ObjectRef $ref): JSONResponse {
		try {
			$handoffs = $this->handoffService->listAvailability(
				ref: new HandoffSourceRef(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id)
			);
			return new JSONResponse(data: ['handoffs' => $handoffs]);
		} catch (HandoffException $e) {
			return $this->objectNotFound();
		} catch (\Throwable $e) {
			return $this->failure(e: $e, operation: 'availability');
		}
	}//end availabilityForObject()

	/**
	 * POST /api/objects/{register}/{schema}/{id}/handoffs/{handoffId}
	 *
	 * Execute a declared handoff (or park it, queue mode). Typed outcomes:
	 * 200 executed (created target ref), 202 parked, 400 validation, 403
	 * permission (no escalation), 404 handoff-not-declared, 409
	 * handoff-provider-unavailable (hide mode — explicitly never a 5xx).
	 *
	 * Per-object guard: write permission on the source and create permission
	 * on the resolved target schema are enforced by the service as the
	 * calling user before any write.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object id/uuid.
	 * @param string $handoffId The declared handoff entry id.
	 *
	 * @return JSONResponse The execution result or typed error payload.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Successful request-to-case handoff)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function execute(string $register, string $schema, string $id, string $handoffId): JSONResponse {
		return $this->executeForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id), handoffId: $handoffId);
	}//end execute()

	/**
	 * execute() for the object the route names.
	 *
	 * @param ObjectRef $ref       The object the route names.
	 * @param string    $handoffId The declared handoff entry id.
	 *
	 * @return JSONResponse The execution result or typed error payload.
	 */
	private function executeForObject(ObjectRef $ref, string $handoffId): JSONResponse {
		try {
			$result = $this->handoffService->execute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id, handoffId: $handoffId);
			return new JSONResponse(data: $result, statusCode: $this->executedStatus(result: $result));
		} catch (HandoffException $e) {
			return $this->handoffRefusal(e: $e);
		} catch (ValidationException $e) {
			return new JSONResponse(data: ['error' => 'validation', 'message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return $this->failure(e: $e, operation: 'execute');
		}
	}//end executeForObject()

	/**
	 * 202 for a parked (queue-mode) handoff, 200 otherwise.
	 *
	 * @param array<string, mixed> $result The service result.
	 *
	 * @return int The HTTP status.
	 */
	private function executedStatus(array $result): int {
		if (($result['status'] ?? '') === 'parked') {
			return Http::STATUS_ACCEPTED;
		}

		return Http::STATUS_OK;
	}//end executedStatus()

	/**
	 * Map a handoff refusal: a missing provider is a machine-readable conflict
	 * with current install state, never a 5xx; anything else is not declared.
	 *
	 * @param HandoffException $e The handoff refusal.
	 *
	 * @return JSONResponse The 409 or 404.
	 */
	private function handoffRefusal(HandoffException $e): JSONResponse {
		if ($e->getErrorCode() === HandoffException::PROVIDER_UNAVAILABLE) {
			return new JSONResponse(
				data: ['error' => HandoffException::PROVIDER_UNAVAILABLE, 'message' => $e->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(
			data: ['error' => HandoffException::NOT_DECLARED, 'message' => $e->getMessage()],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end handoffRefusal()

	/**
	 * Map a failure shared by both endpoints: 403, 404, or a logged 500.
	 *
	 * @param \Throwable $e The failure.
	 * @param string $operation The endpoint, `availability` or `execute`.
	 *
	 * @return JSONResponse The error response.
	 */
	private function failure(\Throwable $e, string $operation): JSONResponse {
		if ($e instanceof NotAuthorizedException) {
			return new JSONResponse(data: ['error' => 'forbidden', 'message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($e instanceof DoesNotExistException) {
			return $this->objectNotFound();
		}

		return $this->internalError(e: $e, operation: $operation);
	}//end failure()

	/**
	 * The 404 for a source object that does not exist or cannot be seen.
	 *
	 * @return JSONResponse The 404.
	 */
	private function objectNotFound(): JSONResponse {
		return new JSONResponse(data: ['error' => 'not-found', 'message' => 'Object not found.'], statusCode: Http::STATUS_NOT_FOUND);
	}//end objectNotFound()

	/**
	 * Log an unexpected failure and answer a generic 500.
	 *
	 * @param \Throwable $e The failure.
	 * @param string $operation The endpoint, `availability` or `execute`.
	 *
	 * @return JSONResponse The 500.
	 */
	private function internalError(\Throwable $e, string $operation): JSONResponse {
		$this->logger->error(
			message: '[HandoffController] ' . $operation . ' failed: ' . $e->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
		);
		return new JSONResponse(
			data: ['error' => 'internal', 'message' => self::INTERNAL_ERROR_MESSAGES[$operation]],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end internalError()
}//end class
