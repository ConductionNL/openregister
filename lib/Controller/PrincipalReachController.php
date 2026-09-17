<?php

/**
 * PrincipalReachController — the reach listing and the one revocation act.
 *
 * Two routes in the order an administrator uses them: see everything the
 * principal can reach, then take it away. The listing comes first by
 * construction, because D-5 says the list is shown before anything is removed
 * and an act carried out from a list nobody read is the one this surface
 * exists to prevent.
 *
 * ADMIN ONLY, AND DELIBERATELY NOT `@NoAdminRequired`. The other data-subject
 * surfaces run as an ordinary handler because they are scoped to what that
 * handler may already read. This one answers about ANOTHER account's whole
 * access across the instance and then removes it, which is an administrator's
 * act, so the framework's admin gate is the right guard rather than an in-body
 * check.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Rbac\PrincipalReachService;
use OCA\OpenRegister\Service\Rbac\ReachRevocationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists and revokes everything one principal can reach.
 */
class PrincipalReachController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                 $appName    The app name.
	 * @param IRequest               $request    The request.
	 * @param PrincipalReachService  $reach      The listing.
	 * @param ReachRevocationService $revocation The one act.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PrincipalReachService $reach,
		private readonly ReachRevocationService $revocation,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * GET /api/rbac/reach/{principal} — everything this account can reach.
	 *
	 * Reads only. The entries carry the source of each grant and whether a
	 * revocation can remove it, so the retained half is visible before the act
	 * rather than discovered after it.
	 *
	 * @param string $principal The account to report on.
	 *
	 * @return JSONResponse The reach listing.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
	 */
	public function show(string $principal): JSONResponse {
		$principal = trim($principal);
		if ($principal === '') {
			return new JSONResponse(
				data: ['error' => 'principal is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $this->reach->listFor(principal: $principal));
	}//end show()

	/**
	 * POST /api/rbac/reach/{principal}/revoke — take all of it away, once.
	 *
	 * Body: `reason` (optional, recorded with the act).
	 *
	 * @param string $principal The account losing its reach.
	 *
	 * @return JSONResponse One record naming every grant removed and every one retained.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
	 */
	public function revoke(string $principal): JSONResponse {
		$principal = trim($principal);
		if ($principal === '') {
			return new JSONResponse(
				data: ['error' => 'principal is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: $this->revocation->revokeAll(
				principal: $principal,
				reason: trim((string)($this->request->getParam(key: 'reason') ?? ''))
			)
		);
	}//end revoke()
}//end class
