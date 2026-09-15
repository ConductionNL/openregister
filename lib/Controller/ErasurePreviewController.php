<?php

/**
 * ErasurePreviewController — the HTTP surface of the previewed erasure.
 *
 * Four routes, in the order a handler uses them: take the preview, read it
 * back, approve it, run it. The split is the point. `POST /api/gdpr/erase`
 * still exists and still erases in one call for the callers that had already
 * decided; this surface is for the request a gemeente has to ANSWER, where the
 * protected records and their grounds are the half the answer is made of.
 *
 * Every route is `@NoAdminRequired`: an AVG request is handled by a handler,
 * not by an administrator, and the scoping is the service's — the discovery
 * loads every object through MagicMapper with `_rbac` and `_multitenancy` on,
 * and a preview row is reachable only by its author or an administrator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Preview, approve and run an AVG erasure.
 */
class ErasurePreviewController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                $appName        The app name.
	 * @param IRequest              $request        The request.
	 * @param ErasurePreviewService $previewService The preview.
	 * @param ErasurePreviewStore   $store          The recorded preview and its approval.
	 * @param ErasureRunner         $runner         The run.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ErasurePreviewService $previewService,
		private readonly ErasurePreviewStore $store,
		private readonly ErasureRunner $runner,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * POST /api/gdpr/erasure-previews — count what an erasure would touch.
	 *
	 * Body: `subject` (required), `type` (optional PII type), `eraseMode`
	 * (`pseudonymise`|`whole-object`), `request` (optional data subject request
	 * id the preview answers).
	 *
	 * Nothing in the subject's data is written. The preview itself is recorded,
	 * so it can be approved and so the answer sent to the data subject can be
	 * shown to be the answer that was acted on.
	 *
	 * @return JSONResponse The recorded preview.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded downstream: ErasurePreviewService discovers through
	 *   DataSubjectRequestService::findSubjectObjects, which loads every object via
	 *   MagicMapper::find(_rbac:true,_multitenancy:true), so a caller only ever counts objects it may read.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function create(): JSONResponse {
		$subject = trim((string)($this->request->getParam(key: 'subject') ?? ''));
		if ($subject === '') {
			return new JSONResponse(
				data: ['error' => 'subject is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$preview = $this->previewService->preview(
			subjectId: $subject,
			type: $this->optionalType(),
			eraseMode: (string)($this->request->getParam(key: 'eraseMode')
				?? DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE)
		);

		$record = $this->store->record(
			preview: $preview,
			requestId: $this->optionalRequestId()
		);

		return new JSONResponse(data: $record->jsonSerialize());
	}//end create()

	/**
	 * GET /api/gdpr/erasure-previews/{id} — read a recorded preview back.
	 *
	 * @param string $id The preview uuid.
	 *
	 * @return JSONResponse The preview, or the refusal that names the rule.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded in-body: ErasurePreviewStore::load refuses any preview the caller did not
	 *   create, unless they are an administrator, and answers `unknown` rather than `forbidden` so the existence
	 *   of a request about a data subject is not disclosed.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function show(string $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->store->load(uuid: $id)->jsonSerialize());
		} catch (ErasureRefusedException $e) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: $e->getStatusCode());
		}
	}//end show()

	/**
	 * POST /api/gdpr/erasure-previews/{id}/approve — approve it for running.
	 *
	 * @param string $id The preview uuid.
	 *
	 * @return JSONResponse The approved preview, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded in-body: as show(), through ErasurePreviewStore::load.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function approve(string $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->store->approve(uuid: $id)->jsonSerialize());
		} catch (ErasureRefusedException $e) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: $e->getStatusCode());
		}
	}//end approve()

	/**
	 * POST /api/gdpr/erasure-previews/{id}/run — erase, from this preview.
	 *
	 * Refuses when the preview was never approved, when it has already been
	 * run, and when the records the subject appears on have changed since the
	 * approval. Nothing is written in any of those three cases.
	 *
	 * @param string $id The preview uuid.
	 *
	 * @return JSONResponse What the run did, or the refusal that names the rule.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded in-body and downstream: ErasurePreviewStore::requireRunnable refuses a
	 *   preview the caller did not create, and every destruction is re-checked per object by
	 *   DestroyRightService::refusalFor and RetentionClockService::refusalFor.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function run(string $id): JSONResponse {
		try {
			$record = $this->store->requireRunnable(uuid: $id);

			return new JSONResponse(data: $this->runner->run(record: $record));
		} catch (ErasureRefusedException $e) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: $e->getStatusCode());
		}
	}//end run()

	/**
	 * The optional PII type filter, normalised to null when absent.
	 *
	 * @return string|null The type.
	 */
	private function optionalType(): ?string {
		$type = trim((string)($this->request->getParam(key: 'type') ?? ''));
		if ($type === '') {
			return null;
		}

		return $type;
	}//end optionalType()

	/**
	 * The optional data subject request id the preview answers.
	 *
	 * @return string|null The request id.
	 */
	private function optionalRequestId(): ?string {
		$requestId = trim((string)($this->request->getParam(key: 'request') ?? ''));
		if ($requestId === '') {
			return null;
		}

		return $requestId;
	}//end optionalRequestId()
}//end class
