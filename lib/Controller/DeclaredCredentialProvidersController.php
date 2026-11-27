<?php

/**
 * DeclaredCredentialProvidersController — the administrator's review list of declared providers.
 *
 * One admin-only route beside the approve, reject and revoke routes. Nextcloud's
 * `AuthorizedAdminSetting` admits administrators and delegated admins of the
 * OpenRegister settings; the in-body administrator check then admits only a real
 * administrator, the same audience that may decide, because this list names the
 * host and the rules a declaration would open.
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
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Kernel\Contracts\AdminSettingDelegation;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\Credential\DeclaredProviderResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Lists every declared credential provider for review: origin, status, host and rules.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
 */
class DeclaredCredentialProvidersController extends Controller {

	/**
	 * The 403 message for a caller who is not an administrator.
	 *
	 * @var string
	 */
	private const ADMIN_ONLY = 'Only administrators may review declared credential providers.';

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request     The request.
	 * @param IGroupManager            $groups      Tells a real administrator from anyone else.
	 * @param IUserSession             $userSession The reviewing administrator.
	 * @param DeclaredProviderResolver $declared    The declarations and their admission state.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
	 */
	public function __construct(
		IRequest $request,
		private readonly IGroupManager $groups,
		private readonly IUserSession $userSession,
		private readonly DeclaredProviderResolver $declared,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/credentials/providers/declared — every declaration with its host and rules.
	 *
	 * @return JSONResponse `{results}`, or 401/403 for a caller who may not review.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
	 */
	#[AuthorizedAdminSetting(settings: AdminSettingDelegation::OPEN_REGISTER_ADMIN)]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->groups->isAdmin($user->getUID()) === false) {
			return new JSONResponse(data: ['error' => self::ADMIN_ONLY], statusCode: 403);
		}

		return new JSONResponse(data: ['results' => $this->declared->review()]);
	}//end index()
}//end class
