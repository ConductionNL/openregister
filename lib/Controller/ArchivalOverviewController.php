<?php

/**
 * OpenRegister Archival Overview Controller
 *
 * Serves the read the Archival page is built on, and its draw-up action.
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
 * @spec openspec/changes/archival-overview-page/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Archival\ArchivalOverviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The archival overview endpoint.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
class ArchivalOverviewController extends Controller {

	/**
	 * The group that does the archiving work.
	 */
	private const ARCHIVIST_GROUP = 'archivaris';

	/**
	 * Constructor.
	 *
	 * @param string                  $appName      The app name.
	 * @param IRequest                $request      The request.
	 * @param ArchivalOverviewService $overview     Gathers the overview.
	 * @param IUserSession            $userSession  Who is asking.
	 * @param IGroupManager           $groupManager Whether they may.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ArchivalOverviewService $overview,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The archiving work at a glance.
	 *
	 * GET /api/archival/overview
	 *
	 * @return JSONResponse The overview, or 401/403.
	 *
	 * @spec openspec/changes/archival-overview-page/specs/archival-destruction-workflow/spec.md#requirement-the-archiving-work-has-one-overview-read-req-aov-001
	 */
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$refusal = $this->refuseNonArchivist();
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(data: $this->overview->overview(), statusCode: Http::STATUS_OK);
	}//end overview()

	/**
	 * Draw up a destruction list from every record eligible today.
	 *
	 * POST /api/archival/destruction-lists/draw-up
	 *
	 * @return JSONResponse 201 with the list, 422 when nothing is eligible, 409 when no list register is configured.
	 *
	 * @spec openspec/changes/archival-overview-page/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-can-be-drawn-up-from-the-page-req-aov-003
	 */
	#[NoAdminRequired]
	public function drawUp(): JSONResponse {
		$refusal = $this->refuseNonArchivist();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$created = $this->overview->drawUpList();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'configured' => false],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		if ($created['list'] === null) {
			return new JSONResponse(
				data: ['error' => 'Nothing may be destroyed now, so no list was drawn up.', 'refused' => $created['refused']],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			data: [
				'uuid' => ($created['list']['uuid'] ?? null),
				'status' => ($created['list']['status'] ?? null),
				'entryCount' => count(($created['list']['objects'] ?? [])),
				'refused' => $created['refused'],
			],
			statusCode: Http::STATUS_CREATED
		);
	}//end drawUp()

	/**
	 * Refuse anybody who is not an archivist or an admin.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function refuseNonArchivist(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'Niet geauthenticeerd'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$uid = $user->getUID();
		if ($this->groupManager->isInGroup($uid, self::ARCHIVIST_GROUP) === false
			&& $this->groupManager->isAdmin($uid) === false
		) {
			return new JSONResponse(
				data: ['error' => 'Onvoldoende rechten: archivaris rol is vereist'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end refuseNonArchivist()
}//end class
