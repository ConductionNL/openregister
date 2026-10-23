<?php

/**
 * CredentialOrganisationController — the organisation picker of the organisation-credential form.
 *
 * Lists the organisations the caller may hold organisation credentials for, so the form can
 * show which organisation a credential goes into and let an administrator choose another one
 * (broker-acts-for-an-organisation-member). The list is a convenience, never the authority:
 * `CredentialController::create()` re-checks `isOrganisationAdmin()` for whatever
 * organisation the client sends, and the organisation listing re-checks access.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The organisations a caller may manage organisation credentials for.
 *
 * @spec openspec/changes/broker-acts-for-an-organisation-member/specs/credential-broker/spec.md#requirement-an-organisation-credential-names-and-lets-the-admin-choose-its-organisation
 */
class CredentialOrganisationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The session (the caller).
	 * @param OrganisationService $organisationService Resolves what the caller may manage.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly OrganisationService $organisationService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/credentials/organisations — the organisations the caller may hold credentials for.
	 *
	 * Every organisation the caller may manage (a Nextcloud admin: all of them; anyone else:
	 * the ones they own), with the caller's active organisation flagged as the default. Only
	 * uuid, name and the flag are returned.
	 *
	 * @return JSONResponse `{results: Array<{uuid, name, active}>}`.
	 *
	 * @spec openspec/changes/broker-acts-for-an-organisation-member/specs/credential-broker/spec.md#requirement-an-organisation-credential-names-and-lets-the-admin-choose-its-organisation
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$active = (string)($this->organisationService->getActiveOrganisation()?->getUuid() ?? '');
		$results = [];
		foreach ($this->organisationService->getManageableOrganisations(userId: $uid) as $organisation) {
			$uuid = (string)$organisation->getUuid();
			$results[] = [
				'uuid' => $uuid,
				'name' => (string)$organisation->getName(),
				'active' => ($uuid === $active),
			];
		}

		return new JSONResponse(['results' => $results]);
	}//end index()
}//end class
