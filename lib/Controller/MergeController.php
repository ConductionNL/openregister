<?php

/**
 * OpenRegister MergeController
 *
 * HTTP entry point for the RBAC-scoped MDM merge surface: preview, execute,
 * and reverse. Every method delegates entirely to
 * {@see \OCA\OpenRegister\Service\Merge\MergeService} — no merge logic lives
 * here. RBAC/tenant scoping comes from `ObjectService` inside the service
 * (the same posture as {@see DuplicateController} / {@see QualityController}):
 * a caller who cannot read/write the target objects gets a forbidden/not-found
 * response rather than a successful merge.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

class MergeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The application name.
	 * @param IRequest $request The current request.
	 * @param MergeService $mergeService MDM merge engine.
	 * @param IUserSession $userSession Current user session, for actor attribution.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MergeService $mergeService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Side-effect-free preview of a merge: projected survivor golden record,
	 * provenance and reversal deadline. No object, mergeOperation, or event
	 * is written.
	 *
	 * @return JSONResponse JSON response with the preview payload.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#5.1
	 */
	public function preview(): JSONResponse {
		$from = (string)$this->request->getParam('from', '');
		$into = (string)$this->request->getParam('into', '');

		try {
			$result = $this->mergeService->previewMerge(from: $from, into: $into);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end preview()

	/**
	 * Execute a merge atomically: relink source records, recompute the
	 * survivor, persist a `mergeOperation` audit row, and dispatch
	 * `ObjectsMergedEvent`.
	 *
	 * @return JSONResponse JSON response with the persisted `mergeOperation`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#5.1
	 */
	public function execute(): JSONResponse {
		$from = (string)$this->request->getParam('from', '');
		$into = (string)$this->request->getParam('into', '');
		$reason = (string)$this->request->getParam('reason', '');

		$mergedBy = ((string)($this->userSession->getUser()?->getUID() ?? ''));

		try {
			$result = $this->mergeService->executeMerge(
				from: $from,
				into: $into,
				reason: $reason,
				mergedBy: $mergedBy
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end execute()

	/**
	 * Reverse a merge within its reversal window, restoring both objects
	 * from the snapshot and flipping the operation to `reversible: false`.
	 *
	 * @param string $id The `mergeOperation` uuid.
	 *
	 * @return JSONResponse JSON response with the updated `mergeOperation`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#5.1
	 */
	public function reverse(string $id): JSONResponse {
		$reversedBy = ((string)($this->userSession->getUser()?->getUID() ?? ''));

		try {
			$result = $this->mergeService->reverseMerge(mergeOperationId: $id, reversedBy: $reversedBy);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end reverse()
}//end class
