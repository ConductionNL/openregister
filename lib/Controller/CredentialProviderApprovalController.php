<?php

/**
 * CredentialProviderApprovalController — administrators approve, reject and revoke declared providers.
 *
 * Three admin-only routes over DeclaredProviderDecisions. Nextcloud's
 * `AuthorizedAdminSetting` admits only administrators (and delegated admins of
 * the OpenRegister settings); the in-body administrator check then admits only
 * a real administrator, so a delegated admin cannot open a host to an app.
 * Nothing is written for a refused caller.
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
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Kernel\Contracts\AdminSettingDelegation;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\Credential\DeclaredProviderDecisionRefused;
use OCA\OpenRegister\Service\Credential\DeclaredProviderDecisions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The approval plane of app-declared credential providers.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */
class CredentialProviderApprovalController extends Controller {

	/**
	 * The 403 message for a caller who is not an administrator.
	 *
	 * @var string
	 */
	private const ADMIN_ONLY = 'Only administrators may decide on declared credential providers.';

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request     The request.
	 * @param IGroupManager             $groups      Tells a real administrator from anyone else.
	 * @param IUserSession              $userSession The deciding administrator.
	 * @param DeclaredProviderDecisions $decisions   Records the decision.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
	 */
	public function __construct(
		IRequest $request,
		private readonly IGroupManager $groups,
		private readonly IUserSession $userSession,
		private readonly DeclaredProviderDecisions $decisions,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/credentials/providers/{identifier}/approve — admit a pending, rejected or revoked declaration.
	 *
	 * @param string $identifier The `<appId>:<localId>` identifier.
	 *
	 * @return JSONResponse The new admission state, or the refusal.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
	 */
	#[AuthorizedAdminSetting(settings: AdminSettingDelegation::OPEN_REGISTER_ADMIN)]
	public function approve(string $identifier): JSONResponse {
		return $this->decide(identifier: $identifier, decision: DeclaredProviderDecisions::APPROVE);
	}//end approve()

	/**
	 * POST /api/credentials/providers/{identifier}/reject — keep a pending declaration unusable.
	 *
	 * @param string $identifier The `<appId>:<localId>` identifier.
	 *
	 * @return JSONResponse The new admission state, or the refusal.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
	 */
	#[AuthorizedAdminSetting(settings: AdminSettingDelegation::OPEN_REGISTER_ADMIN)]
	public function reject(string $identifier): JSONResponse {
		return $this->decide(identifier: $identifier, decision: DeclaredProviderDecisions::REJECT);
	}//end reject()

	/**
	 * POST /api/credentials/providers/{identifier}/revoke — make an admitted declaration unusable.
	 *
	 * @param string $identifier The `<appId>:<localId>` identifier.
	 *
	 * @return JSONResponse The new admission state, or the refusal.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
	 */
	#[AuthorizedAdminSetting(settings: AdminSettingDelegation::OPEN_REGISTER_ADMIN)]
	public function revoke(string $identifier): JSONResponse {
		return $this->decide(identifier: $identifier, decision: DeclaredProviderDecisions::REVOKE);
	}//end revoke()

	/**
	 * Refuse a non-administrator, else record the decision with the request's note.
	 *
	 * @param string $identifier The identifier.
	 * @param string $decision   The decision.
	 *
	 * @return JSONResponse
	 */
	private function decide(string $identifier, string $decision): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->groups->isAdmin($user->getUID()) === false) {
			return new JSONResponse(data: ['error' => self::ADMIN_ONLY], statusCode: 403);
		}

		$note = $this->request->getParam('note', '');
		if (is_string($note) === false) {
			$note = '';
		}

		try {
			$state = $this->decisions->decide(
				identifier: $identifier,
				decision: $decision,
				decidedBy: (string)$this->userSession->getUser()?->getUID(),
				note: $note
			);
		} catch (DeclaredProviderDecisionRefused $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getStatus());
		}

		return new JSONResponse(
			data: [
				'identifier' => $identifier,
				'status'     => $state['status'],
				'origin'     => $state['origin'],
			]
		);
	}//end decide()
}//end class
