<?php

/**
 * AuditReportController — the downloadable audit report of a register, an actor and a period.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\Audit\AuditReport;
use OCA\OpenRegister\Service\Audit\AuditReportFile;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET /api/reports/audit: administrators only.
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
 */
class AuditReportController extends Controller {

	/**
	 * The format a request without one gets.
	 *
	 * @var string
	 */
	private const DEFAULT_FORMAT = 'xlsx';

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request      The request.
	 * @param IUserSession            $userSession  The caller.
	 * @param IGroupManager           $groupManager Tells an administrator from anyone else.
	 * @param RegisterMapperInterface $registers    Resolves a register slug or id.
	 * @param AuditReport             $report       Builds the report.
	 * @param AuditReportFile         $file         Writes it.
	 * @param LoggerInterface         $logger       Logs a failed report.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly RegisterMapperInterface $registers,
		private readonly AuditReport $report,
		private readonly AuditReportFile $file,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The audit report as a file: `register` (slug or id), `userId`, `from`, `to`, `format` (xlsx, ods or csv).
	 *
	 * The in-body check admits only an administrator; anyone else gets 403 before anything is read.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or 400/401/403/404/500.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function audit(): DataDownloadResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(data: ['error' => 'Only administrators may generate audit reports.'], statusCode: 403);
		}

		$format = strtolower(trim((string)$this->request->getParam('format', self::DEFAULT_FORMAT)));
		if (isset(AuditReportFile::FORMATS[$format]) === false) {
			return new JSONResponse(
				data: ['error' => sprintf('Unsupported format "%s"; use %s.', $format, implode(', ', array_keys(AuditReportFile::FORMATS)))],
				statusCode: 400
			);
		}

		$registerId = null;
		$register = trim((string)$this->request->getParam('register', ''));
		if ($register !== '') {
			try {
				$registerId = (int)$this->registers->find(id: $register, _rbac: false, _multitenancy: false)->getId();
			} catch (Throwable) {
				return new JSONResponse(data: ['error' => sprintf('Register "%s" not found.', $register)], statusCode: 404);
			}
		}

		try {
			$built = $this->report->build(
				registerId: $registerId,
				userId: $this->param(name: 'userId'),
				from: $this->param(name: 'from'),
				to: $this->param(name: 'to')
			);
			$written = $this->file->write(report: $built, format: $format);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		} catch (Throwable $e) {
			$this->logger->error('[AuditReportController] audit report failed', ['exception' => $e]);

			return new JSONResponse(data: ['error' => 'The audit report could not be generated.'], statusCode: 500);
		}

		return new DataDownloadResponse(data: $written['bytes'], filename: $written['filename'], contentType: $written['mime']);
	}//end audit()

	/**
	 * A text parameter, or null when absent or blank.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null The trimmed value.
	 */
	private function param(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end param()
}//end class
