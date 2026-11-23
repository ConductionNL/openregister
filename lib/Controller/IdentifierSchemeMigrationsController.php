<?php

/**
 * IdentifierSchemeMigrationsController — start an identifier scheme migration and read its progress.
 *
 * Administrators only: a migration rewrites every issued identifier of a
 * property.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\Support\AdministratorOnlyGate;
use OCA\OpenRegister\Kernel\Contracts\AdminSettingDelegation;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\IdentifierSchemeMigrationRunningException;
use OCA\OpenRegister\Service\IdentifierSchemeMigrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * POST /api/schemas/{id}/identifier-migrations and GET /api/identifier-migrations/{run}.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
 */
class IdentifierSchemeMigrationsController extends Controller {

	/**
	 * The refusal for a caller who is not an administrator.
	 */
	private const ADMIN_ONLY = 'Only an administrator may migrate identifiers';

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request     The request.
	 * @param AdministratorOnlyGate            $gate        Refuses a caller who is not an administrator.
	 * @param IdentifierSchemeMigrationService $migrations  The migrations.
	 * @param IUserSession                     $userSession Who starts a migration.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministratorOnlyGate $gate,
		private readonly IdentifierSchemeMigrationService $migrations,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Start rewriting a property's identifiers from their previous format.
	 *
	 * Body: `{register: int, property: string, previousFormat: string}`.
	 *
	 * @param int $id The schema id.
	 *
	 * @return JSONResponse 202 with the run; 400 for an unusable request; 404 for
	 *                      an unknown schema; 409 naming the run already migrating
	 *                      the sequence.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	#[AuthorizedAdminSetting(settings: AdminSettingDelegation::OPEN_REGISTER_ADMIN)]
	public function create(int $id): JSONResponse {
		$refusal = $this->gate->refusal(forbidden: self::ADMIN_ONLY);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$run = $this->migrations->start(
				registerId: (int)$this->request->getParam('register', 0),
				schemaId: $id,
				property: trim((string)$this->request->getParam('property', '')),
				previousFormat: (string)$this->request->getParam('previousFormat', ''),
				startedBy: $this->userSession->getUser()?->getUID()
			);
		} catch (IdentifierSchemeMigrationRunningException $e) {
			return new JSONResponse(['error' => $e->getMessage(), 'running' => $e->getRunning()], Http::STATUS_CONFLICT);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Schema not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($run, Http::STATUS_ACCEPTED);
	}//end create()

	/**
	 * One migration and its progress.
	 *
	 * @param int $run The run id.
	 *
	 * @return JSONResponse 200 with the run, or 404.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	#[AuthorizedAdminSetting(settings: AdminSettingDelegation::OPEN_REGISTER_ADMIN)]
	public function show(int $run): JSONResponse {
		$refusal = $this->gate->refusal(forbidden: self::ADMIN_ONLY);
		if ($refusal !== null) {
			return $refusal;
		}

		$found = $this->migrations->find(id: $run);
		if ($found === null) {
			return new JSONResponse(['error' => 'Migration not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($found);
	}//end show()
}//end class
