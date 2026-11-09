<?php

/**
 * MigrationController
 *
 * Controller for storage migration between blob and magic tables.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\MigrationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for storage migration between blob and magic tables.
 */
class MigrationController extends Controller {
	/**
	 * Constructor for MigrationController.
	 *
	 * @param IRequest $request HTTP request object.
	 * @param MigrationServiceInterface $migrationService Migration service.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly MigrationServiceInterface $migrationService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Get storage status for a register/schema combination.
	 *
	 * @param string $register Register ID or slug.
	 * @param string $schema Schema ID or slug.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Storage status.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-7
	 */
	public function status(string $register, string $schema): JSONResponse {
		try {
			$resolved = $this->resolve(register: $register, schema: $schema);

			$status = $this->migrationService->getStorageStatus(
				register: $resolved['register'],
				schema: $resolved['schema']
			);

			return new JSONResponse(data: $status);
		} catch (\Exception $e) {
			return $this->failure(e: $e);
		}
	}//end status()

	/**
	 * Trigger a migration between blob storage and magic tables.
	 *
	 * @NoCSRFRequired
	 *
	 * Expected body: {register, schema, direction, batchSize?, dryRun?}
	 *
	 * @return JSONResponse Migration report.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-7
	 */
	public function migrate(): JSONResponse {
		try {
			$params = $this->migrationParams();
			$refusal = $this->refuseMigration(params: $params);
			if ($refusal !== null) {
				return $refusal;
			}

			return new JSONResponse(data: $this->runMigration(params: $params));
		} catch (\Exception $e) {
			return $this->failure(e: $e);
		}
	}//end migrate()

	/**
	 * The migration request's parameters, read in body order.
	 *
	 * @return array{register: mixed, schema: mixed, direction: mixed, batchSize: int, dryRun: bool}
	 */
	private function migrationParams(): array {
		return [
			'register' => $this->request->getParam('register'),
			'schema' => $this->request->getParam('schema'),
			'direction' => $this->request->getParam('direction'),
			'batchSize' => (int)($this->request->getParam('batchSize', 100)),
			'dryRun' => filter_var(
				$this->request->getParam('dryRun', false),
				FILTER_VALIDATE_BOOLEAN
			),
		];
	}//end migrationParams()

	/**
	 * Refuse a migration request without a target or with an unknown direction.
	 *
	 * @param array<string, mixed> $params The migration parameters.
	 *
	 * @return JSONResponse|null A 400, or null when the request may run.
	 */
	private function refuseMigration(array $params): ?JSONResponse {
		if (empty($params['register']) === true || empty($params['schema']) === true) {
			return $this->badRequest(error: 'register and schema parameters are required');
		}

		if (in_array($params['direction'], ['to-magic', 'to-blob'], true) === false) {
			return $this->badRequest(error: 'direction must be "to-magic" or "to-blob"');
		}

		return null;
	}//end refuseMigration()

	/**
	 * Run the requested migration and return its report.
	 *
	 * The blob migration runs for both directions; `to-magic` then runs the
	 * magic-table migration and returns that report instead.
	 *
	 * @param array<string, mixed> $params The validated migration parameters.
	 *
	 * @return array<string, mixed> The migration report.
	 */
	private function runMigration(array $params): array {
		$resolved = $this->resolve(register: $params['register'], schema: $params['schema']);
		$register = $resolved['register'];
		$schema = $resolved['schema'];
		$batchSize = $params['batchSize'];
		$dryRun = $params['dryRun'];

		$report = $this->migrationService->migrateToBlobStorage(register: $register, schema: $schema, batchSize: $batchSize, dryRun: $dryRun);
		if ($params['direction'] === 'to-magic') {
			$report = $this->migrationService->migrateToMagicTable(register: $register, schema: $schema, batchSize: $batchSize, dryRun: $dryRun);
		}

		return $report;
	}//end runMigration()

	/**
	 * Resolve a register and schema reference pair.
	 *
	 * @param string|integer $register The register reference.
	 * @param string|integer $schema The schema reference.
	 *
	 * @return array{register: \OCA\OpenRegister\Db\Register, schema: \OCA\OpenRegister\Db\Schema} The resolved pair.
	 */
	private function resolve(string|int $register, string|int $schema): array {
		return $this->migrationService->resolveRegisterAndSchema(registerId: $register, schemaId: $schema);
	}//end resolve()

	/**
	 * A 400 carrying one error message.
	 *
	 * @param string $error The error message.
	 *
	 * @return JSONResponse The 400.
	 */
	private function badRequest(string $error): JSONResponse {
		return new JSONResponse(data: ['error' => $error], statusCode: 400);
	}//end badRequest()

	/**
	 * A 500 carrying the exception message.
	 *
	 * @param \Exception $e The failure.
	 *
	 * @return JSONResponse The 500.
	 */
	private function failure(\Exception $e): JSONResponse {
		return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
	}//end failure()
}//end class
