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
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Follows an object for its new assignee.
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent>
 */
final class AssigneeFollowListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Resolves the object's schema and its roles.
	 * @param SemanticRoleHandler $roles Reads `x-openregister-role` from the schema.
	 * @param PermissionHandler $permissions The one RBAC evaluator, for the read check.
	 * @param IUserManager $userManager Tells a uid from a group id or a party reference.
	 * @param WatcherService $watchers The follow primitive.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly SemanticRoleHandler $roles,
		private readonly PermissionHandler $permissions,
		private readonly IUserManager $userManager,
		private readonly WatcherService $watchers,
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
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false && ($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$object = $event->getObject();
		$old = null;
		if ($event instanceof ObjectUpdatedEvent) {
			$object = $event->getNewObject();
			$old = $event->getOldObject();
		}

		try {
			$this->followNewAssignee(object: $object, old: $old);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[AssigneeFollowListener] assignee follow skipped: %s', $e->getMessage())
			);
		}

	}//end handle()

	/**
	 * Resolve the assignee role and follow for a changed, readable user.
	 *
	 * @param ObjectEntity $object The object as saved.
	 * @param ObjectEntity|null $old The object before the save, on an update.
	 *
	 * @return void
	 */
	private function followNewAssignee(ObjectEntity $object, ?ObjectEntity $old): void {
		$schemaId = (string)$object->getSchema();
		if ($schemaId === '' || (string)$object->getUuid() === '') {
			return;
		}

		$schema = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		$property = ($this->roles->roles(properties: $schema->getProperties())['assignee'] ?? null);
		if ($property === null) {
			return;
		}

		$assignee = $this->valueOf(object: $object, property: $property);
		if ($assignee === null || $assignee === $this->valueOf(object: $old, property: $property)) {
			return;
		}

		if ($this->userManager->userExists($assignee) === false) {
			return;
		}

		$mayRead = $this->permissions->hasPermission(
			schema: $schema,
			action: 'read',
			userId: $assignee,
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);
		if ($mayRead === false) {
			return;
		}

		$this->watchers->followAssigned(
			object: $object,
			userId: $assignee,
			register: (string)$object->getRegister(),
			schema: $schemaId
		);

	}//end followNewAssignee()

	/**
	 * The assignee property's value as a non-empty string, or null.
	 *
	 * @param ObjectEntity|null $object The object, or null when there is none.
	 * @param string $property The property holding the assignee role.
	 *
	 * @return string|null The value.
	 */
	private function valueOf(?ObjectEntity $object, string $property): ?string {
		if ($object === null) {
			return null;
		}

		$value = ($object->getObject()[$property] ?? null);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);

	}//end valueOf()
}//end class
