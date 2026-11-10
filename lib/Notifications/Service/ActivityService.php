<?php

/**
 * OpenRegister ActivityService.
 *
 * Service for publishing OpenRegister activity events to the Nextcloud activity stream.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\Activity\IManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing OpenRegister activity events.
 */
class ActivityService {
	/**
	 * Constructor.
	 *
	 * @param IManager $activityManager The activity manager.
	 * @param IUserSession $userSession The user session.
	 * @param ActivityEntries $entries Builds each activity.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 * @spec openspec/specs/activity-provider/spec.md#requirement-an-object-activity-names-the-schema-and-the-object
	 */
	public function __construct(
		private IManager $activityManager,
		private IUserSession $userSession,
		private ActivityEntries $entries,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish an activity event for a created object.
	 *
	 * @param ObjectEntity $object The created object entity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 * @spec openspec/specs/activity-provider/spec.md#requirement-an-object-activity-names-the-schema-and-the-object
	 */
	public function publishObjectCreated(ObjectEntity $object): void {
		$this->publish(entry: $this->entries->object(object: $object, action: 'created'));
	}//end publishObjectCreated()

	/**
	 * Publish an activity event for an updated object.
	 *
	 * @param ObjectEntity $newObject The updated object entity.
	 * @param ?ObjectEntity $oldObject The previous object entity state.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $oldObject reserved for future diff support
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 * @spec openspec/specs/activity-provider/spec.md#requirement-an-object-activity-names-the-schema-and-the-object
	 */
	public function publishObjectUpdated(ObjectEntity $newObject, ?ObjectEntity $oldObject = null): void {
		$this->publish(entry: $this->entries->object(object: $newObject, action: 'updated'));
	}//end publishObjectUpdated()

	/**
	 * Publish an activity event for a deleted object.
	 *
	 * @param ObjectEntity $object The deleted object entity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 * @spec openspec/specs/activity-provider/spec.md#requirement-an-object-activity-names-the-schema-and-the-object
	 */
	public function publishObjectDeleted(ObjectEntity $object): void {
		$this->publish(entry: $this->entries->object(object: $object, action: 'deleted'));
	}//end publishObjectDeleted()

	/**
	 * Publish an activity event for a created register.
	 *
	 * @param Register $register The created register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function publishRegisterCreated(Register $register): void {
		$this->publish(entry: $this->entries->register(register: $register, action: 'created'));
	}//end publishRegisterCreated()

	/**
	 * Publish an activity event for an updated register.
	 *
	 * @param Register $register The updated register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function publishRegisterUpdated(Register $register): void {
		$this->publish(entry: $this->entries->register(register: $register, action: 'updated'));
	}//end publishRegisterUpdated()

	/**
	 * Publish an activity event for a deleted register.
	 *
	 * @param Register $register The deleted register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function publishRegisterDeleted(Register $register): void {
		$this->publish(entry: $this->entries->register(register: $register, action: 'deleted'));
	}//end publishRegisterDeleted()

	/**
	 * Publish an activity event for a created schema.
	 *
	 * @param Schema $schema The created schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function publishSchemaCreated(Schema $schema): void {
		$this->publish(entry: $this->entries->schema(schema: $schema, action: 'created'));
	}//end publishSchemaCreated()

	/**
	 * Publish an activity event for an updated schema.
	 *
	 * @param Schema $schema The updated schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function publishSchemaUpdated(Schema $schema): void {
		$this->publish(entry: $this->entries->schema(schema: $schema, action: 'updated'));
	}//end publishSchemaUpdated()

	/**
	 * Publish an activity event for a deleted schema.
	 *
	 * @param Schema $schema The deleted schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function publishSchemaDeleted(Schema $schema): void {
		$this->publish(entry: $this->entries->schema(schema: $schema, action: 'deleted'));
	}//end publishSchemaDeleted()

	/**
	 * Publish an activity, and log a failure instead of failing the write it follows.
	 *
	 * @param ActivityEntry $entry The activity.
	 *
	 * @return void
	 */
	private function publish(ActivityEntry $entry): void {
		try {
			$this->deliver(entry: $entry);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to publish OpenRegister activity',
				['subject' => $entry->subject(), 'type' => $entry->type(), 'exception' => $e->getMessage()]
			);
		}
	}//end publish()

	/**
	 * Send an activity to the author, or in system context to the owner, and to an owner who is not the author.
	 *
	 * Without either there is nobody to tell.
	 *
	 * @param ActivityEntry $entry The activity.
	 *
	 * @return void
	 */
	private function deliver(ActivityEntry $entry): void {
		$author = (string)$this->userSession->getUser()?->getUID();
		foreach ($this->receivers(author: $author, owner: (string)$entry->about->owner) as $receiver) {
			$this->activityManager->publish($entry->fill(event: $this->activityManager->generateEvent(), author: $author)->setAffectedUser($receiver));
		}
	}//end deliver()

	/**
	 * The distinct users to tell.
	 *
	 * @param string $author The acting user, empty in system context.
	 * @param string $owner  The entity's owner, empty when it has none.
	 *
	 * @return list<string>
	 */
	private function receivers(string $author, string $owner): array {
		return array_values(array_unique(array_filter([$author, $owner], static fn (string $user): bool => $user !== '')));
	}//end receivers()
}//end class
