<?php

/**
 * RegisterDescriptorController — the register inventory over HTTP.
 *
 * Backs the admin panel that reports which app-declared registers landed on this
 * instance and re-imports one on demand. Both endpoints are administrator-only:
 * the inventory names every installed app and the state of its data model, and
 * the import rewrites schema definitions instance-wide.
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
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\RegisterDescriptorService;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the register-descriptor inventory and the forced re-import.
 */
class RegisterDescriptorController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                    $appName     The app id.
	 * @param IRequest                  $request     The current request.
	 * @param RegisterDescriptorService $descriptors The inventory and importer.
	 * @param IUserSession              $userSession Resolves the caller.
	 * @param IGroupManager             $groupManager Answers whether they are an administrator.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RegisterDescriptorService $descriptors,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every register any installed app declares, and whether it landed.
	 *
	 * @return JSONResponse The inventory, or a refusal.
	 *
	 * 🔴 THE no-admin-required ANNOTATION IS DELIBERATELY ABSENT, and is not
	 * spelled here either — a docblock that writes the literal tag DECLARES it,
	 * so a comment explaining its absence would restore exactly the mismatch it
	 * describes. (Learned the hard way: a comment about removing `@covers` was
	 * parsed as `@covers` and reddened six CI cells.)
	 *
	 * The body calls requireAdmin(), and an annotation saying the opposite is not
	 * a harmless disagreement: it is how an endpoint comes to be reachable by
	 * everyone while its code reads as guarded. With the tag absent, Nextcloud's
	 * middleware requires an administrator before the controller runs, and
	 * requireAdmin() then answers a proper JSON 403 rather than a middleware
	 * redirect. Two agreeing layers, not one cancelling the other.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
	 */
	public function index(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$rows   = $this->descriptors->inventory();
		$absent = 0;
		$behind = 0;
		foreach ($rows as $row) {
			if ($row['state'] === RegisterDescriptorService::STATE_ABSENT) {
				$absent++;
				continue;
			}

			if ($row['state'] === RegisterDescriptorService::STATE_BEHIND) {
				$behind++;
			}
		}

		return new JSONResponse(
			data: [
				'results' => $rows,
				'total'   => count($rows),
				'absent'  => $absent,
				'behind'  => $behind,
			]
		);
	}//end index()

	/**
	 * Force a re-import of one app's descriptor for one register slug.
	 *
	 * The outcome is the response body, not a log line. An operation an
	 * administrator just triggered that reports nothing is indistinguishable
	 * from one that did nothing.
	 *
	 * @param string $appId The app that ships the descriptor.
	 * @param string $slug  The register slug within it.
	 *
	 * @return JSONResponse The outcome, or a refusal.
	 *
	 * The no-admin-required tag is absent here too — see index() for why it is
	 * not spelled out. This endpoint rewrites schema definitions instance-wide,
	 * so the mismatch would have cost more here.
	 *
	 * 🔴 AND CSRF STAYS ON. The obvious way to satisfy gate-5's "declare an auth
	 * posture" is a no-CSRF tag, which index() already carries as a GET. Reaching
	 * for it here would disable CSRF protection on a state-changing POST to buy a
	 * green gate — the panel posts through axios with Nextcloud's request token,
	 * so it needs nothing of the sort. `AuthorizedAdminSetting` declares the
	 * posture the body actually enforces, which is what both gates are asking
	 * for. Bound to OpenRegisterAdmin, the panel this endpoint serves.
	 *
	 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function import(string $appId, string $slug): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->descriptors->reimport(appId: $appId, slug: $slug);
		if ($result['outcome'] === 'failed') {
			return new JSONResponse(data: $result, statusCode: 422);
		}

		return new JSONResponse(data: $result);
	}//end import()

	/**
	 * Refuse a caller who is not an administrator.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				data: ['error' => 'Forbidden: register descriptors are admin-only'],
				statusCode: 403
			);
		}

		return null;
	}//end requireAdmin()
}//end class
