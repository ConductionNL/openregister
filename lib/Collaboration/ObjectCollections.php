<?php

/**
 * OpenRegister ObjectCollections
 *
 * The Nextcloud collections an object belongs to, and the other members of
 * each that the reader may access (platform-collaboration-resources, task 1.5).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Collaboration
 * @package  OCA\OpenRegister\Collaboration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Collaboration;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Collaboration\Resources\ICollection;
use OCP\Collaboration\Resources\IManager;
use OCP\Collaboration\Resources\IResource;
use OCP\Collaboration\Resources\ResourceException;
use OCP\IUser;

/**
 * Reads an object's collections for one reader.
 *
 * The caller has already read the object through the object read path, so the
 * reader may see it. A collection the reader may not access is left out, and so
 * is any member of a collection they may not access: what a member is decides
 * the platform's provider for its type, never this class.
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
 */
class ObjectCollections {

	/**
	 * Constructor.
	 *
	 * @param IManager               $manager  Nextcloud's collaboration resources.
	 * @param ObjectResourceProvider $provider Says whether the object's schema joins collections.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
	 */
	public function __construct(
		private readonly IManager $manager,
		private readonly ObjectResourceProvider $provider,
	) {
	}//end __construct()

	/**
	 * The object's collections the reader may access, each with its other members.
	 *
	 * @param ObjectEntity $object The object, already read by the reader.
	 * @param IUser        $reader The reader.
	 *
	 * @return list<array{id: int, name: string, members: list<array<string, mixed>>}> The collections.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
	 */
	public function forObject(ObjectEntity $object, IUser $reader): array {
		$resourceId = self::resourceId(object: $object);
		if ($resourceId === null || $this->provider->mayJoin(schemaId: (int)$object->getSchema()) === false) {
			return [];
		}

		try {
			$resource = $this->manager->getResourceForUser(ObjectResourceProvider::TYPE, $resourceId, $reader);
		} catch (ResourceException $e) {
			return [];
		}

		$collections = [];
		foreach ($resource->getCollections() as $collection) {
			if ($collection->canAccess($reader) === false) {
				continue;
			}

			$collections[] = [
				'id' => $collection->getId(),
				'name' => $collection->getName(),
				'members' => $this->members(collection: $collection, self: $resource, reader: $reader),
			];
		}

		return $collections;
	}//end forObject()

	/**
	 * The object's resource id, `<registerId>/<schemaId>/<uuid>`, or null when it lacks one.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string|null The id.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
	 */
	public static function resourceId(ObjectEntity $object): ?string {
		$id = (string)$object->getRegister() . '/' . (string)$object->getSchema() . '/' . (string)$object->getUuid();
		if (ObjectResourceProvider::parse(resourceId: $id) === null) {
			return null;
		}

		return $id;
	}//end resourceId()

	/**
	 * The other members of a collection the reader may access, as rich objects.
	 *
	 * @param ICollection $collection The collection.
	 * @param IResource   $self       The object's own resource, left out.
	 * @param IUser       $reader     The reader.
	 *
	 * @return list<array<string, mixed>> The members.
	 */
	private function members(ICollection $collection, IResource $self, IUser $reader): array {
		$members = [];
		foreach ($collection->getResources() as $resource) {
			if ($resource->getType() === $self->getType() && $resource->getId() === $self->getId()) {
				continue;
			}

			if ($resource->canAccess($reader) === false) {
				continue;
			}

			$members[] = $resource->getRichObject();
		}

		return $members;
	}//end members()
}//end class
