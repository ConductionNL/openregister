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
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolverInterface;
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
	 * @param PermissionHandlerInterface $permissions The authorization block's judge, and its app matrix rows.
	 * @param IUserSession      $userSession The acting user.
	 * @param IL10N             $l10n        Picks the reason's language.
	 * @param ObjectActionElevationEntries $elevation Resolves an action's `elevate` entries.
	 */
	public function __construct(
		private readonly PermissionHandlerInterface $permissions,
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
	 * Whether the caller may run this action on the object.
	 *
	 * Every kind but a self-assign is decided by may() on its right. A
	 * self-assign runs only when the schema grants it by name, in its
	 * authorization block or in a declared or app-provided matrix row; without
	 * either nobody may run it, also on a schema whose empty block would
	 * otherwise default open. Its right is then checked without the object
	 * owner's bypass: owning an object does not make one its assignee.
	 *
	 * @param Schema                 $schema     The object's schema.
	 * @param string                 $right      The right a non-self-assign action needs.
	 * @param ObjectActionDefinition $definition The action.
	 * @param ObjectEntity|null      $object     The object, or null for a selection.
	 *
	 * @return bool True when the caller may run it.
	 *
	 * @spec openspec/changes/declared-self-assign-action/specs/declared-actions/spec.md
	 */
	public function mayRun(Schema $schema, string $right, ObjectActionDefinition $definition, ?ObjectEntity $object): bool {
		if ($definition->selfAssignField() === null) {
			return $this->may(schema: $schema, right: $right, object: $object);
		}

		return $this->selfAssignNamed(schema: $schema, key: $definition->key)
			&& $this->permissions->hasPermission(
				schema: $schema,
				action: $definition->key,
				userId: $this->userId(),
				objectOwner: null,
				_rbac: true,
				object: $this->withoutOwnership(object: $object)
			);
	}//end mayRun()

	/**
	 * The object as the permission check sees it for a self-assign: without its owner or owning group.
	 *
	 * A private-scope object admits its owner and its owning group for every
	 * action; for a self-assign that would let owning the object grant it.
	 *
	 * @param ObjectEntity|null $object The object, or null for a selection.
	 *
	 * @return ObjectEntity|null A copy without owner and owning group.
	 */
	private function withoutOwnership(?ObjectEntity $object): ?ObjectEntity {
		if ($object === null) {
			return null;
		}

		$probe = clone $object;
		$probe->setOwner(null);
		$authorization = $probe->getAuthorization();
		if (is_array($authorization) === true) {
			unset($authorization[ObjectScopeResolverInterface::OWNER_GROUP_KEY]);
			$probe->setAuthorization($authorization);
		}

		return $probe;
	}//end withoutOwnership()

	/**
	 * Whether the schema names this self-assign action in a rule or in a matrix row, its app rows included.
	 *
	 * @param Schema $schema The schema.
	 * @param string $key    The action key.
	 *
	 * @return bool True when a rule or a row grants it by name.
	 */
	private function selfAssignNamed(Schema $schema, string $key): bool {
		$authorization = ($schema->getAuthorization() ?? []);
		if (empty($authorization[$key]) === false) {
			return true;
		}

		$matrix = ($authorization[DepartmentMatrixCompiler::KEY] ?? null);
		if (is_array($matrix) === false) {
			return false;
		}

		return $this->rowsName(rows: ($this->permissions->matrixRowsFromApps()->withAppRows(schema: $schema, matrix: $matrix)['rows'] ?? null), key: $key);
	}//end selfAssignNamed()

	/**
	 * Whether a self-assign action's property is still empty on this object.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param ObjectEntity           $object     The object.
	 *
	 * @return bool True when the property is empty, or the action is not a self-assign.
	 *
	 * @spec openspec/changes/declared-self-assign-action/specs/declared-actions/spec.md
	 */
	public function selfAssignOpen(ObjectActionDefinition $definition, ObjectEntity $object): bool {
		$field = $definition->selfAssignField();
		if ($field === null) {
			return true;
		}

		$value = (($object->getObject() ?? [])[$field] ?? null);

		return in_array($value, [null, '', []], true);
	}//end selfAssignOpen()

	/**
	 * Whether any declared matrix row grants this action.
	 *
	 * @param mixed  $rows The declared rows.
	 * @param string $key  The action key.
	 *
	 * @return bool True when a row names it.
	 */
	private function rowsName(mixed $rows, string $key): bool {
		if (is_array($rows) === false) {
			return false;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$actions = array_map(static fn ($action): string => trim((string)$action), (array)($row['actions'] ?? []));
			if (in_array($key, $actions, true) === true) {
				return true;
			}
		}

		return false;
	}//end rowsName()

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
