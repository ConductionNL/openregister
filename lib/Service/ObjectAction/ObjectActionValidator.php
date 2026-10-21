<?php

/**
 * Refuses a schema whose declared actions cannot be saved.
 *
 * Three layers, each asked once, at schema save time rather than in front of
 * the person who presses the button:
 *
 * - the shape of every declaration ({@see ObjectActionDefinition::refusals()});
 * - the nodes inline `steps` name ({@see ObjectActionStepsFlow});
 * - the flow a `flow` body binds to ({@see MacroActionValidator});
 * - who may declare `elevate`: an administrator, or the system during an
 *   import that runs without a session.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\MacroActionValidator;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Validates the declared actions of a schema being saved.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionDefinition's readers are stateless declaration readers.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
class ObjectActionValidator {

	/**
	 * Constructor.
	 *
	 * @param MacroActionValidator $macros       Verifies the flows `flow` bodies bind to.
	 * @param IUserSession         $userSession  The saver.
	 * @param IGroupManager        $groupManager Whether the saver is an administrator.
	 * @param ObjectActionStepsFlow|null $steps  Checks inline steps against the node registry.
	 */
	public function __construct(
		private readonly MacroActionValidator $macros,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ?ObjectActionStepsFlow $steps = null,
	) {
	}//end __construct()

	/**
	 * Every refusal the declared actions of this schema earn.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return list<string> The refusals; empty when every action may be saved.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public function refusals(Schema $schema): array {
		$configuration = ($schema->getConfiguration() ?? []);

		$refusals = ObjectActionDefinition::refusals(
			configuration: $configuration,
			authorization: ($schema->getAuthorization() ?? [])
		);
		if ($refusals !== []) {
			return $refusals;
		}

		$refusals = $this->elevationRefusals(configuration: $configuration);
		if ($refusals !== []) {
			return $refusals;
		}

		$refusals = ($this->steps?->refusals(configuration: $configuration) ?? []);
		if ($refusals !== []) {
			return $refusals;
		}

		return $this->macros->refusals(configuration: $configuration);
	}//end refusals()

	/**
	 * Refuse `elevate` saved by a signed-in user who is not an administrator.
	 *
	 * A save without a session is an import run by the system (app install,
	 * repair step, occ), which is the administrator's own act.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return list<string> The refusals.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	private function elevationRefusals(array $configuration): array {
		$elevating = [];
		foreach (ObjectActionDefinition::declarations(configuration: $configuration) as $key => $definition) {
			if (array_key_exists('elevate', $definition) === true) {
				$elevating[] = $key;
			}
		}

		if ($elevating === []) {
			return [];
		}

		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === true) {
			return [];
		}

		return array_map(
			static fn (string $key): string => sprintf('Action "%s": only an administrator may declare "elevate".', $key),
			$elevating
		);
	}//end elevationRefusals()
}//end class
