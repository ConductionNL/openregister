<?php

/**
 * SubjectExportController — the data subject's own export, over HTTP.
 *
 * Three routes: ask, read the state back, take the file. The middle one exists
 * because the assembly is a background job, so a caller needs a way to find out
 * whether the answer is ready without guessing at a download that would 404.
 *
 * `@NoAdminRequired`, because an article 20 request is handled by a handler or
 * by the subject themselves, and the assembler is already RBAC and tenant
 * scoped. Reach is guarded in the service: an export belongs to the account
 * that asked for it, and an administrator.
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

use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Requests, reports and delivers a data subject's own export.
 */
class SubjectExportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string               $appName The app name.
	 * @param IRequest             $request The request.
	 * @param SubjectExportService $exports The export service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SubjectExportService $exports,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * POST /api/gdpr/subject-exports — ask for everything held about a subject.
	 *
	 * Body: `subject` (required), `type` (optional PII type), `request`
	 * (optional data subject request id). The assembly runs as a background
	 * job, so this answers immediately with a pending export.
	 *
	 * @return JSONResponse The recorded request.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded downstream: the assembler is
	 *   DataSubjectRequestService::assembleAccessExport, which loads every object via
	 *   MagicMapper::find(_rbac:true,_multitenancy:true), so a caller only ever exports what it may read.
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

		$export = $this->exports->request(
			subject: $subject,
			type: $this->optional(key: 'type'),
			requestId: $this->optional(key: 'request')
		);

		return new JSONResponse(data: $export->jsonSerialize());
	}//end create()

	/**
	 * GET /api/gdpr/subject-exports/{id} — is it ready, and until when.
	 *
	 * @param string $id The export uuid.
	 *
	 * @return JSONResponse The export state.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded in-body: SubjectExportService::load refuses an export the caller did not
	 *   request unless they are an administrator, and answers absent rather than forbidden so the existence of
	 *   a request about a data subject is not disclosed.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function show(string $id): JSONResponse {
		$export = $this->exports->load(uuid: $id);
		if ($export === null) {
			return new JSONResponse(
				data: ['error' => 'No subject export with that identifier exists.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: $export->jsonSerialize());
	}//end show()

	/**
	 * GET /api/gdpr/subject-exports/{id}/download — take the file.
	 *
	 * Refuses a link that outlived its expiry, and one whose assembly has not
	 * finished, before it assembles anything.
	 *
	 * @param string $id The export uuid.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded in-body and downstream: as show(), plus the assembler's own
	 *   _rbac/_multitenancy scoping on every object it reads.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function download(string $id): DataDownloadResponse|JSONResponse {
		$bytes = $this->exports->download(uuid: $id);
		if ($bytes === null) {
			return new JSONResponse(
				data: [
					'error' => 'SUBJECT_EXPORT_UNAVAILABLE',
					'message' => 'This export is not ready, has expired, or does not exist.',
				],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new DataDownloadResponse(
			data: $bytes,
			filename: 'subject-export-' . $id . '.json',
			contentType: 'application/json'
		);
	}//end download()

	/**
	 * An optional trimmed parameter, normalised to null when absent.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return string|null The value.
	 */
	private function optional(string $key): ?string {
		$value = trim((string)($this->request->getParam(key: $key) ?? ''));
		if ($value === '') {
			return null;
		}

		return $value;
	}//end optional()
}//end class
