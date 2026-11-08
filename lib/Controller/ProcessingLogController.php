<?php

/**
 * AVG / GDPR processing-log inquiry & export controller (verwerkingenlogging).
 *
 * Read-only, access-guarded surface over the append-only
 * `oc_openregister_processing_log`. The log itself is sensitive (it
 * records who looked at whose personal data), so every endpoint is:
 *
 *   - Admin-only by default with delegation to a configurable
 *     privacy-officer (FG) NC group (`processing_log_fg_group`,
 *     default `privacy-officer`). NC SecurityMiddleware already makes
 *     these admin-only at the framework level (no `#[NoAdminRequired]`);
 *     the in-body check additionally admits the FG group and fails
 *     CLOSED for everyone else.
 *   - Organisation-scoped: a non-admin caller only ever queries the
 *     organisations they belong to — no IDOR across tenants.
 *   - Confidential-gated: `confidential` entries are excluded for
 *     non-FG callers at the mapper-query level.
 *
 * The log is append-only by surface: this controller exposes ONLY GET
 * endpoints — there is deliberately no update or delete route.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\ProcessingLogControllerServices;
use OCA\OpenRegister\Controller\Support\GdprRequestParams;
use OCA\OpenRegister\Controller\Support\ProcessingLogAccess;
use OCA\OpenRegister\Controller\Support\ProcessingLogPolicy;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;

/**
 * REST endpoints for querying and exporting the AVG processing log.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class ProcessingLogController extends Controller {

	/**
	 * The FG group setting and the maximum extract range.
	 *
	 * @var ProcessingLogPolicy
	 */
	private readonly ProcessingLogPolicy $policy;

	/**
	 * Who may query the log, and over which organisation.
	 *
	 * @var ProcessingLogAccess
	 */
	private readonly ProcessingLogAccess $access;

	/**
	 * The optional query parameters.
	 *
	 * @var GdprRequestParams
	 */
	private readonly GdprRequestParams $params;

	/**
	 * The processing log store and the app config the policy reads.
	 *
	 * @var ProcessingLogControllerServices
	 */
	private readonly ProcessingLogControllerServices $services;

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request             Active request.
	 * @param ProcessingLogControllerServices $services Processing log store and the app config the policy reads.
	 * @param ControllerSession            $session             Session, group manager and logger.
	 * @param OrganisationServiceInterface $organisationService Org-scoping helper.
	 */
	public function __construct(
		IRequest $request,
		ProcessingLogControllerServices $services,
		private readonly ControllerSession $session,
		OrganisationServiceInterface $organisationService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

		$this->services = $services;
		$this->policy = new ProcessingLogPolicy(appConfig: $services->appConfig);
		$this->access = new ProcessingLogAccess(session: $this->session, policy: $this->policy, organisationService: $organisationService);
		$this->params = new GdprRequestParams(request: $request);
	}//end __construct()

	/**
	 * GET /api/avg/verwerkingen — filtered processing-log inquiry.
	 *
	 * Optional query params: `register`, `schema`, `activity`, `actor`,
	 * `action`, `subjectIdType`, `subjectIdValue`, `from`, `to`,
	 * `limit`, `offset`.
	 *
	 * Admin-only by NC default; delegated to the FG group. Fails closed.
	 *
	 * @return JSONResponse Wrapped list envelope, 401, or 403.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function index(): JSONResponse {
		$access = $this->access->resolve();
		if ($access instanceof JSONResponse) {
			return $access;
		}

		[$isFg, $organisationId] = $access;

		$entries = $this->filtered(organisationId: $organisationId, includeConfidential: $isFg);

		return new JSONResponse(data: ['count' => count($entries), 'results' => $entries]);
	}//end index()

	/**
	 * GET /api/avg/verwerkingen/betrokkene — per-subject inzage extract.
	 *
	 * Required query params: `subjectIdType`, `subjectIdValue`.
	 * Optional: `from`, `to`. Range bounded by
	 * `processing_export_max_range_days` (default 366) → HTTP 422.
	 *
	 * @return JSONResponse Extract envelope, 400/422, 401, or 403.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function involvedParty(): JSONResponse {
		$access = $this->access->resolve();
		if ($access instanceof JSONResponse) {
			return $access;
		}

		[$isFg, $organisationId] = $access;

		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(
				data: ['error' => 'subjectIdType and subjectIdValue are required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->extract(subject: $subject, scope: ['organisationId' => $organisationId, 'includeConfidential' => $isFg]);
	}//end involvedParty()

	/**
	 * The subject identifiers of an extract, or null when either is missing.
	 *
	 * @return array{idType: string, idValue: string}|null The identifiers.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function subject(): ?array {
		$idType = $this->params->nonEmpty(key: 'subjectIdType');
		$idValue = $this->params->nonEmpty(key: 'subjectIdValue');
		if ($idType === null || $idValue === null) {
			return null;
		}

		return ['idType' => $idType, 'idValue' => $idValue];
	}//end subject()

	/**
	 * The filtered inquiry, paged from the request.
	 *
	 * @param string|null $organisationId      The organisation scope, null for none.
	 * @param bool        $includeConfidential Whether confidential entries are included.
	 *
	 * @return array<int, mixed> The entries.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function filtered(?string $organisationId, bool $includeConfidential): array {
		return $this->services->logMapper->findFiltered(
			filters: $this->filters(),
			from: $this->dateParam(key: 'from'),
			to: $this->dateParam(key: 'to'),
			organisationId: $organisationId,
			includeConfidential: $includeConfidential,
			limit: $this->params->boundedInt(key: 'limit', default: 100, max: 1000),
			offset: $this->params->boundedInt(key: 'offset', default: 0, max: 1000000)
		);
	}//end filtered()

	/**
	 * The column filters of the inquiry, from the request.
	 *
	 * @return array<string, string|null> Column to value, null when not filtered.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function filters(): array {
		return [
			'register_id' => $this->params->nonEmpty(key: 'register'),
			'schema_id' => $this->params->nonEmpty(key: 'schema'),
			'activity_id' => $this->params->nonEmpty(key: 'activity'),
			'actor' => $this->params->nonEmpty(key: 'actor'),
			'action' => $this->params->nonEmpty(key: 'action'),
			'subject_id_type' => $this->params->nonEmpty(key: 'subjectIdType'),
			'subject_id_value' => $this->params->nonEmpty(key: 'subjectIdValue'),
		];
	}//end filters()

	/**
	 * The per-subject extract over the requested period, or the range refusal.
	 *
	 * @param array{idType: string, idValue: string}                       $subject The subject identifiers.
	 * @param array{organisationId: ?string, includeConfidential: bool} $scope   The organisation scope, and whether
	 *                                                                           confidential entries are included.
	 *
	 * @return JSONResponse The extract envelope, or HTTP 422.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function extract(array $subject, array $scope): JSONResponse {
		$from = $this->dateParam(key: 'from');
		$to = $this->dateParam(key: 'to');

		$rangeError = $this->policy->rangeRefusal(from: $from, to: $to);
		if ($rangeError instanceof JSONResponse) {
			return $rangeError;
		}

		$entries = $this->services->logMapper->findBySubject(
			idType: $subject['idType'], idValue: $subject['idValue'], from: $from, to: $to,
			organisationId: $scope['organisationId'], includeConfidential: $scope['includeConfidential']
		);

		return new JSONResponse(data: $this->extractBody(subject: $subject, read: ['from' => $from, 'to' => $to, 'entries' => $entries]));
	}//end extract()

	/**
	 * The extract envelope.
	 *
	 * @param array{idType: string, idValue: string}                             $subject The subject identifiers.
	 * @param array{from: ?DateTime, to: ?DateTime, entries: array<int, mixed>} $read    The lower and upper bound, and the reads.
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function extractBody(array $subject, array $read): array {
		return [
			'subject' => $subject,
			'period' => ['from' => $read['from']?->format('c'), 'to' => $read['to']?->format('c')],
			'count' => count($read['entries']),
			'reads' => $read['entries'],
		];
	}//end extractBody()

	/**
	 * Read a request parameter as an ISO-8601 DateTime, or null.
	 *
	 * @param string $key Parameter name.
	 *
	 * @return DateTime|null
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function dateParam(string $key): ?DateTime {
		return $this->params->date(value: $this->params->nonEmpty(key: $key));
	}//end dateParam()
}//end class
