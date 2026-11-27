<?php

/**
 * OpenRegister SurveyResultsController
 *
 * A survey's results and its export, for the readers the survey names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Kernel\Contracts\CsvCell;
use OCA\OpenRegister\Service\Survey\SurveyAnswerTable;
use OCA\OpenRegister\Service\Survey\SurveyResults;
use OCA\OpenRegister\Service\Survey\SurveyResultsWithheldException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET a survey's results (count always, answers once they may be shown) and its CSV export.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */
class SurveyResultsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string        $appName      The app name.
	 * @param IRequest      $request      The request.
	 * @param SurveyResults $results      The survey's records and rules.
	 * @param IUserSession  $userSession  The caller.
	 * @param IGroupManager $groupManager The caller's groups and the admin check.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SurveyResults $results,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The survey's results: the response count, and the answer sets unless they are withheld.
	 *
	 * @param string $register The register holding the survey.
	 * @param string $survey   The survey uuid.
	 *
	 * @return JSONResponse The results, or 401/403/404.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-005-who-may-read-the-answers-and-whether-they-are-named
	 */
	#[NoAdminRequired]
	public function results(string $register, string $survey): JSONResponse {
		$readable = $this->readableSurvey(register: $register, surveyUuid: $survey);
		if ($readable instanceof JSONResponse) {
			return $readable;
		}

		return new JSONResponse(data: $this->results->results(register: $register, survey: $readable));
	}//end results()

	/**
	 * The survey's answers as CSV: one row per answer set, one column per question.
	 *
	 * @param string $register The register holding the survey.
	 * @param string $survey   The survey uuid.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or a refusal naming the count and the reason.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-006-answers-export-as-rows
	 */
	#[NoAdminRequired]
	public function export(string $register, string $survey): DataDownloadResponse|JSONResponse {
		$readable = $this->readableSurvey(register: $register, surveyUuid: $survey);
		if ($readable instanceof JSONResponse) {
			return $readable;
		}

		try {
			$table = $this->results->exportTable(register: $register, survey: $readable);
		} catch (SurveyResultsWithheldException $withheld) {
			return new JSONResponse(
				data: ['error' => $withheld->getMessage(), 'withheld' => true, 'count' => $withheld->count],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new DataDownloadResponse(
			data: self::csvOf(table: $table),
			filename: 'survey-' . $survey . '.csv',
			contentType: 'text/csv'
		);
	}//end export()

	/**
	 * The survey when the caller may read its answers, else the refusal.
	 *
	 * @param string $register   The register holding the survey.
	 * @param string $surveyUuid The survey uuid.
	 *
	 * @return array<string, mixed>|JSONResponse The survey, or 401/403/404.
	 */
	private function readableSurvey(string $register, string $surveyUuid): array|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$survey = $this->results->survey(register: $register, surveyUuid: $surveyUuid);
		} catch (DoesNotExistException) {
			$survey = null;
		}

		if ($survey === null) {
			return new JSONResponse(data: ['error' => 'Survey not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$uid = $user->getUID();
		$mayRead = $this->results->mayRead(
			survey: $survey,
			groupIds: $this->groupManager->getUserGroupIds($user),
			isAdmin: $this->groupManager->isAdmin($uid)
		);
		if ($mayRead === false) {
			return new JSONResponse(data: ['error' => 'You are not among the readers of this survey'], statusCode: Http::STATUS_FORBIDDEN);
		}

		return $survey;
	}//end readableSurvey()

	/**
	 * A table as CSV, its column labels as the header row; every cell made CSV-safe
	 * (a value that would start a spreadsheet formula gets a leading apostrophe).
	 *
	 * @param SurveyAnswerTable $table The table.
	 *
	 * @return string The CSV.
	 */
	private static function csvOf(SurveyAnswerTable $table): string {
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, CsvCell::row(row: $table->columns), ',', '"', '');
		foreach ($table->rows as $row) {
			$line = [];
			foreach ($table->columns as $key) {
				$line[] = self::cell(value: ($row[$key] ?? null));
			}

			fputcsv($handle, CsvCell::row(row: $line), ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end csvOf()

	/**
	 * One cell as text: scalars as written, anything else as JSON.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The cell.
	 */
	private static function cell(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return (string)json_encode($value);
	}//end cell()
}//end class
