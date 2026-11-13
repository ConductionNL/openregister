<?php

/**
 * ActionRightsController: administrators read and change Open Register's action rights.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\AdministratorOnlyGate;
use OCA\OpenRegister\Service\ActionRightsCatalogueInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;
use OCA\OpenRegister\Service\Flow\IFlowNodeRequiresRight;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * `GET` and `PUT /api/settings/action-rights`: the action matrix, including the
 * rights flow steps need, and the administrator's map of node type to right.
 */
class ActionRightsController extends Controller {

	/**
	 * The 403 message for a caller who is not an administrator.
	 *
	 * @var string
	 */
	private const ADMIN_ONLY = 'Only administrators may read or change the action rights.';

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request    The request.
	 * @param AdministratorOnlyGate          $gate       Refuses a caller who is not an administrator.
	 * @param ActionRightsCatalogueInterface $catalogue  The action rights and their groups.
	 * @param FlowNodeRightsGuardInterface   $nodeRights The administrator's map of node type to right.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministratorOnlyGate $gate,
		private readonly ActionRightsCatalogueInterface $catalogue,
		private readonly FlowNodeRightsGuardInterface $nodeRights,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every action right with its groups, and the node map.
	 *
	 * @return JSONResponse `{actions: [{action, app, description, nodeTypes, groups}], nodeRights: {type: right|null}}`.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function index(): JSONResponse {
		return ($this->gate->refusal(forbidden: self::ADMIN_ONLY) ?? $this->current());
	}//end index()

	/**
	 * Change the groups of action rights and the node map.
	 *
	 * Body: `{groups?: {action: [group, ...]}, nodeRights?: {type: right|null}}`.
	 * The node map is written first, so a right it introduces can be granted in
	 * the same request. An unknown action or a malformed map is refused with 400
	 * naming it, and nothing is written.
	 *
	 * @return JSONResponse The rights as index() answers them, or 400.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function update(): JSONResponse {
		$refusal = $this->gate->refusal(forbidden: self::ADMIN_ONLY);
		if ($refusal !== null) {
			return $refusal;
		}

		$groups = $this->request->getParam('groups', []);
		$map    = $this->request->getParam('nodeRights');
		if (is_array($groups) === false) {
			return new JSONResponse(['error' => '"groups" must be an object of action to group ids.'], Http::STATUS_BAD_REQUEST);
		}

		$previousMap = $this->nodeRights->adminMap();
		try {
			if ($map !== null) {
				$this->nodeRights->setAdminMap(map: self::validMap(map: $map));
			}

			$this->catalogue->setGroups(groups: $groups);
		} catch (InvalidArgumentException $e) {
			$this->nodeRights->setAdminMap(map: $previousMap);
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return $this->current();
	}//end update()

	/**
	 * The rights and the node map.
	 *
	 * @return JSONResponse
	 */
	private function current(): JSONResponse {
		return new JSONResponse(
			[
				'actions' => $this->catalogue->actionsWithGroups(),
				'nodeRights' => (object) $this->nodeRights->adminMap(),
			]
		);
	}//end current()

	/**
	 * The node map as sent, refused when malformed.
	 *
	 * @param mixed $map The `nodeRights` parameter.
	 *
	 * @return array<string, string|null>
	 *
	 * @throws InvalidArgumentException Naming the entry that is not a node type mapped to a `flow.node.` right or null.
	 */
	private static function validMap(mixed $map): array {
		if (is_array($map) === false) {
			throw new InvalidArgumentException('"nodeRights" must be an object of node type to right.');
		}

		$valid = [];
		foreach ($map as $type => $right) {
			$isRight = (is_string($right) === true && str_starts_with($right, IFlowNodeRequiresRight::RIGHT_PREFIX) === true
				&& strlen($right) > strlen(IFlowNodeRequiresRight::RIGHT_PREFIX));
			if (is_string($type) === false || trim($type) === '' || ($right !== null && $isRight === false)) {
				throw new InvalidArgumentException(
					sprintf('Node type "%s" must map to a right starting with "%s", or to null.', (string) $type, IFlowNodeRequiresRight::RIGHT_PREFIX)
				);
			}

			$valid[$type] = $right;
		}

		return $valid;
	}//end validMap()
}//end class
