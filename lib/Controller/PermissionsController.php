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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\PermissionsControllerServices;
use OCA\OpenRegister\Service\ActionRightsCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementModeInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Publishes the grantable permission set and the staged deny report.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class PermissionsController extends Controller {

	/**
	 * The grantable set.
	 *
	 * @var PermissionCatalogueInterface
	 */
	private readonly PermissionCatalogueInterface $catalogue;

	/**
	 * The staging switch.
	 *
	 * @var DenyEnforcementModeInterface
	 */
	private readonly DenyEnforcementModeInterface $enforcement;

	/**
	 * The action rights, described.
	 *
	 * @var ActionRightsCatalogueInterface
	 */
	private readonly ActionRightsCatalogueInterface $actions;

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request  Active HTTP request.
	 * @param PermissionsControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		PermissionsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->catalogue = $services->catalogue;
		$this->enforcement = $services->enforcement;
		$this->actions = $services->actions;
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
	 *     "actions": [
	 *       {"action": "flow.node.send-email", "app": "openregister",
	 *        "description": "Put a step that sends e-mail in a flow.",
	 *        "nodeTypes": ["openregister.send-email"]},
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
	 * `actions` lists the action rights apart from the object verbs: an action is
	 * a grant to a person, not a verb on an object, and mixed into `permissions`
	 * an action name could reach an authorization block.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'permissions' => array_values($this->catalogue->all()),
				'actions' => $this->actions->actions(),
				'denyEnforcement' => $this->enforcement->current(),
				'rejectedDeclarations' => $this->catalogue->rejectedDeclarations(),
			]
		);
	}//end index()
}//end class
