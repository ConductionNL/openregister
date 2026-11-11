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
class ObjectActionValidator implements ObjectActionValidatorInterface {

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

		$refusals = $this->definitionRefusals(schema: $schema, configuration: $configuration);
		if ($refusals !== []) {
			return $refusals;
		}

		$refusals = array_merge(
			$this->elevationRefusals(configuration: $configuration),
			$this->selfAssignFieldRefusals(schema: $schema)
		);
		if ($refusals !== []) {
			return $refusals;
		}

		return $this->stepAndMacroRefusals(configuration: $configuration);
	}//end refusals()

	/**
	 * The refusals of the declared actions themselves, read against the schema's authorization.
	 *
	 * @param Schema               $schema        The schema.
	 * @param array<string, mixed> $configuration Its configuration.
	 *
	 * @return array<int, string> The refusals.
	 */
	private function definitionRefusals(Schema $schema, array $configuration): array {
		return ObjectActionDefinition::refusals(
			configuration: $configuration,
			authorization: ($schema->getAuthorization() ?? [])
		);
	}//end definitionRefusals()

	/**
	 * The refusals of the actions' steps, or else of their macros.
	 *
	 * @param array<string, mixed> $configuration The schema's configuration.
	 *
	 * @return array<int, string> The refusals.
	 */
	private function stepAndMacroRefusals(array $configuration): array {
		$refusals = ($this->steps?->refusals(configuration: $configuration) ?? []);
		if ($refusals !== []) {
			return $refusals;
		}

		return $this->macros->refusals(configuration: $configuration);
	}//end stepAndMacroRefusals()

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

	/**
	 * Refusals for a self-assign action whose field the schema does not declare.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return list<string> The refusals.
	 *
	 * @spec openspec/changes/declared-self-assign-action/specs/declared-actions/spec.md
	 */
	private function selfAssignFieldRefusals(Schema $schema): array {
		$properties = ($schema->getProperties() ?? []);
		$refusals = [];
		foreach (ObjectActionDefinition::parse(configuration: ($schema->getConfiguration() ?? [])) as $key => $definition) {
			$field = $definition->selfAssignField();
			if ($field === null) {
				continue;
			}

			if (is_array($properties[$field] ?? null) === false) {
				$refusals[] = sprintf('Action "%s": "selfAssign" names "%s", which is not a property of this schema.', $key, $field);
				continue;
			}

			if (($properties[$field]['type'] ?? 'string') !== 'string') {
				$refusals[] = sprintf('Action "%s": "selfAssign" names "%s", which does not hold a string.', $key, $field);
			}
		}

		return $refusals;
	}//end selfAssignFieldRefusals()
}//end class
