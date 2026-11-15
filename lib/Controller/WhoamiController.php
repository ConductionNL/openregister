<?php

/**
 * OpenRegister WhoamiController
 *
 * Who the calling principal is, and what its token may do.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-the-effective-grant-is-visible-and-writes-name-the-token
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET /api/whoami: the calling user, the token or Consumer it acts through
 * (`actorVia`), and the effective grant that narrows it (design D-4). A
 * supplier who is refused can read why. Only the caller's own principal is
 * ever answered, so there is no id to scope.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-the-effective-grant-is-visible-and-writes-name-the-token
 */
class WhoamiController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request     The request.
	 * @param IUserSession     $userSession The calling user.
	 * @param TokenGrantSource $grants      The grant bound for this request, when a token carries one.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly TokenGrantSource $grants,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The calling principal and its effective grant.
	 *
	 * @return JSONResponse `{user, actorVia, grant, expired}`; 401 without a signed-in user.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-the-effective-grant-is-visible-and-writes-name-the-token
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$grant = $this->grants->current();
		$actorVia = null;
		if ($grant !== null && $grant->tokenId !== '') {
			$actorVia = $grant->tokenId;
		}

		return new JSONResponse(
			data: [
				'user' => $user->getUID(),
				'actorVia' => $actorVia,
				'grant' => $grant?->toArray(),
				'expired' => ($grant !== null && $grant->isExpired(now: new DateTimeImmutable()) === true),
			]
		);
	}//end show()
}//end class
