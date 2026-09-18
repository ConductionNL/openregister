<?php

/**
 * Reported content and the copies taken of it, as an API.
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
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\ContentReportMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Filing is open, reading a copy is not.
 *
 * The asymmetry is the design. Anybody who can see content must be able to
 * report it, or reporting is a privilege and the material nobody reviews is
 * the material nobody privileged happened to see. Reading the COPY is a
 * different act: it is reading content that was reported, frozen, and kept
 * after removal, and it belongs to the people reviewing it.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class ContentReportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string              $appName     App identifier.
	 * @param IRequest            $request     Active request.
	 * @param ContentReportMapper $reports     The reports and their copies.
	 * @param ContentReportService $service    Files a report and resolves reviewer access.
	 * @param MagicMapper         $objects     Resolves the content being reported.
	 * @param IUserSession        $userSession Current user session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ContentReportMapper $reports,
		private readonly ContentReportService $service,
		private readonly MagicMapper $objects,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * POST /api/content-reports — report content, which takes the copy.
	 *
	 * Open to any authenticated caller, deliberately. The copy is taken HERE,
	 * at filing, and not when a removal runs: a copy that races the delete is
	 * a copy that loses the race precisely when it matters.
	 *
	 * @return JSONResponse The filed report, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$objectId = trim((string)($this->request->getParam(key: 'object') ?? ''));
		if ($objectId === '') {
			return new JSONResponse(
				data: ['error' => 'object is required'],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$reason = trim((string)($this->request->getParam(key: 'reason') ?? ''));
		if ($reason === '') {
			return new JSONResponse(
				data: ['error' => 'reason is required'],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			$object = $this->objects->find($objectId);
		} catch (Throwable $notFound) {
			return new JSONResponse(
				data: ['error' => 'Not Found', 'object' => $objectId],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		try {
			$report = $this->service->file(object: $object, reason: $reason, reporter: $user->getUID());
		} catch (Throwable $writeFailed) {
			return new JSONResponse(
				data: ['error' => $writeFailed->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(data: $report->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * GET /api/content-reports — the reports, for reviewers.
	 *
	 * Optional query parameters: `status`, `organisation`.
	 *
	 * @return JSONResponse The list envelope, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		if ($this->isReviewer() === false) {
			return $this->forbidden();
		}

		$rows = $this->reports->findAll(
			status: $this->optionalParam(key: 'status'),
			organisationId: $this->optionalParam(key: 'organisation')
		);

		$results = [];
		foreach ($rows as $row) {
			$results[] = $row->jsonSerialize();
		}

		return new JSONResponse(data: ['count' => count($results), 'results' => $results]);
	}//end index()

	/**
	 * GET /api/content-reports/{id} — one report, for reviewers.
	 *
	 * @param string $id The report id or uuid.
	 *
	 * @return JSONResponse The report, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt The guard IS the reviewer-group check on the line below, which
	 *   is stricter than a per-object owner check would be: a report has no owner who may
	 *   read it, only a reviewer group, and the reporter themselves is deliberately not
	 *   given a way back in. An id lookup that clears that gate is reviewing, which is the
	 *   whole purpose of the endpoint.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function show(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->unauthorized();
		}

		if ($this->isReviewer() === false) {
			return $this->forbidden();
		}

		$report = $this->resolve(identifier: $id);
		if ($report === null) {
			return $this->notFound(identifier: $id);
		}

		return new JSONResponse(data: $report->jsonSerialize());
	}//end show()

	/**
	 * GET /api/content-reports/{id}/copy — the frozen content itself.
	 *
	 * The endpoint the requirement is about. It answers the copy even when the
	 * content it was taken from is gone, which is the point: removing the
	 * content must not destroy the evidence.
	 *
	 * @param string $id The report id or uuid.
	 *
	 * @return JSONResponse The copy, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Guarded by the reviewer-group check below, and by
	 *   ContentReportService::readCopy() a second time, which returns null rather than the
	 *   copy for anybody outside the group the report itself pinned when it was filed.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function copy(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$report = $this->resolve(identifier: $id);
		if ($report === null) {
			return $this->notFound(identifier: $id);
		}

		// The access check lives on the SERVICE and is asked here, rather than
		// being repeated in the controller: the report pins the group it was
		// filed under, so a later configuration change cannot widen access to a
		// copy already taken, and only one place knows that rule.
		$copy = $this->service->readCopy(report: $report, user: $user);
		if ($copy === null) {
			return $this->forbidden();
		}

		return new JSONResponse(
			data: [
				'report' => $report->getUuid(),
				'objectUuid' => $report->getObjectUuid(),
				'removed' => $report->isRemoved(),
				'removedAt' => $report->getRemovedAt()?->format('c'),
				'removalAudit' => $report->getRemovalAudit(),
				'copyHash' => $report->getCopyHash(),
				'copyIntact' => $report->copyIsIntact(),
				'expires' => $report->getExpires()?->format('c'),
				'copy' => $copy,
			]
		);
	}//end copy()

	/**
	 * PUT /api/content-reports/{id} — record a review outcome.
	 *
	 * @param string $id The report id or uuid.
	 *
	 * @return JSONResponse The report, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @SuppressWarnings(PHPMD.StaticAccess) ContentReport::isValidStatus is the entity's own vocabulary
	 *   check, the same shape ProcessingPurposeController uses.
	 * @no-admin-idor-exempt Guarded by the reviewer-group check below. Reviewing is the
	 *   only write this endpoint allows, and it is the reviewer group's job by definition.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function update(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->unauthorized();
		}

		if ($this->isReviewer() === false) {
			return $this->forbidden();
		}

		$report = $this->resolve(identifier: $id);
		if ($report === null) {
			return $this->notFound(identifier: $id);
		}

		$status = $this->optionalParam(key: 'status');
		if ($status === null || ContentReport::isValidStatus(status: $status) === false) {
			return new JSONResponse(
				data: [
					'error' => 'status must be one of: ' . implode(', ', ContentReport::STATUS_VOCABULARY),
				],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$report->setStatus($status);

		try {
			$persisted = $this->reports->update($report);
		} catch (Throwable $writeFailed) {
			return new JSONResponse(
				data: ['error' => $writeFailed->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(data: $persisted->jsonSerialize());
	}//end update()

	/**
	 * Resolve a path identifier that may be an id or a uuid.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return ContentReport|null The report, or null.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function resolve(string $identifier): ?ContentReport {
		if (ctype_digit($identifier) === true) {
			try {
				return $this->reports->find((int)$identifier);
			} catch (Throwable $notFound) {
				return null;
			}
		}

		return $this->reports->findByUuid(uuid: $identifier);
	}//end resolve()

	/**
	 * Whether the caller is in the configured reviewer group.
	 *
	 * @return bool True when the caller may review reported content.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function isReviewer(): bool {
		$probe = new ContentReport();
		$probe->setReviewerGroup($this->service->reviewerGroup());

		return $this->service->mayReadCopy(report: $probe, user: $this->userSession->getUser());
	}//end isReviewer()

	/**
	 * Read an optional string parameter.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return string|null The trimmed value, or null when absent or empty.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function optionalParam(string $key): ?string {
		$value = $this->request->getParam(key: $key);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end optionalParam()

	/**
	 * The unauthenticated response.
	 *
	 * @return JSONResponse HTTP 401.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Authentication required'],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
	}//end unauthorized()

	/**
	 * The non-reviewer response.
	 *
	 * @return JSONResponse HTTP 403.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'Reading reported content requires the reviewer group',
				'reviewerGroup' => $this->service->reviewerGroup(),
			],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end forbidden()

	/**
	 * The missing-report response.
	 *
	 * @param string $identifier The identifier that matched nothing.
	 *
	 * @return JSONResponse HTTP 404.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function notFound(string $identifier): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Not Found', 'identifier' => $identifier],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFound()
}//end class
