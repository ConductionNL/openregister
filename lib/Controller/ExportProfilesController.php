<?php

/**
 * OpenRegister Export Profiles Controller
 *
 * REST CRUD for export profiles, owner scoped, plus the run action that
 * produces the file. The export verb is checked on the run, in the service, so
 * an integration that calls it directly meets the same refusal a browser does.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Service\Export\ExportProfileWriter;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * ExportProfilesController administers export profiles and runs one.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The fourteenth dependency is the export-run
 *     recorder, and it is here rather than inside the profile service on purpose: this is the
 *     method that hands the bytes to a caller, so this is where "the register served a copy"
 *     is a true statement. Pushing it one layer down would record a run for a call that had
 *     not yet succeeded.
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */
class ExportProfilesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string               $appName      Application name.
	 * @param IRequest             $request      HTTP request.
	 * @param ExportProfileService $service      Profile administration and runs.
	 * @param IUserSession         $userSession  Current-user session.
	 * @param IGroupManager        $groupManager Group manager for the admin check.
	 * @param ExportRunRecorder     $exportRuns   Records the export this run served.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ExportProfileService $service,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ExportRunRecorder $exportRuns,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List the caller's export profiles. An administrator sees every profile.
	 *
	 * @return JSONResponse The profiles.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		$profiles = $this->service->listFor(callerUid: $userId, callerIsAdmin: $this->isCurrentUserAdmin());
		$items = array_map(static fn (ExportProfile $profile) => $profile->jsonSerialize(), $profiles);

		return new JSONResponse(data: ['results' => $items, 'total' => count($items)]);
	}//end index()

	/**
	 * Read one export profile. Owner or administrator only.
	 *
	 * @param int $id The profile id.
	 *
	 * @return JSONResponse The profile.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		try {
			$profile = $this->service->find(id: $id);
			$this->service->assertOwnerOrAdmin(
				profile: $profile,
				callerUid: $userId,
				callerIsAdmin: $this->isCurrentUserAdmin()
			);
		} catch (DoesNotExistException $e) {
			return $this->notFoundResponse();
		} catch (ExportRefusedException $e) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: $e->getStatusCode());
		}

		return new JSONResponse(data: $profile->jsonSerialize());
	}//end show()

	/**
	 * Create an export profile owned by the caller.
	 *
	 * @return JSONResponse The persisted profile.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		try {
			$profile = $this->service->create(data: $this->submitted(), ownerUid: $userId);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}

		return new JSONResponse(data: $profile->jsonSerialize(), statusCode: 201);
	}//end create()

	/**
	 * Change an export profile. Owner or administrator only.
	 *
	 * @param int $id The profile id.
	 *
	 * @return JSONResponse The persisted profile.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(int $id): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		try {
			$profile = $this->service->find(id: $id);
			$this->service->assertOwnerOrAdmin(
				profile: $profile,
				callerUid: $userId,
				callerIsAdmin: $this->isCurrentUserAdmin()
			);
			$profile = $this->service->update(profile: $profile, data: $this->submitted());
		} catch (DoesNotExistException $e) {
			return $this->notFoundResponse();
		} catch (ExportRefusedException $e) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: $e->getStatusCode());
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}

		return new JSONResponse(data: $profile->jsonSerialize());
	}//end update()

	/**
	 * Delete an export profile. Owner or administrator only.
	 *
	 * @param int $id The profile id.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $id): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		try {
			$profile = $this->service->find(id: $id);
			$this->service->assertOwnerOrAdmin(
				profile: $profile,
				callerUid: $userId,
				callerIsAdmin: $this->isCurrentUserAdmin()
			);
			$this->service->delete(profile: $profile);
		} catch (DoesNotExistException $e) {
			return $this->notFoundResponse();
		} catch (ExportRefusedException $e) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: $e->getStatusCode());
		}

		return new JSONResponse(data: ['deleted' => true]);
	}//end destroy()

	/**
	 * Run an export profile and hand back the file.
	 *
	 * The verb is checked inside the service, not here, so the scheduled runner
	 * and the whole-set job meet the same check without a controller.
	 *
	 * @param int $id The profile id.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function run(int $id): DataDownloadResponse|JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		try {
			$profile = $this->service->find(id: $id);
			$this->service->assertOwnerOrAdmin(
				profile: $profile,
				callerUid: $userId,
				callerIsAdmin: $this->isCurrentUserAdmin()
			);
			$written = $this->service->run(profile: $profile, actorUid: $userId);
		} catch (DoesNotExistException $e) {
			return $this->notFoundResponse();
		} catch (ExportRefusedException $e) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: $e->getStatusCode());
		}

		// Record the run before the bytes leave. This path serves the export
		// straight to the caller, so there is no file for a sweep to delete
		// and the retention is null: the row is the record, and it is the
		// register handing the copy over, which is what the count counts.
		$this->recordRun(profile: $profile, actorUid: $userId, written: $written);

		$contentType = 'text/csv';
		if (($profile->getFormat() ?? 'csv') === 'json') {
			$contentType = 'application/json';
		}

		$response = new DataDownloadResponse(
			data: $written['bytes'],
			filename: $written['filename'],
			contentType: $contentType
		);

		// The mode is in the file too. It is repeated here so a client that
		// streams the bytes straight to disk does not have to read them back to
		// learn what it just saved.
		$response->addHeader('X-OpenRegister-Export-Value-Mode', (string)$written['metadata']['valueMode']);
		$response->addHeader('X-OpenRegister-Export-Row-Count', (string)$written['rowCount']);
		$response->addHeader('X-OpenRegister-Export-Profile', (string)$written['metadata']['profileUuid']);

		return $response;
	}//end run()

	/**
	 * Record what this profile run served.
	 *
	 * Never throws: the caller already holds the bytes by the time anything
	 * here could fail, and turning a bookkeeping failure into a 500 would lose
	 * an export that succeeded. The gap is logged by the recorder.
	 *
	 * The run is written with `served` status, no expiry and a download count
	 * of one, because the bytes went straight out rather than into a file. A
	 * download served from the register is exactly what the count counts.
	 *
	 * @param mixed  $profile  The export profile.
	 * @param string $actorUid Who asked for it.
	 * @param array  $written  The rendered export.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	private function recordRun($profile, string $actorUid, array $written): void {
		try {
			$this->exportRuns->record(
				source: 'export-profile',
				actor: $actorUid,
				format: (string)($profile->getFormat() ?? 'csv'),
				rowCount: (int)($written['rowCount'] ?? 0),
				profile: (string)($written['metadata']['profileUuid'] ?? ''),
				filename: (string)($written['filename'] ?? ''),
				retentionSeconds: null,
				downloadCount: 1,
				status: \OCA\OpenRegister\Db\ExportRun::STATUS_SERVED
			);
		} catch (\Throwable $e) {
			// Deliberately swallowed: see the docblock.
			unset($e);
		}
	}//end recordRun()

	/**
	 * The metadata line prefix a CSV export opens with, published so a consumer
	 * can skip it without hardcoding it.
	 *
	 * @return JSONResponse The export contract a consumer reads.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function contract(): JSONResponse {
		return new JSONResponse(
			data: [
				'csvMetadataPrefix' => ExportProfileWriter::CSV_METADATA_PREFIX,
				'valueModes' => ExportProfile::MODES,
				'formats' => ExportProfile::FORMATS,
				'headers' => [
					'valueMode' => 'X-OpenRegister-Export-Value-Mode',
					'rowCount' => 'X-OpenRegister-Export-Row-Count',
					'profile' => 'X-OpenRegister-Export-Profile',
				],
			]
		);
	}//end contract()

	/**
	 * The submitted body, without the routing key.
	 *
	 * @return array<string, mixed> The submission.
	 */
	private function submitted(): array {
		$data = $this->request->getParams();
		unset($data['_route'], $data['id']);

		return $data;
	}//end submitted()

	/**
	 * Resolve the caller's uid, or null when anonymous.
	 *
	 * @return string|null The uid.
	 */
	private function resolveUserId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end resolveUserId()

	/**
	 * Whether the caller is a Nextcloud administrator.
	 *
	 * @return bool True when they are.
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()

	/**
	 * The 401 an anonymous caller gets.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function authRequiredResponse(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
	}//end authRequiredResponse()

	/**
	 * The 404 a missing profile gets.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function notFoundResponse(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Export profile not found'], statusCode: 404);
	}//end notFoundResponse()
}//end class
