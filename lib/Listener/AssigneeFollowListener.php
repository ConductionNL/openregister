<?php

/**
 * AssigneeFollowListener: being assigned an object means following it.
 *
 * Assignment is generic in OpenRegister: a schema marks the property that
 * names the assignee with `x-openregister-role: assignee` (SemanticRoleHandler).
 * So the rule "an assignee follows, with notifications on" lives here, once,
 * rather than in every leaf app that assigns things (`merge-follow-and-favourites`
 * design D-6).
 *
 * WHAT IT DOES NOT DO. It does not unfollow the previous assignee: losing a
 * case is not losing interest in it, and unfollowing is one click. It does
 * not follow a value that is not a Nextcloud user (a group id, a party
 * reference). And it does not follow a user who may not read the object,
 * checked with the same fail-closed read check a mention uses, because a
 * follow is an audience and the audience is read-gated.
 *
 * Best-effort: a failed follow is logged and never fails the save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Listener\Support\AssigneeFollowServices;

/**
 * Follows an object for its new assignee.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent>
 */
final class AssigneeFollowListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface   $schemaMapper Resolves the object's schema and its roles.
	 * @param AssigneeFollowServices $follow Semantic roles, users and watchers.
	 * @param PermissionCheckInterface       $permissions  The one RBAC evaluator, for the read check.
	 * @param LoggerInterface         $logger       PSR logger.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly AssigneeFollowServices $follow,
		private readonly PermissionCheckInterface $permissions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Follow the object for its assignee when the assignee changed.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent) {
			$this->followSafely(object: $event->getNewObject(), old: $event->getOldObject());
			return;
		}

		if ($event instanceof ObjectCreatedEvent) {
			$this->followSafely(object: $event->getObject(), old: null);
		}

	}//end handle()

	/**
	 * Follow for the new assignee; a failure is logged and never travels back into the save.
	 *
	 * @param ObjectEntity      $object The object as saved.
	 * @param ObjectEntity|null $old    The object before the save, on an update.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
	 */
	private function followSafely(ObjectEntity $object, ?ObjectEntity $old): void {
		if ((string)$object->getSchema() === '' || (string)$object->getUuid() === '') {
			return;
		}

		try {
			$this->followNewAssignee(object: $object, old: $old);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[AssigneeFollowListener] assignee follow skipped: %s', $e->getMessage())
			);
		}

	}//end followSafely()

	/**
	 * Resolve the assignee role and follow for a changed, readable user.
	 *
	 * @param ObjectEntity $object The object as saved, with a schema and a uuid.
	 * @param ObjectEntity|null $old The object before the save, on an update.
	 *
	 * @return void
	 */
	private function followNewAssignee(ObjectEntity $object, ?ObjectEntity $old): void {
		$schemaId = (string)$object->getSchema();
		$run = new AssigneeFollowRun();
		$run->schema = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		$run->object = $object;
		$run->old = $old;
		$assignee = $this->changedAssignee(run: $run);
		if ($assignee === null || $this->mayRead(run: $run, userId: $assignee) === false) {
			return;
		}

		$this->follow->watchers->followAssigned(object: $object, userId: $assignee, register: (string)$object->getRegister(), schema: $schemaId);

	}//end followNewAssignee()

	/**
	 * The assignee the save set, when the schema has the role, the value changed and it names a Nextcloud user.
	 *
	 * @param AssigneeFollowRun $run The schema, the object as saved and as it was.
	 *
	 * @return string|null The new assignee's uid, or null.
	 */
	private function changedAssignee(AssigneeFollowRun $run): ?string {
		$property = ($this->follow->roles->roles(properties: $run->schema->getProperties())['assignee'] ?? null);
		if ($property === null) {
			return null;
		}

		$assignee = $this->valueOf(object: $run->object, property: $property);
		if ($this->isNewUser(assignee: $assignee, previous: $this->valueOf(object: $run->old, property: $property)) === false) {
			return null;
		}

		return $assignee;

	}//end changedAssignee()

	/**
	 * Whether the assignee value changed and names a Nextcloud user.
	 *
	 * @param string|null $assignee The value as saved.
	 * @param string|null $previous The value before the save.
	 *
	 * @return bool True for a changed value that is a user's uid.
	 */
	private function isNewUser(?string $assignee, ?string $previous): bool {
		return $assignee !== null && $assignee !== $previous && $this->follow->userManager->userExists($assignee) === true;
	}//end isNewUser()

	/**
	 * Whether the assignee may read the object, through the one RBAC evaluator.
	 *
	 * @param AssigneeFollowRun $run    The schema and the object as saved.
	 * @param string            $userId The assignee's uid.
	 *
	 * @return bool True when the read is allowed.
	 */
	private function mayRead(AssigneeFollowRun $run, string $userId): bool {
		$object = $run->object;
		$owner = $object->getOwner();

		return $this->permissions->hasPermission(schema: $run->schema, action: 'read', userId: $userId, objectOwner: $owner, _rbac: true, object: $object);

	}//end mayRead()

	/**
	 * The assignee property's value as a non-empty string, or null.
	 *
	 * @param ObjectEntity|null $object The object, or null when there is none.
	 * @param string $property The property holding the assignee role.
	 *
	 * @return string|null The value.
	 */
	private function valueOf(?ObjectEntity $object, string $property): ?string {
		$value = ($object?->getObject()[$property] ?? null);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);

	}//end valueOf()
}//end class
