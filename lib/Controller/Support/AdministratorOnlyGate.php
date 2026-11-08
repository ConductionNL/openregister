<?php

/**
 * OpenRegister administrator-only gate
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

use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The in-body administrator check of the register-descriptor and
 * working-calendar admin endpoints: a JSON 401 without a user, a JSON 403
 * for a non-administrator.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
final class AdministratorOnlyGate {
	/**
	 * Bind the session and the group manager.
	 *
	 * @param IUserSession  $userSession  Resolves the caller.
	 * @param IGroupManager $groupManager Answers whether they are an administrator.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Refuse a caller who is not an administrator.
	 *
	 * @param string $forbidden The 403 message naming what is admin-only.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function refusal(string $forbidden): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(data: ['error' => $forbidden], statusCode: 403);
		}

		return null;
	}//end refusal()
}//end class
