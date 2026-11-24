<?php

/**
 * Whether a journey may change one object through an `edit` link.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Form\JourneyDefinition;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use Throwable;

/**
 * The two questions an `edit` link asks of its journey.
 *
 * At mint: may this caller hand out a link that changes this object through
 * this journey? Only when they may read and update the object themselves, and
 * the journey fits it. At every use: does the journey still fit the object?
 * A journey fits when its mode is `update`, it writes, and every write targets
 * the object's own schema. Anything that cannot be read is a refusal: the
 * guard fails closed.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */
class LinkJourneyGuard {

	/**
	 * The mode of a journey that changes an existing object.
	 *
	 * @var string
	 */
	public const MODE_UPDATE = 'update';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface  $objects     Reads the object and the journey.
	 * @param SchemaMapperInterface      $schemas     Resolves a write's schema reference.
	 * @param PermissionHandlerInterface $permissions Asks the caller's update right on the object.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly SchemaMapperInterface $schemas,
		private readonly PermissionHandlerInterface $permissions,
	) {
	}//end __construct()

	/**
	 * Why the calling user may not mint an `edit` link over this object for this journey, or null.
	 *
	 * The object is read as the caller reads it, so a record they cannot read
	 * is refused here too; the update right is asked under the ordinary rules.
	 *
	 * @param string $objectUuid The link's subject object.
	 * @param string $journeyId  The journey the link opens.
	 *
	 * @return string|null The refusal, or null when the link may be minted.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-1
	 */
	public function mintRefusal(string $objectUuid, string $journeyId): ?string {
		$object = $this->read(id: $objectUuid, asCaller: true);
		if ($object === null) {
			return 'The record this link opens cannot be found.';
		}

		if ($this->mayUpdate(object: $object) === false) {
			return 'You may not change this record, so you cannot hand out a link that changes it.';
		}

		return $this->refusal(object: $object, journeyId: $journeyId);
	}//end mintRefusal()

	/**
	 * The journey, when it may change this object; null otherwise.
	 *
	 * @param ObjectEntity $object    The link's subject object.
	 * @param string       $journeyId The journey the link opens.
	 *
	 * @return JourneyDefinition|null The journey, or null when it does not fit.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-2
	 */
	public function journeyFor(ObjectEntity $object, string $journeyId): ?JourneyDefinition {
		$journey = $this->journey(journeyId: $journeyId);
		if ($journey === null || $this->fitRefusal(object: $object, journey: $journey) !== null) {
			return null;
		}

		return $journey;
	}//end journeyFor()

	/**
	 * Why this journey may not change this object, or null when it may.
	 *
	 * @param ObjectEntity $object    The object.
	 * @param string       $journeyId The journey object's uuid.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-1
	 */
	public function refusal(ObjectEntity $object, string $journeyId): ?string {
		$journey = $this->journey(journeyId: $journeyId);
		if ($journey === null) {
			return 'The journey this link names cannot be found.';
		}

		return $this->fitRefusal(object: $object, journey: $journey);
	}//end refusal()

	/**
	 * Why a read journey does not fit the object, or null.
	 *
	 * @param ObjectEntity      $object  The object.
	 * @param JourneyDefinition $journey The journey.
	 *
	 * @return string|null The refusal, or null.
	 */
	private function fitRefusal(ObjectEntity $object, JourneyDefinition $journey): ?string {
		if (($journey->body['mode'] ?? null) !== self::MODE_UPDATE) {
			return 'The journey this link names does not change an existing record: its mode is not update.';
		}

		$writes = [];
		foreach ($journey->stepIds() as $stepId) {
			$writes = array_merge($writes, $journey->writesOf(stepId: $stepId));
		}

		if ($writes === []) {
			return 'The journey this link names writes nothing.';
		}

		foreach ($writes as $write) {
			if ($this->schemaId(reference: $write['schema'] ?? null) !== (string)$object->getSchema()) {
				return 'The journey this link names changes records of another schema than the record this link opens.';
			}
		}

		return null;
	}//end fitRefusal()

	/**
	 * The journey object, read with the rules off; null when it is not a journey that can be read.
	 *
	 * @param string $journeyId The journey object's uuid.
	 *
	 * @return JourneyDefinition|null The journey.
	 */
	private function journey(string $journeyId): ?JourneyDefinition {
		$journeyId = trim($journeyId);
		if ($journeyId === '') {
			return null;
		}

		$entity = $this->read(id: $journeyId, asCaller: false);
		if ($entity === null) {
			return null;
		}

		return new JourneyDefinition(id: $journeyId, body: $entity->getObject());
	}//end journey()

	/**
	 * One object, as the caller reads it or with the rules off; null when absent, unreadable or deleted.
	 *
	 * @param string $id       The uuid.
	 * @param bool   $asCaller Read under the caller's own rules.
	 *
	 * @return ObjectEntity|null The object.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The two reads differ only in whose rules apply.
	 */
	private function read(string $id, bool $asCaller): ?ObjectEntity {
		try {
			$object = $this->objects->find(
				id: $id,
				options: new ObjectFindOptions(render: false, audit: false),
				_rbac: $asCaller,
				_multitenancy: $asCaller
			);
		} catch (Throwable) {
			return null;
		}

		if ($object instanceof ObjectEntity === false || $object->isSoftDeleted() === true) {
			return null;
		}

		return $object;
	}//end read()

	/**
	 * Whether the caller holds the update right on the object; false when it cannot be asked.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function mayUpdate(ObjectEntity $object): bool {
		try {
			$schema = $this->schemas->find(id: (string)$object->getSchema(), _multitenancy: false);

			return $this->permissions->hasPermission(
				ask: (new PermissionAsk(schema: $schema, action: 'update'))
					->askedBy(userId: null, objectOwner: $object->getOwner())
					->about(object: $object, register: null)
			);
		} catch (Throwable) {
			return false;
		}
	}//end mayUpdate()

	/**
	 * A write's schema reference (id, uuid or slug) as the schema's id; '' when it resolves to none.
	 *
	 * @param mixed $reference The reference.
	 *
	 * @return string The id.
	 */
	private function schemaId(mixed $reference): string {
		if (is_string($reference) === false && is_int($reference) === false) {
			return '';
		}

		try {
			return (string)$this->schemas->find(id: $reference, _multitenancy: false)->getId();
		} catch (Throwable) {
			return '';
		}
	}//end schemaId()
}//end class
