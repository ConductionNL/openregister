<?php

/**
 * ObjectExportController
 *
 * Exports the objects of one register and schema as a file: the format
 * dispatch, the public path for a caller without a session, the export-right
 * refusal, and the audit and run recording of every export.
 *
 * Export is Configuration's work, so the endpoint lives with it rather than
 * in the object API. The URL and verb are unchanged:
 * `GET /api/objects/{register}/{schema}/export`.
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
 * @spec openspec/changes/functional-areas-depend-in-one-direction/contracts/configuration.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Controller\Support\ObjectExport;
use OCA\OpenRegister\Controller\Support\ServedExport;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectExportRequest;
use OCA\OpenRegister\Service\Export\ExportAuditRecorderInterface;
use OCA\OpenRegister\Service\Export\ExportPage;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCA\OpenRegister\Service\Export\ExportRequest;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\Export\ExportRunContent;
use OCA\OpenRegister\Service\Export\ExportRunFile;
use OCA\OpenRegister\Service\Export\ExportRunOrigin;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Service\Export\PublicExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Resilience\Sheddable;

/**
 * Serves the export of a register and schema's objects.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class ObjectExportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName       The app name.
	 * @param IRequest                $request       The request: filters and format.
	 * @param IUserSession            $userSession   Resolves the caller; none means the public path.
	 * @param ObjectExporterInterface $exportService Writes the rows in each format.
	 * @param ContainerInterface      $container     Resolves the right service, the recorders and the public export.
	 * @param LoggerInterface         $logger        Logs an unresolvable collaborator.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectExporterInterface $exportService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Export objects to specified format
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return DataDownloadResponse|JSONResponse The exported file as a download response, or a
	 *                                           400 JSON error when a `format=pdf` request exceeds
	 *                                           {@see \OCA\OpenRegister\Service\ExportService::MAX_PDF_EXPORT_ROWS}.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @psalm-return DataDownloadResponse<200,
	 *     'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'|'text/csv'|'application/pdf',
	 *     array<never, never>>|JSONResponse
	 *
	 * @psalm-suppress NoValue
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 * @spec openspec/specs/export-pdf-format/spec.md
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	#[UserRateLimit(limit: 600, period: 60)]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[Sheddable]
	public function export(string $register, string $schema, ObjectOperationsInterface $objectService): DataDownloadResponse|JSONResponse {
		// Set the register and schema context.
		$objectService->setRegister(register: $register);
		$objectService->setSchema(schema: $schema);

		// Get filters and type from request.
		$filters = $this->exportFilters();
		$type = $this->exportFormat();

		// Get register and schema entities.
		//
		// Reuse what setRegister()/setSchema() already resolved instead of
		// re-resolving. The re-resolution was GLOBAL — `$this->services->store->schemaMapper->find()`
		// matches `LOWER(slug)` across the whole instance — so on an instance where
		// two apps share a schema slug the export was named after ANOTHER app's
		// schema while its rows came from this register's. A filename is exactly
		// where that goes unnoticed: the file downloads, opens, and lies about its
		// own provenance.
		$registerEntity = $objectService->getCurrentRegisterEntity();
		$schemaEntity = $objectService->getCurrentSchemaEntity();
		if ($registerEntity === null || $schemaEntity === null) {
			// Unreachable in practice — setRegister()/setSchema() above either
			// resolve or throw. Refusing here rather than re-resolving keeps the
			// register a boundary: a fallback lookup would be a second, unscoped
			// chance to find *a* schema with this slug, which is the whole defect.
			return new JSONResponse(data: ['error' => 'Register or schema not found'], statusCode: 404);
		}

		// WITHOUT A SESSION the public principal exports, through its own path:
		// the schema's grant must name `public`, only open formats are written,
		// and one bounded page leaves per response (design D-2 to D-4).
		if ($this->exportsAsThePublic() === true) {
			return $this->publicExport(
				export: new ObjectExport(
					register: $registerEntity,
					schema: $schemaEntity,
					filters: $filters,
					filenameBase: self::exportFilenameBase(register: $registerEntity, schema: $schemaEntity)
				),
				format: (string)$type
			);
		}

		// THE EXPORT VERB, before a single row is read. Hiding the menu item is
		// not a control: this endpoint is what an integration calls, so this is
		// where the right has to hold (design D-1). The refusal names the verb
		// rather than the register, because "forbidden" leaves an operator
		// guessing which of the two grants they are missing.
		$refusal = $this->exportRefusalFor(schema: $schemaEntity, register: $registerEntity);
		if ($refusal !== null) {
			return $refusal;
		}

		$export = new ObjectExport(
			register: $registerEntity,
			schema: $schemaEntity,
			filters: $filters,
			filenameBase: self::exportFilenameBase(register: $registerEntity, schema: $schemaEntity)
		);

		// Call ExportService directly (bypassing ObjectService which has circular dependency issues).
		return match ($type) {
			'csv' => $this->csvExport(export: $export),
			'tsv' => $this->tsvExport(export: $export),
			'json' => $this->jsonExport(export: $export),
			'xml' => $this->xmlExport(export: $export),
			'pdf' => $this->pdfExport(export: $export),
			'ods' => $this->odsExport(export: $export),
			default => $this->excelExport(export: $export),
		};
	}//end export()

	/**
	 * Whether this export runs as the public principal.
	 *
	 * Not an access check: it picks which of the two export paths applies, and
	 * each path checks its own right (the public grant for a visitor, the
	 * caller's export right for a signed-in user).
	 *
	 * @return bool True when there is no signed-in user.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	private function exportsAsThePublic(): bool {
		return ($this->userSession->getUser() === null);
	}//end exportsAsThePublic()

	/**
	 * Export for a caller without a session: one page in an open format, or the refusal.
	 *
	 * @param ObjectExport $export What to export.
	 * @param string       $format The requested format.
	 *
	 * @return DataDownloadResponse|JSONResponse The page with its paging headers, or the refusal.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	private function publicExport(ObjectExport $export, string $format): DataDownloadResponse|JSONResponse {
		$service = null;
		try {
			$service = $this->container->get(PublicExportService::class);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ObjectExportController] Public export service unresolvable, refusing the export',
				context: ['error' => $e->getMessage()]
			);
		}

		// No service, no export: the grant check lives there.
		if (($service instanceof PublicExportService) === false) {
			return new JSONResponse(
				data: ['error' => 'EXPORT_REFUSED', 'verb' => ExportRightService::ACTION, 'rule' => 'right-service-unavailable'],
				statusCode: 503
			);
		}

		$request = (new ExportRequest(register: $export->register, schema: $export->schema, filters: $export->filters))
			->withPage(page: ExportPage::publicFrom(requested: ($export->filters['_page'] ?? null)));
		$result  = $service->export(request: $request, format: $format);
		if ($result->content === null) {
			return new JSONResponse(data: $result->refusalBody(), statusCode: $result->statusCode());
		}

		$response = $export->download(format: $format, content: $result->content);
		foreach ($result->headers(requestUri: $this->request->getRequestUri()) as $name => $value) {
			$response->addHeader($name, $value);
		}

		return $response;
	}//end publicExport()

	/**
	 * The request parameters an export filters on.
	 *
	 * @return array The filters.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function exportFilters(): array {
		$filters = $this->request->getParams();
		unset($filters['_route']);

		return $filters;
	}//end exportFilters()

	/**
	 * The format an export asks for: `format`, else `type`, else excel.
	 *
	 * @return mixed The format.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function exportFormat(): mixed {
		return $this->request->getParam(key: 'format') ?? $this->request->getParam(key: 'type', default: 'excel');
	}//end exportFormat()

	/**
	 * The file name of an export, without extension.
	 *
	 * @param Register $register The register being exported.
	 * @param Schema   $schema   The schema being exported.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private static function exportFilenameBase(Register $register, Schema $schema): string {
		return sprintf(
			'%s_%s_%s',
			$register->getSlug() ?? 'register',
			$schema->getSlug() ?? 'schema',
			(new DateTime())->format('Y-m-d_His')
		);
	}//end exportFilenameBase()

	/**
	 * The export-service request for an export run as the signed-in caller.
	 *
	 * @param ObjectExport $export What to export.
	 *
	 * @return ObjectExportRequest
	 */
	private function callerExportRequest(ObjectExport $export): ObjectExportRequest {
		return new ObjectExportRequest(
			register: $export->register,
			schema: $export->schema,
			filters: $export->filters,
			currentUser: $this->userSession->getUser(),
		);
	}//end callerExportRequest()

	/**
	 * Export as CSV.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse The file.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function csvExport(ObjectExport $export): DataDownloadResponse {
		$content = $this->exportService->exportToCsv(
			request: $this->callerExportRequest(export: $export),
		);

		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'csv', rowCount: $this->countCsvExportRows(csv: $content)));

		return $export->download(format: 'csv', content: $content);
	}//end csvExport()

	/**
	 * Export as TSV.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse The file.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function tsvExport(ObjectExport $export): DataDownloadResponse {
		$content = $this->exportService->exportToTsv(
			request: $this->callerExportRequest(export: $export),
		);

		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'tsv', rowCount: $this->countCsvExportRows(csv: $content)));

		return $export->download(format: 'tsv', content: $content);
	}//end tsvExport()

	/**
	 * Export as JSON.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse The file.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function jsonExport(ObjectExport $export): DataDownloadResponse {
		$content = $this->exportService->exportToJson(
			request: $this->callerExportRequest(export: $export),
		);

		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'json', rowCount: $this->countJsonExportRows(json: $content)));

		return $export->download(format: 'json', content: $content);
	}//end jsonExport()

	/**
	 * Export as XML.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse The file.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
	 */
	private function xmlExport(ObjectExport $export): DataDownloadResponse {
		$content = $this->exportService->exportToXml(
			request: $this->callerExportRequest(export: $export),
		);

		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'xml', rowCount: self::countXmlExportRows(xml: $content)));

		return $export->download(format: 'xml', content: $content);
	}//end xmlExport()

	/**
	 * Export as PDF; an export over the row limit or without an office server is refused.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or the refusal.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function pdfExport(ObjectExport $export): DataDownloadResponse|JSONResponse {
		try {
			$content = $this->exportService->exportToPdf(
				request: $this->callerExportRequest(export: $export),
			);
		} catch (ExportTooLargeException $e) {
			return self::exportTooLargeResponse(exception: $e);
		} catch (OfficeServerRequiredException $e) {
			return new JSONResponse(data: $e->toResponseData(), statusCode: OfficeServerRequiredException::HTTP_STATUS);
		}

		$this->recordPdfExport(export: $export);

		return $export->download(format: 'pdf', content: $content);
	}//end pdfExport()

	/**
	 * Record a finished PDF export, with the row count the export service answers.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function recordPdfExport(ObjectExport $export): void {
		$rowCount = $this->exportService->countExportRows(
			request: $this->callerExportRequest(export: $export),
		);
		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'pdf', rowCount: $rowCount));
	}//end recordPdfExport()

	/**
	 * The answer to a PDF export over the row limit.
	 *
	 * @param ExportTooLargeException $exception The refusal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private static function exportTooLargeResponse(ExportTooLargeException $exception): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'export_too_large',
				'message' => $exception->getMessage(),
				'rowCount' => $exception->getRowCount(),
				'maxRows' => $exception->getMaxRows(),
			],
			statusCode: ExportTooLargeException::HTTP_STATUS
		);
	}//end exportTooLargeResponse()

	/**
	 * Export as Excel.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse The file.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function excelExport(ObjectExport $export): DataDownloadResponse {
		$spreadsheet = $this->exportService->exportToExcel(
			request: $this->callerExportRequest(export: $export),
		);

		// Create Excel writer and get content.
		$writer = new Xlsx($spreadsheet);
		ob_start();
		$writer->save('php://output');
		$content = ob_get_clean();

		$rowCount = $this->countSpreadsheetExportRows(spreadsheet: $spreadsheet);
		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'excel', rowCount: $rowCount));

		return $export->download(format: 'excel', content: $content);
	}//end excelExport()

	/**
	 * Export as ODS (OpenDocument spreadsheet).
	 *
	 * The same spreadsheet the Excel export builds, so the headers, the
	 * relation names and the RBAC filtering are identical; only the writer
	 * differs.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse The file.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md
	 */
	private function odsExport(ObjectExport $export): DataDownloadResponse {
		$spreadsheet = $this->exportService->exportToExcel(
			request: $this->callerExportRequest(export: $export),
		);

		$writer = new Ods($spreadsheet);
		ob_start();
		$writer->save('php://output');
		$content = ob_get_clean();

		$rowCount = $this->countSpreadsheetExportRows(spreadsheet: $spreadsheet);
		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'ods', rowCount: $rowCount));

		return $export->download(format: 'ods', content: $content);
	}//end odsExport()

	/**
	 * The refusal for this caller's export, or null when they may take it.
	 *
	 * Resolved from the container rather than injected, because a nullable
	 * constructor dependency would make the control skippable: a caller that
	 * constructs this controller without the service would get an export with
	 * no verb check and no sign that one was missing. A service that cannot be
	 * resolved refuses, for the same reason an unreadable rule refuses.
	 *
	 * @param Schema $schema The schema being exported.
	 * @param Register $register The register being exported.
	 *
	 * @return JSONResponse|null The refusal to return, or null when the export may run.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private function exportRefusalFor(Schema $schema, Register $register): ?JSONResponse {
		$rightService = null;
		try {
			$rightService = $this->container->get(ExportRightService::class);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ObjectExportController] Export right service unresolvable, refusing the export',
				context: ['error' => $e->getMessage()]
			);
		}

		// A container that answers with something other than the service is the
		// same situation as one that throws, and it must end the same way. The
		// alternative is an export that runs with no verb check and nothing to
		// say one was missing, which is the hole this whole change closes.
		if (($rightService instanceof ExportRightService) === false) {
			return new JSONResponse(
				data: [
					'error' => 'EXPORT_REFUSED',
					'verb' => ExportRightService::ACTION,
					'rule' => 'right-service-unavailable',
					'message' => 'The export right cannot be evaluated right now, so nothing was exported.',
				],
				statusCode: 503
			);
		}

		$refusal = $rightService->refusalFor(schema: $schema);
		if ($refusal === null) {
			return null;
		}

		$this->recordExportRefusal(refusal: $refusal, table: new RegisterSchema(register: $register, schema: $schema));

		return new JSONResponse(data: $refusal->toResponseBody(), statusCode: $refusal->getStatusCode());
	}//end exportRefusalFor()

	/**
	 * Record a refused export on the audit trail.
	 *
	 * @param ExportRefusedException $refusal The refusal.
	 * @param RegisterSchema                                         $table   The register and schema that were asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function recordExportRefusal(ExportRefusedException $refusal, RegisterSchema $table): void {
		$recorder = $this->exportAuditRecorder();
		if ($recorder === null) {
			return;
		}

		$recorder->recordRefused(
			profile: 'ad-hoc',
			rule: $refusal->getRule(),
			reason: $refusal->getMessage(),
			register: $table->register->getId(),
			schema: $table->schema->getId()
		);
	}//end recordExportRefusal()

	/**
	 * Record a completed export on the audit trail.
	 *
	 * @param ObjectExport $export The export, for its register and schema.
	 * @param ServedExport $served The format written and how many rows left the instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function recordExportCompleted(ObjectExport $export, ServedExport $served): void {
		$recorder = $this->exportAuditRecorder();
		if ($recorder === null) {
			return;
		}

		$recorder->recordCompleted(
			profile: 'ad-hoc',
			rowCount: $served->rowCount,
			format: $served->format,
			valueMode: null,
			register: $export->register->getId(),
			schema: $export->schema->getId()
		);

		$this->recordExportRun(export: $export, served: $served);
	}//end recordExportCompleted()

	/**
	 * Put an ad-hoc API export in the export area as a run.
	 *
	 * The bytes went straight to the caller, so the run keeps no file and has
	 * no expiry, and its download count is one: the register served it once,
	 * in this response. A recorder that cannot be resolved or fails costs the
	 * row, never the export.
	 *
	 * @param ObjectExport $export The export, for its register and schema.
	 * @param ServedExport $served The format and the rows served.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	private function recordExportRun(ObjectExport $export, ServedExport $served): void {
		try {
			$runs = $this->container->get(ExportRunRecorder::class);
			if ($runs instanceof ExportRunRecorder === false) {
				return;
			}

			$runs->record(
				origin: new ExportRunOrigin(
					source: 'api',
					actor: (string)($this->userSession->getUser()?->getUID() ?? ''),
					profile: 'ad-hoc'
				),
				content: new ExportRunContent(
					format: $served->format,
					rowCount: $served->rowCount,
					registerName: (string)($export->register->getSlug() ?? $export->register->getId()),
					schemaName: (string)($export->schema->getSlug() ?? $export->schema->getId())
				),
				file: new ExportRunFile(
					downloadCount: 1
				),
				retentionSeconds: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ObjectExportController] Could not record the export run; the export itself was served',
				context: ['error' => $e->getMessage()]
			);
		}//end try
	}//end recordExportRun()

	/**
	 * The audit recorder, or null when it cannot be resolved.
	 *
	 * Unlike the right service this one may be absent without refusing the
	 * export: see ExportAuditRecorder's own note on why a ledger hiccup must
	 * not fail a monthly aanlevering.
	 *
	 * @return ExportAuditRecorderInterface|null The recorder.
	 */
	private function exportAuditRecorder(): ?ExportAuditRecorderInterface {
		try {
			return $this->container->get(ExportAuditRecorderInterface::class);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ObjectExportController] Export audit recorder unresolvable, the export is not on the trail',
				context: ['error' => $e->getMessage()]
			);

			return null;
		}
	}//end exportAuditRecorder()

	/**
	 * Count the data rows in exported CSV bytes.
	 *
	 * Counted off the artefact that was actually produced rather than off a
	 * second query, so the number on the trail is the number in the file.
	 *
	 * @param string $csv The CSV content.
	 *
	 * @return int The row count, header excluded.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function countCsvExportRows(string $csv): int {
		$lines = array_filter(explode("\n", $csv), static fn ($line) => trim($line) !== '');

		return max(0, (count($lines) - 1));
	}//end countCsvExportRows()

	/**
	 * Count the objects in exported JSON bytes.
	 *
	 * @param string $json The JSON content.
	 *
	 * @return int The row count, or 0 when the payload is not a list.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function countJsonExportRows(string $json): int {
		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			return 0;
		}

		if (isset($decoded['results']) === true && is_array($decoded['results']) === true) {
			return count($decoded['results']);
		}

		return count($decoded);
	}//end countJsonExportRows()

	/**
	 * The number of `<object>` records directly under the root of an exported XML document.
	 *
	 * @param string $xml The document.
	 *
	 * @return int The record count, 0 when the document cannot be read.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
	 */
	private static function countXmlExportRows(string $xml): int {
		$previous = libxml_use_internal_errors(true);
		$reader = new \XMLReader();
		$count = 0;
		if ($reader->XML($xml) === true) {
			while ($reader->read() === true) {
				if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 1 && $reader->name === 'object') {
					$count++;
				}
			}

			$reader->close();
		}

		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return $count;
	}//end countXmlExportRows()

	/**
	 * Sum the data rows across every sheet of an exported spreadsheet.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet The spreadsheet.
	 *
	 * @return int The row count, each sheet header excluded.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function countSpreadsheetExportRows(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): int {
		$rows = 0;
		foreach ($spreadsheet->getAllSheets() as $sheet) {
			$rows += max(0, ($sheet->getHighestRow() - 1));
		}

		return $rows;
	}//end countSpreadsheetExportRows()
}//end class
