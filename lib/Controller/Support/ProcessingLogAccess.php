<?php

/**
 * Who may query the processing log, and over which organisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The caller's access posture over the processing log.
 *
 * Admin or privacy officer (FG), or refused: fails closed. Admins query
 * unscoped; FG-only callers are tenant-scoped to their own organisation so
 * they can never read another tenant's log (no cross-tenant IDOR).
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class ProcessingLogAccess {
	/**
	 * The caller and their groups.
	 *
	 * @var GdprRegisterAccess
	 */
	private readonly GdprRegisterAccess $access;

	/**
	 * The refusal envelopes.
	 *
	 * @var GdprRegisterReplies
	 */
	private readonly GdprRegisterReplies $replies;

	/**
	 * Bundle the collaborators.
	 *
	 * @param ControllerSession            $session             Session, group manager and logger.
	 * @param ProcessingLogPolicy          $policy              The FG group setting.
	 * @param OrganisationServiceInterface $organisationService Org-scoping helper.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		ControllerSession $session,
		private readonly ProcessingLogPolicy $policy,
		private readonly OrganisationServiceInterface $organisationService,
	) {
		$this->access = new GdprRegisterAccess(session: $session);
		$this->replies = new GdprRegisterReplies();
	}//end __construct()

	/**
	 * Resolve the caller's access posture, or a denial response.
	 *
	 * @return JSONResponse|array{0: bool, 1: string|null} [isFg, organisationId] or a 401/403.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function resolve(): JSONResponse|array {
		$user = $this->access->user();
		if ($user === null) {
			return $this->replies->unauthorized();
		}

		$groups = $this->access->groupIds(user: $user);
		$isAdmin = in_array('admin', $groups, true);
		$isFg = in_array($this->policy->fgGroup(), $groups, true);

		if ($isAdmin === false && $isFg === false) {
			// Fail closed: neither admin nor privacy officer.
			return $this->replies->forbidden(message: 'Privacy-officer or admin privileges required');
		}

		return $this->scoped(isAdmin: $isAdmin, isFg: $isFg);
	}//end resolve()

	/**
	 * The posture of an admitted caller, scoped to their organisation unless admin.
	 *
	 * @param bool $isAdmin Whether the caller is an admin.
	 * @param bool $isFg    Whether the caller is in the FG group.
	 *
	 * @return JSONResponse|array{0: bool, 1: string|null} The posture, or an empty list.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function scoped(bool $isAdmin, bool $isFg): JSONResponse|array {
		$organisationId = null;
		if ($isAdmin === false) {
			$organisationId = $this->callerOrganisationId();
			if ($organisationId === null) {
				return new JSONResponse(data: ['count' => 0, 'results' => []]);
			}
		}

		return [$isFg || $isAdmin, $organisationId];
	}//end scoped()

	/**
	 * Active organisation uuid for a non-admin caller, or null.
	 *
	 * @return string|null The first organisation with a uuid.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	private function callerOrganisationId(): ?string {
		foreach ($this->organisationService->getUserOrganisations() as $organisation) {
			$uuid = (string)$organisation->getUuid();
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return null;
	}//end callerOrganisationId()
}//end class
