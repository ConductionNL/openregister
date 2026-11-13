<?php

/**
 * The admin query console's HTTP surface.
 *
 * Two endpoints over one read-only, bounded GraphQL runner: run a query and
 * read the result, or run it and download the first list as CSV or JSON.
 *
 * ADMINISTRATORS ONLY, AND BY THE MIDDLEWARE. No method here carries
 * `#[NoAdminRequired]`, so Nextcloud rejects a non-administrator with 403
 * before the controller is constructed, the posture every other
 * `/api/operations/*` route takes. An in-body check behind `#[NoAdminRequired]`
 * would put the only barrier in a line a refactor can delete.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Exception\NoTabularResultException;
use OCA\OpenRegister\Exception\ReadOnlyQueryException;
use OCA\OpenRegister\Search\Service\GraphQL\BoundedRun;
use OCA\OpenRegister\Service\GraphQL\AdminQueryAudit;
use OCA\OpenRegister\Service\GraphQL\AdminQueryExport;
use OCA\OpenRegister\Service\GraphQL\AdminQueryRunner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * OperationsQueryController.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class OperationsQueryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request The HTTP request.
	 * @param AdminQueryRunner $runner  The read-only, bounded GraphQL runner.
	 * @param AdminQueryExport $export  The first-list flattener and CSV/JSON writer.
	 * @param AdminQueryAudit  $audit   The run and download audit writer.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly AdminQueryRunner $runner,
		private readonly AdminQueryExport $export,
		private readonly AdminQueryAudit $audit,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `POST /api/operations/query` — run a read-only query and answer the result.
	 *
	 * @return JSONResponse The GraphQL result, or 400 `READ_ONLY` when the query writes.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function run(): JSONResponse {
		$query = (string) $this->request->getParam('query', '');
		$variables = $this->variables();
		$operationName = $this->operationName();

		try {
			$result = $this->runner->run(query: $query, variables: $variables, operationName: $operationName);
		} catch (ReadOnlyQueryException $refusal) {
			$this->audit->record(
				action: AdminQueryAudit::ACTION_RUN,
				query: $query,
				variables: $variables,
				operation: $operationName,
				outcome: ['rows' => 0, 'truncated' => false, 'durationMs' => 0, 'outcome' => 'read-only-refused']
			);

			return new JSONResponse(['error' => 'READ_ONLY', 'message' => $refusal->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$this->audit->record(
			action: AdminQueryAudit::ACTION_RUN,
			query: $query,
			variables: $variables,
			operation: $operationName,
			outcome: $this->outcomeOf(result: $result)
		);

		return new JSONResponse($result);
	}//end run()

	/**
	 * `POST /api/operations/query/export` — run a query and download the first list.
	 *
	 * @return JSONResponse|DataDownloadResponse The file, 400 `READ_ONLY` when the query writes, or 422 when there is nothing to download.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function export(): JSONResponse|DataDownloadResponse {
		$query = (string) $this->request->getParam('query', '');
		$variables = $this->variables();
		$operationName = $this->operationName();
		$format = 'csv';
		if (strtolower((string) $this->request->getParam('format', 'csv')) === 'json') {
			$format = 'json';
		}


		try {
			$result = $this->runner->run(query: $query, variables: $variables, operationName: $operationName);
			$table = $this->export->tableOf(result: $result);
		} catch (ReadOnlyQueryException $refusal) {
			$this->auditExport(
				query: $query,
				variables: $variables,
				operation: $operationName,
				format: $format,
				outcome: $this->refusedOutcome(format: $format, outcome: 'read-only-refused')
			);

			return new JSONResponse(['error' => 'READ_ONLY', 'message' => $refusal->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NoTabularResultException $empty) {
			$this->auditExport(
				query: $query,
				variables: $variables,
				operation: $operationName,
				format: $format,
				outcome: $this->refusedOutcome(format: $format, outcome: 'no-list')
			);

			return new JSONResponse(['error' => 'NO_LIST', 'message' => $empty->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}//end try

		$outcome = $this->outcomeOf(result: $result);
		$outcome['format'] = $format;
		$this->auditExport(query: $query, variables: $variables, operation: $operationName, format: $format, outcome: $outcome);

		$stamp = (new DateTime())->format('Ymd-Hi');
		if ($format === 'json') {
			return new DataDownloadResponse($this->export->toJson(table: $table), 'query-' . $stamp . '.json', 'application/json');
		}

		return new DataDownloadResponse($this->export->toCsv(table: $table), 'query-' . $stamp . '.csv', 'text/csv');
	}//end export()

	/**
	 * The request's variables, an object or none.
	 *
	 * @return array<string, mixed> The variables.
	 */
	private function variables(): array {
		$variables = $this->request->getParam('variables');
		if (is_array($variables) === true) {
			return $variables;
		}

		return [];
	}//end variables()

	/**
	 * The request's operation name, or null.
	 *
	 * @return string|null The operation name.
	 */
	private function operationName(): ?string {
		$operationName = $this->request->getParam('operationName');
		if (is_string($operationName) === true && $operationName !== '') {
			return $operationName;
		}

		return null;
	}//end operationName()

	/**
	 * The audit outcome of a completed run, read from its extensions and errors.
	 *
	 * @param array<string, mixed> $result The GraphQL result.
	 *
	 * @return array{rows: int, truncated: bool, durationMs: int, outcome: string} The outcome.
	 */
	private function outcomeOf(array $result): array {
		$extensions = ($result['extensions'] ?? []);

		return [
			'rows' => (int) ($extensions['rows'] ?? 0),
			'truncated' => (bool) ($extensions['truncated'] ?? false),
			'durationMs' => (int) ($extensions['durationMs'] ?? 0),
			'outcome' => $this->classify(result: $result),
		];
	}//end outcomeOf()

	/**
	 * Whether a completed run was clean, timed out, or errored.
	 *
	 * @param array<string, mixed> $result The GraphQL result.
	 *
	 * @return string `ok`, `timeout` or `error`.
	 */
	private function classify(array $result): string {
		$errors = ($result['errors'] ?? []);
		if (is_array($errors) === false || $errors === []) {
			return 'ok';
		}

		foreach ($errors as $error) {
			if (($error['extensions']['code'] ?? null) === BoundedRun::TIMEOUT_CODE) {
				return 'timeout';
			}
		}

		return 'error';
	}//end classify()

	/**
	 * The outcome shape of a refused export.
	 *
	 * @param string $format  The requested format.
	 * @param string $outcome The outcome word.
	 *
	 * @return array{rows: int, truncated: bool, durationMs: int, outcome: string, format: string} The outcome.
	 */
	private function refusedOutcome(string $format, string $outcome): array {
		return ['rows' => 0, 'truncated' => false, 'durationMs' => 0, 'outcome' => $outcome, 'format' => $format];
	}//end refusedOutcome()

	/**
	 * Write a `query.export` audit row.
	 *
	 * @param string               $query     The query text.
	 * @param array<string, mixed> $variables The variables, named only.
	 * @param string|null          $operation The operation name.
	 * @param string               $format    The requested format.
	 * @param array<string, mixed> $outcome   The run outcome.
	 *
	 * @return void
	 */
	private function auditExport(string $query, array $variables, ?string $operation, string $format, array $outcome): void {
		$this->audit->record(
			action: AdminQueryAudit::ACTION_EXPORT,
			query: $query,
			variables: $variables,
			operation: $operation,
			outcome: $outcome
		);
	}//end auditExport()
}//end class
