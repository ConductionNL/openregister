<?php

/**
 * The permission catalogue, and what the deny would do if it were enforcing.
 *
 * Three reads, and they answer the questions an administrator has before they
 * write a single rule:
 *
 *  - `GET /api/permissions` what can be granted here at all. Until this
 *    answered, a role editor had nothing to offer, which is why every consumer
 *    in the fleet invented its own vocabulary in its own screen.
 *  - `GET /api/permissions/deny-preview` what enforcement would refuse. The
 *    deny ships staged (D15), and this is the report an administrator reads
 *    before turning it on.
 *  - `GET /api/permissions/compare-roles` what one role can do that another
 *    cannot, against the catalogue, so a role that quietly acquired a verb is
 *    visible rather than diffed by eye.
 *
 * WHY THE PREVIEW READS THE RULES AND NOT A LOG. Accumulated observations
 * answer "what has fired", and stop there. The denies nobody has exercised yet
 * are missing from that answer, and those are precisely the ones that surprise
 * somebody on the day the switch is flipped. This endpoint reads the rules as
 * written, so a deny nobody has hit is in the report the day it is saved.
 *
 * Auth posture. `index()` is `#[NoAdminRequired]` and ungated: the catalogue
 * is a vocabulary, not a secret, and role editors read it.
 *
 * The three reports that DO disclose the authorization model - `denyPreview()`,
 * `scopeAudit()` and `compareRoles()` - moved to
 * {@see PermissionsAuditController}, which is admin-only throughout. Two auth
 * postures in one controller is a shape that invites getting one of them wrong.
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Publishes the grantable permission set and the staged deny report.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class PermissionsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string              $appName        Application identifier.
	 * @param IRequest            $request        Active HTTP request.
	 * @param PermissionCatalogue $catalogue      The grantable set.
	 * @param DenyEnforcementMode $enforcement    The staging switch.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PermissionCatalogue $catalogue,
		private readonly DenyEnforcementMode $enforcement,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()
	/**
	 * Every permission that may be granted on this instance.
	 *
	 * Response shape:
	 *
	 *   {
	 *     "permissions": [
	 *       {"verb": "read", "app": "openregister", "canonical": true,
	 *        "levels": ["register", "schema", "object"],
	 *        "description": "Open one object and read its contents."},
	 *       ...
	 *     ],
	 *     "denyEnforcement": "staging",
	 *     "rejectedDeclarations": {}
	 *   }
	 *
	 * `rejectedDeclarations` is reported rather than dropped: an app whose verb
	 * is missing from a role editor should be able to read why, instead of
	 * finding an empty selector and guessing.
	 *
	 * @return JSONResponse The catalogue.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'permissions' => array_values($this->catalogue->all()),
				'denyEnforcement' => $this->enforcement->current(),
				'rejectedDeclarations' => $this->catalogue->rejectedDeclarations(),
			]
		);
	}//end index()
}//end class
