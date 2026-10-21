<?php

/**
 * Who may run an action on an object, and whether its precondition holds.
 *
 * There is no new permission model (ADR-023). The right is the action's own
 * entry in the schema's `authorization` block, checked per object, so a
 * conditional `match` rule ("you are the handler") answers without code.
 * The precondition is the declared `when`, JSONLogic over `{object, user,
 * inputs}`. The list and the run ask these same two questions, so a button is
 * never offered that the run would refuse for a reason known in advance.
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * The right and the precondition of an action.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression::isTrue() is the JSONLogic evaluator flows use;
 *   ObjectActionElevationScope is the request-scoped elevation frame.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
class ObjectActionGate {

	/**
	 * Constructor.
	 *
	 * @param PermissionHandler $permissions The authorization block's judge.
	 * @param IUserSession      $userSession The acting user.
	 * @param IL10N             $l10n        Picks the reason's language.
	 * @param ObjectActionElevationEntries $elevation Resolves an action's `elevate` entries.
	 */
	public function __construct(
		private readonly PermissionHandler $permissions,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly ObjectActionElevationEntries $elevation,
	) {
	}//end __construct()

	/**
	 * The acting user's id.
	 *
	 * @return string|null The uid, or null without a session.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function userId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end userId()

	/**
	 * Whether the acting user holds a right on this object.
	 *
	 * @param Schema            $schema The object's schema.
	 * @param string            $right  The action key, or a CRUD verb.
	 * @param ObjectEntity|null $object The object; null asks at schema level (owner-independent rules only).
	 *
	 * @return bool True when they do.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function may(Schema $schema, string $right, ?ObjectEntity $object): bool {
		return $this->permissions->hasPermission(
			schema: $schema,
			action: $right,
			userId: $this->userId(),
			objectOwner: $object?->getOwner(),
			_rbac: true,
			object: $object
		);
	}//end may()

	/**
	 * Whether the action's `when` holds for this object and these inputs.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param ObjectEntity           $object     The object.
	 * @param array<string, mixed>   $inputs     The inputs, defaults resolved.
	 *
	 * @return bool True when it holds, or when the action declares none.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function whenHolds(ObjectActionDefinition $definition, ObjectEntity $object, array $inputs = []): bool {
		if ($definition->when === null) {
			return true;
		}

		return FlowExpression::isTrue(
			logic: $definition->when,
			data: [
				'object' => ($object->getObject() ?? []),
				'user' => ['id' => $this->userId()],
				'inputs' => $inputs,
			]
		);
	}//end whenHolds()

	/**
	 * Run an action's body with its declared elevation open.
	 *
	 * Called only after the right and `when` have passed. Writes the person's
	 * rights refuse are let through as the system for the listed
	 * `(register, schema, verb)` only, and attributed to the action and person.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param callable               $operation  The body.
	 *
	 * @return mixed Whatever the body returned.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public function elevated(ObjectActionDefinition $definition, callable $operation): mixed {
		return ObjectActionElevationScope::run(
			action: $definition->key,
			person: $this->userId(),
			entries: $this->elevation->resolve(entries: $definition->elevate),
			operation: $operation
		);
	}//end elevated()

	/**
	 * The reason given when `when` does not hold, in the user's language.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
	 */
	public function reason(ObjectActionDefinition $definition): string {
		$message = $this->localised(value: $definition->whenMessage);
		if ($message !== '') {
			return $message;
		}

		return $this->l10n->t('This action is not available for this object right now.');
	}//end reason()

	/**
	 * A string, or the user's language from a locale map, or English, or the first.
	 *
	 * @param mixed $value A string or a locale map.
	 *
	 * @return string The text, or '' when there is none.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
	 */
	public function localised(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		if (is_array($value) === false || $value === []) {
			return '';
		}

		$language = substr($this->l10n->getLanguageCode(), 0, 2);

		return (string)($value[$language] ?? ($value['en'] ?? reset($value)));
	}//end localised()
}//end class
