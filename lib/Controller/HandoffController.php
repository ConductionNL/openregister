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

use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Handoff\HandoffService;
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
	 * Constructor.
	 *
	 * @param string $appName App name (injected by NC).
	 * @param IRequest $request Request (injected by NC).
	 * @param HandoffService $handoffService The handoff engine.
	 * @param LoggerInterface $logger Structured logging.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly HandoffService $handoffService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

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
		try {
			$handoffs = $this->handoffService->listAvailability(register: $register, schema: $schema, id: $id);
			return new JSONResponse(data: ['handoffs' => $handoffs]);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(data: ['error' => 'forbidden', 'message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (HandoffException|DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'not-found', 'message' => 'Object not found.'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[HandoffController] availability failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);
			return new JSONResponse(
				data: ['error' => 'internal', 'message' => 'Handoff availability failed.'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end availability()

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
		try {
			$result = $this->handoffService->execute(register: $register, schema: $schema, id: $id, handoffId: $handoffId);

			$status = Http::STATUS_OK;
			if (($result['status'] ?? '') === 'parked') {
				$status = Http::STATUS_ACCEPTED;
			}

			return new JSONResponse(data: $result, statusCode: $status);
		} catch (HandoffException $e) {
			if ($e->getErrorCode() === HandoffException::PROVIDER_UNAVAILABLE) {
				// Machine-readable degradation — a missing provider is a
				// conflict with current install state, never a 5xx.
				return new JSONResponse(
					data: ['error' => HandoffException::PROVIDER_UNAVAILABLE, 'message' => $e->getMessage()],
					statusCode: Http::STATUS_CONFLICT
				);
			}

			return new JSONResponse(
				data: ['error' => HandoffException::NOT_DECLARED, 'message' => $e->getMessage()],
				statusCode: Http::STATUS_NOT_FOUND
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(data: ['error' => 'forbidden', 'message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (ValidationException $e) {
			return new JSONResponse(data: ['error' => 'validation', 'message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'not-found', 'message' => 'Object not found.'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[HandoffController] execute failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);
			return new JSONResponse(
				data: ['error' => 'internal', 'message' => 'Handoff execution failed.'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end execute()
}//end class
