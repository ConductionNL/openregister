<?php

/**
 * OpenRegister register downloads
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The files the registers controller hands out: exports and import templates.
 *
 * The controller finds the register and schema; this class renders them.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
final class RegisterDownloads {
	private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	/**
	 * Keep the session and services the downloads read.
	 *
	 * @param ControllerSession           $session  The session, for the current user.
	 * @param RegistersControllerServices $services The controller's services.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function __construct(
		private readonly ControllerSession $session,
		private readonly RegistersControllerServices $services,
	) {
	}//end __construct()

	/**
	 * A register's objects as an Excel download.
	 *
	 * @param Register $register The register.
	 *
	 * @return DataDownloadResponse The download.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function excel(Register $register): DataDownloadResponse {
		$spreadsheet = $this->services->transfer->exportService->exportToExcel(
			register: $register,
			schema: null,
			filters: [],
			currentUser: $this->session->userSession->getUser()
		);
		$filename    = $this->stamped(stem: $register->getSlug() ?? 'register', extension: 'xlsx');
		return new DataDownloadResponse($this->xlsxBytes(spreadsheet: $spreadsheet), $filename, self::XLSX_MIME);
	}//end excel()

	/**
	 * One schema of a register as a CSV download.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return DataDownloadResponse The download.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function csv(Register $register, Schema $schema): DataDownloadResponse {
		$csv = $this->services->transfer->exportService->exportToCsv(
			register: $register,
			schema: $schema,
			filters: [],
			currentUser: $this->session->userSession->getUser()
		);
		$stem = $this->schemaStem(register: $register, schema: $schema);
		return new DataDownloadResponse($csv, $this->stamped(stem: $stem, extension: 'csv'), 'text/csv');
	}//end csv()

	/**
	 * One schema of a register as a PDF download.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return JSONResponse|DataDownloadResponse The download, or the refusal when the export is too large or needs an office server.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function pdf(Register $register, Schema $schema): JSONResponse|DataDownloadResponse {
		try {
			$user = $this->session->userSession->getUser();
			$pdf  = $this->services->transfer->exportService->exportToPdf(register: $register, schema: $schema, filters: [], currentUser: $user);
		} catch (ExportTooLargeException $e) {
			return $this->tooLarge(refusal: $e);
		} catch (OfficeServerRequiredException $e) {
			return new JSONResponse(data: $e->toResponseData(), statusCode: OfficeServerRequiredException::HTTP_STATUS);
		}

		$stem = $this->schemaStem(register: $register, schema: $schema);
		return new DataDownloadResponse($pdf, $this->stamped(stem: $stem, extension: 'pdf'), 'application/pdf');
	}//end pdf()

	/**
	 * A register's configuration as a JSON download.
	 *
	 * @param Register $register       The register.
	 * @param bool     $includeObjects Whether the export carries the register's objects.
	 *
	 * @return DataDownloadResponse The download.
	 *
	 * @throws Exception When the configuration cannot be encoded as JSON.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function configuration(Register $register, bool $includeObjects): DataDownloadResponse {
		$exportData  = $this->services->integrations->configurationService->exportConfig(
			input: $register,
			includeObjects: $includeObjects
		);
		$jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($jsonContent === false) {
			throw new Exception('Failed to encode register data to JSON');
		}

		$filename = $this->stamped(stem: $register->getSlug() ?? 'register', extension: 'json');
		return new DataDownloadResponse($jsonContent, $filename, 'application/json');
	}//end configuration()

	/**
	 * An empty CSV import template for a schema.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return DataDownloadResponse The download.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function templateCsv(Register $register, Schema $schema): DataDownloadResponse {
		$content = $this->services->transfer->exportService->buildTemplateCsv(
			register: $register,
			schema: $schema,
			currentUser: $this->session->userSession->getUser()
		);
		return new DataDownloadResponse($content, $this->templateName(schema: $schema) . '.csv', 'text/csv');
	}//end templateCsv()

	/**
	 * An empty spreadsheet import template for a schema.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return DataDownloadResponse The download.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function templateXlsx(Register $register, Schema $schema): DataDownloadResponse {
		$spreadsheet = $this->services->transfer->exportService->buildTemplateSpreadsheet(
			register: $register,
			schema: $schema,
			currentUser: $this->session->userSession->getUser()
		);
		$content     = $this->xlsxBytes(spreadsheet: $spreadsheet);
		return new DataDownloadResponse($content, $this->templateName(schema: $schema) . '.xlsx', self::XLSX_MIME);
	}//end templateXlsx()

	/**
	 * The answer to an export over the row limit.
	 *
	 * @param ExportTooLargeException $refusal The refusal.
	 *
	 * @return JSONResponse The answer.
	 */
	private function tooLarge(ExportTooLargeException $refusal): JSONResponse {
		return new JSONResponse(
			data: [
				'error'    => 'export_too_large',
				'message'  => $refusal->getMessage(),
				'rowCount' => $refusal->getRowCount(),
				'maxRows'  => $refusal->getMaxRows(),
			],
			statusCode: ExportTooLargeException::HTTP_STATUS
		);
	}//end tooLarge()

	/**
	 * The bytes of a spreadsheet as an xlsx file.
	 *
	 * @param Spreadsheet $spreadsheet The spreadsheet.
	 *
	 * @return string The bytes.
	 */
	private function xlsxBytes(Spreadsheet $spreadsheet): string {
		$writer = new Xlsx($spreadsheet);
		ob_start();
		$writer->save('php://output');
		return (string) ob_get_clean();
	}//end xlsxBytes()

	/**
	 * The name stem of a register and schema pair.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return string The stem.
	 */
	private function schemaStem(Register $register, Schema $schema): string {
		return ($register->getSlug() ?? 'register') . '_' . ($schema->getSlug() ?? 'schema');
	}//end schemaStem()

	/**
	 * A file name carrying the moment of the export.
	 *
	 * @param string $stem      The name stem.
	 * @param string $extension The extension.
	 *
	 * @return string The file name.
	 */
	private function stamped(string $stem, string $extension): string {
		return sprintf('%s_%s.%s', $stem, (new DateTime())->format('Y-m-d_His'), $extension);
	}//end stamped()

	/**
	 * The file name of a schema's import template, without extension.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string The name.
	 */
	private function templateName(Schema $schema): string {
		return ($schema->getSlug() ?? 'schema') . '_template';
	}//end templateName()
}//end class
