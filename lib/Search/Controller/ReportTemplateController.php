<?php

/**
 * Report template controller: generates a report from a template.
 *
 *   POST /api/reports/generate
 *   {"templateId": "<uuid>", "dateRange": {"from": "2026-01-01", "to": "2026-12-31"}, "format": "pdf"}
 *
 * `year` may stand in for `dateRange` (the calendar year). The response is
 * the rendered file as a download. The template is loaded with the caller's
 * rights and every section counts with them; a section the caller may not
 * read refuses the whole report (403) rather than leaving it out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Search\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Search\Controller\Support\ReportGenerationFailures;
use OCA\OpenRegister\Search\Service\Reporting\ReportPeriod;
use OCA\OpenRegister\Search\Service\Reporting\ReportTemplateGenerator;
use OCA\OpenRegister\Service\Resilience\Sheddable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST endpoint that renders a report template over a period.
 */
class ReportTemplateController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request      The current request.
	 * @param ReportTemplateGenerator    $generator    Generates the report.
	 * @param MagicObjectFinderInterface $objectMapper Loads the template with the caller's rights.
	 * @param ReportGenerationFailures   $failures     Answers a generation that failed.
	 */
	public function __construct(
		IRequest $request,
		private readonly ReportTemplateGenerator $generator,
		private readonly MagicObjectFinderInterface $objectMapper,
		private readonly ReportGenerationFailures $failures,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/reports/generate: render a template over a period.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	#[Sheddable]
	public function generate() {
		$templateId = trim((string)$this->request->getParam(key: 'templateId', default: ''));
		if ($templateId === '') {
			return new JSONResponse(data: ['error' => '`templateId` is required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$period = $this->period();
			$template = $this->templateData(identifier: $templateId);
			$rendered = $this->generator->generate(
				template: $template,
				period: $period,
				format: strtolower((string)$this->request->getParam(key: 'format', default: 'pdf'))
			);
		} catch (\Throwable $e) {
			return $this->failures->response(templateId: $templateId, error: $e);
		}

		return new DataDownloadResponse(data: $rendered['bytes'], filename: $rendered['filename'], contentType: $rendered['mime']);
	}//end generate()

	/**
	 * The requested period: `dateRange.from`/`to`, else the calendar `year`.
	 *
	 * @return ReportPeriod
	 *
	 * @throws InvalidArgumentException When neither is given or a bound is invalid.
	 */
	private function period(): ReportPeriod {
		$range = $this->request->getParam(key: 'dateRange');
		if (is_array($range) === true) {
			return new ReportPeriod(from: (string)($range['from'] ?? ''), to: (string)($range['to'] ?? ''));
		}

		$year = (string)$this->request->getParam(key: 'year', default: '');
		if (preg_match('/^\d{4}$/', $year) === 1) {
			return new ReportPeriod(from: $year . '-01-01', to: $year . '-12-31');
		}

		throw new InvalidArgumentException('`dateRange` ({from, to}) or `year` is required');
	}//end period()

	/**
	 * Load the template's data with the caller's rights.
	 *
	 * @param string $identifier Numeric id, uuid or slug.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DoesNotExistException When the caller cannot see it or it does not exist.
	 */
	private function templateData(string $identifier): array {
		$arg = $identifier;
		if (ctype_digit($identifier) === true) {
			$arg = (int)$identifier;
		}

		$data = $this->objectMapper->find($arg)->getObject();
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end templateData()

}//end class
