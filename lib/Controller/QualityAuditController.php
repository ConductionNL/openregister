<?php

/**
 * QualityAuditController — declare, run and read data quality audits.
 *
 * Administrators only. An audit evaluates every member of the population it
 * names, regardless of who could read those records, so declaring and
 * running one is an administrator's act; each method also refuses a
 * non-administrator itself rather than relying on the attribute alone.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\AdministratorOnlyGate;
use OCA\OpenRegister\Db\QualityAudit;
use OCA\OpenRegister\Db\QualityAuditRun;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Quality\QualityAuditRefusedException;
use OCA\OpenRegister\Service\Quality\QualityAuditService;
use OCA\OpenRegister\Service\Quality\QualityAuditStores;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The quality audit endpoints (REQ-RCN-003, REQ-RCN-004).
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class QualityAuditController extends Controller {

	/**
	 * The refusal a non-administrator reads.
	 *
	 * @var string
	 */
	private const ADMIN_ONLY = 'Only administrators may declare, run or read quality audits.';

	/**
	 * The largest page a list answers.
	 *
	 * @var int
	 */
	private const MAX_PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request     The request.
	 * @param AdministratorOnlyGate $gate        The administrator check.
	 * @param QualityAuditService   $service     The audits.
	 * @param QualityAuditStores    $stores      The audit and run lookups.
	 * @param IUserSession          $userSession The acting user.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministratorOnlyGate $gate,
		private readonly QualityAuditService $service,
		private readonly QualityAuditStores $stores,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * One page of audits.
	 *
	 * @return JSONResponse The audits.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function index(): JSONResponse {
		$refusal = $this->gate->refusal(forbidden: self::ADMIN_ONLY);
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(data: ['results' => $this->stores->audits->findPage(query: $this->page())]);
	}//end index()

	/**
	 * Declare an audit. A faulty declaration is refused naming each fault.
	 *
	 * @return JSONResponse 201 with the audit, or 400 with the faults.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function create(): JSONResponse {
		$refusal = $this->gate->refusal(forbidden: self::ADMIN_ONLY);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->saved(existing: null, status: 201);
	}//end create()

	/**
	 * One audit with its runs in sequence: the trend.
	 *
	 * @param int $id The audit id.
	 *
	 * @return JSONResponse The audit and its runs, or 404.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function show(int $id): JSONResponse {
		$audit = $this->audit(id: $id);
		if ($audit instanceof JSONResponse) {
			return $audit;
		}

		return new JSONResponse(
			data: $audit->jsonSerialize() + ['runs' => $this->service->trend(audit: $audit, query: $this->page())]
		);
	}//end show()

	/**
	 * Change an audit. Its past runs keep the tolerance they were judged against.
	 *
	 * @param int $id The audit id.
	 *
	 * @return JSONResponse The audit, 400 with the faults, or 404.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function update(int $id): JSONResponse {
		$audit = $this->audit(id: $id);
		if ($audit instanceof JSONResponse) {
			return $audit;
		}

		return $this->saved(existing: $audit, status: 200);
	}//end update()

	/**
	 * Remove an audit with its runs.
	 *
	 * @param int $id The audit id.
	 *
	 * @return JSONResponse 204, or 404.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function destroy(int $id): JSONResponse {
		$audit = $this->audit(id: $id);
		if ($audit instanceof JSONResponse) {
			return $audit;
		}

		$this->service->delete(audit: $audit);

		return new JSONResponse(data: [], statusCode: 204);
	}//end destroy()

	/**
	 * Run an audit now, on demand.
	 *
	 * @param int $id The audit id.
	 *
	 * @return JSONResponse 201 with the kept run, or 404.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function run(int $id): JSONResponse {
		$audit = $this->audit(id: $id);
		if ($audit instanceof JSONResponse) {
			return $audit;
		}

		$run = $this->service->run(
			audit: $audit,
			startedBy: (string)$this->userSession->getUser()?->getUID(),
			now: new DateTimeImmutable()
		);

		return new JSONResponse(data: $run->jsonSerialize(), statusCode: 201);
	}//end run()

	/**
	 * One page of an audit's runs, oldest first.
	 *
	 * @param int $id The audit id.
	 *
	 * @return JSONResponse The runs, or 404.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function runs(int $id): JSONResponse {
		$audit = $this->audit(id: $id);
		if ($audit instanceof JSONResponse) {
			return $audit;
		}

		return new JSONResponse(data: ['results' => $this->service->trend(audit: $audit, query: $this->page())]);
	}//end runs()

	/**
	 * One page of a run's failing records, each with the rules it failed.
	 *
	 * @param int $id The run id.
	 *
	 * @return JSONResponse The failures, or 404.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function failures(int $id): JSONResponse {
		$run = $this->auditRun(id: $id);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		return new JSONResponse(
			data: [
				'results' => $this->stores->runs->findFailures(runId: (int)$run->getId(), query: $this->page()),
				'total' => $run->getFailed(),
			]
		);
	}//end failures()

	/**
	 * A run's failing records as CSV.
	 *
	 * @param int $id The run id.
	 *
	 * @return DataDownloadResponse|JSONResponse The CSV, or the refusal.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function failuresCsv(int $id): DataDownloadResponse|JSONResponse {
		$run = $this->auditRun(id: $id);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		return new DataDownloadResponse(
			$this->service->failuresCsv(run: $run),
			'quality-audit-run-'.(string)$run->getUuid().'-failures.csv',
			'text/csv'
		);
	}//end failuresCsv()

	/**
	 * Save the declaration in the request body.
	 *
	 * @param QualityAudit|null $existing The audit being changed, or null.
	 * @param int               $status   The status of a successful save.
	 *
	 * @return JSONResponse The audit, or 400 with the faults.
	 */
	private function saved(?QualityAudit $existing, int $status): JSONResponse {
		$input = [
			'name' => $this->request->getParam('name'),
			'population' => $this->request->getParam('population'),
			'rules' => $this->request->getParam('rules'),
			'tolerance' => $this->request->getParam('tolerance'),
			'schedule' => $this->request->getParam('schedule'),
		];

		try {
			$audit = $this->service->save(
				input: $input,
				existing: $existing,
				actor: $this->userSession->getUser()?->getUID()
			);
		} catch (QualityAuditRefusedException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage(), 'faults' => $exception->faults()], statusCode: 400);
		}

		return new JSONResponse(data: $audit->jsonSerialize(), statusCode: $status);
	}//end saved()

	/**
	 * The audit an id names, for an administrator.
	 *
	 * @param int $id The audit id.
	 *
	 * @return QualityAudit|JSONResponse The audit, or the refusal.
	 */
	private function audit(int $id): QualityAudit|JSONResponse {
		$refusal = $this->gate->refusal(forbidden: self::ADMIN_ONLY);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->stores->audits->find(id: $id);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['error' => 'Quality audit not found'], statusCode: 404);
		}
	}//end audit()

	/**
	 * The run an id names, for an administrator.
	 *
	 * @param int $id The run id.
	 *
	 * @return QualityAuditRun|JSONResponse The run, or the refusal.
	 */
	private function auditRun(int $id): QualityAuditRun|JSONResponse {
		$refusal = $this->gate->refusal(forbidden: self::ADMIN_ONLY);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->stores->runs->find(id: $id);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['error' => 'Quality audit run not found'], statusCode: 404);
		}
	}//end auditRun()

	/**
	 * The page the request asks for, capped.
	 *
	 * @return ListQuery The page.
	 */
	private function page(): ListQuery {
		return new ListQuery(
			limit: max(1, min((int)$this->request->getParam('limit', 100), self::MAX_PAGE)),
			offset: max(0, (int)$this->request->getParam('offset', 0))
		);
	}//end page()
}//end class
