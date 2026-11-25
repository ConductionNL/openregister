<?php

/**
 * OpenRegister Object Membership
 *
 * Adds an object to a Nextcloud collaboration collection or takes it out, for
 * a person who may update the object, and records the change on the object's
 * audit trail.
 *
 * @category Collaboration
 * @package  OCA\OpenRegister\Collaboration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-membership-is-changed-by-anyone-who-may-update-the-object-and-is-readable-from-it-req-pcr-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Collaboration;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectActivityAuditInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectUpdateRightsInterface;
use OCP\Collaboration\Resources\CollectionException;
use OCP\Collaboration\Resources\ICollection;
use OCP\Collaboration\Resources\IManager;
use OCP\Collaboration\Resources\ResourceException;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Changes which collections an object belongs to (D-2).
 *
 * A collection says what belongs together, which is a fact about the work, so
 * changing it asks the right to update the object, not only to read it. The
 * order is fixed: the schema must have opted in (D-3), the actor must hold
 * `update` on the object, and the collection must be one the actor may access.
 * Only then is the membership changed, and every change that happened writes
 * one entry on the object's audit trail naming the collection. A check that
 * cannot run refuses; it never grants.
 *
 * Nextcloud core's own collection endpoints ask the provider only whether the
 * object may be read, so this is the path that enforces update rights.
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-membership-is-changed-by-anyone-who-may-update-the-object-and-is-readable-from-it-req-pcr-002
 */
class ObjectMembership {

	/**
	 * The membership changed and the change is on the audit trail.
	 *
	 * @var string
	 */
	public const CHANGED = 'changed';

	/**
	 * Nothing to do: the object was already in (add) or not in (remove) the collection.
	 *
	 * @var string
	 */
	public const UNCHANGED = 'unchanged';

	/**
	 * The object's schema does not let its objects join collections.
	 *
	 * @var string
	 */
	public const NOT_JOINABLE = 'not-joinable';

	/**
	 * The actor may not update the object.
	 *
	 * @var string
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * No collection with that id that the actor may access.
	 *
	 * @var string
	 */
	public const NO_COLLECTION = 'no-collection';

	/**
	 * The audit action for an object added to a collection.
	 *
	 * @var string
	 */
	public const ACTION_ADDED = 'collection.added';

	/**
	 * The audit action for an object taken out of a collection.
	 *
	 * @var string
	 */
	public const ACTION_REMOVED = 'collection.removed';

	/**
	 * Constructor.
	 *
	 * @param IManager                    $manager  Nextcloud's collaboration resources.
	 * @param ObjectResourceProvider      $provider Says whether the object's schema joins collections.
	 * @param ObjectUpdateRightsInterface $rights   Answers whether the actor may update the object.
	 * @param ObjectActivityAuditInterface $audit   Writes the entry on the object's audit trail, as the signed-in user.
	 * @param LoggerInterface             $logger   Logs a check that could not run.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-membership-is-changed-by-anyone-who-may-update-the-object-and-is-readable-from-it-req-pcr-002
	 */
	public function __construct(
		private readonly IManager $manager,
		private readonly ObjectResourceProvider $provider,
		private readonly ObjectUpdateRightsInterface $rights,
		private readonly ObjectActivityAuditInterface $audit,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add the object to a collection.
	 *
	 * @param ObjectEntity $object       The object, already read by the actor.
	 * @param int          $collectionId The collection.
	 * @param IUser        $actor        The person adding it.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-membership-is-changed-by-anyone-who-may-update-the-object-and-is-readable-from-it-req-pcr-002
	 */
	public function add(ObjectEntity $object, int $collectionId, IUser $actor): string {
		return $this->change(object: $object, collectionId: $collectionId, actor: $actor, adding: true);
	}//end add()

	/**
	 * Take the object out of a collection.
	 *
	 * @param ObjectEntity $object       The object, already read by the actor.
	 * @param int          $collectionId The collection.
	 * @param IUser        $actor        The person removing it.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-membership-is-changed-by-anyone-who-may-update-the-object-and-is-readable-from-it-req-pcr-002
	 */
	public function remove(ObjectEntity $object, int $collectionId, IUser $actor): string {
		return $this->change(object: $object, collectionId: $collectionId, actor: $actor, adding: false);
	}//end remove()

	/**
	 * Run the checks in order, change the membership, and audit what changed.
	 *
	 * @param ObjectEntity $object       The object.
	 * @param int          $collectionId The collection.
	 * @param IUser        $actor        The actor.
	 * @param bool         $adding       True to add, false to remove.
	 *
	 * @return string One of the outcome constants.
	 */
	private function change(ObjectEntity $object, int $collectionId, IUser $actor, bool $adding): string {
		$resourceId = ObjectCollections::resourceId(object: $object);
		if ($resourceId === null || $this->provider->mayJoin(schemaId: (int)$object->getSchema()) === false) {
			return self::NOT_JOINABLE;
		}

		if ($this->mayUpdate(object: $object, actor: $actor) === false) {
			return self::FORBIDDEN;
		}

		try {
			$collection = $this->manager->getCollectionForUser($collectionId, $actor);
		} catch (CollectionException $e) {
			return self::NO_COLLECTION;
		}

		if ($this->isMember(collection: $collection, resourceId: $resourceId) === $adding) {
			return self::UNCHANGED;
		}

		$resource = $this->manager->createResource(ObjectResourceProvider::TYPE, $resourceId);
		try {
			if ($adding === true) {
				$collection->addResource($resource);
			} else {
				$collection->removeResource($resource);
			}
		} catch (ResourceException $e) {
			// Core refuses a duplicate add; the membership is what was asked for.
			return self::UNCHANGED;
		}

		$this->record(object: $object, collection: $collection, adding: $adding);

		return self::CHANGED;
	}//end change()

	/**
	 * Whether the actor holds `update` on the object; a check that cannot run says no.
	 *
	 * @param ObjectEntity $object The object.
	 * @param IUser        $actor  The actor.
	 *
	 * @return bool
	 */
	private function mayUpdate(ObjectEntity $object, IUser $actor): bool {
		try {
			return $this->rights->mayUpdateObject(uuid: (string)$object->getUuid(), userId: $actor->getUID());
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectMembership] The update right on an object could not be checked',
				context: ['file' => __FILE__, 'line' => __LINE__, 'object' => $object->getUuid(), 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end mayUpdate()

	/**
	 * Whether the collection already holds the object's resource.
	 *
	 * @param ICollection $collection The collection.
	 * @param string      $resourceId The object's resource id.
	 *
	 * @return bool
	 */
	private function isMember(ICollection $collection, string $resourceId): bool {
		foreach ($collection->getResources() as $resource) {
			if ($resource->getType() === ObjectResourceProvider::TYPE && $resource->getId() === $resourceId) {
				return true;
			}
		}

		return false;
	}//end isMember()

	/**
	 * Write the membership change on the object's audit trail.
	 *
	 * @param ObjectEntity $object     The object.
	 * @param ICollection  $collection The collection.
	 * @param bool         $adding     True when it was added.
	 *
	 * @return void
	 */
	private function record(ObjectEntity $object, ICollection $collection, bool $adding): void {
		$action = self::ACTION_REMOVED;
		if ($adding === true) {
			$action = self::ACTION_ADDED;
		}

		$this->audit->record(
			objectUuid: (string)$object->getUuid(),
			action: $action,
			context: ['collection' => ['id' => $collection->getId(), 'name' => $collection->getName()]]
		);
	}//end record()
}//end class
