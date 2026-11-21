<?php

/**
 * CommentsEntityListener
 *
 * Registers the "openregister" objectType with Nextcloud's Comments system,
 * and one entity collection per app that claims a register and schema, so a
 * comment on an app's object is filed under that app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Listener
 * @package   OCA\OpenRegister\Listener
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 * @spec openspec/changes/platform-comments-entity/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\CommentsCollectionCatalog;
use OCP\Comments\CommentsEntityEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CommentsEntityListener registers "openregister" as a comments entity type.
 *
 * When Nextcloud's Comments system dispatches CommentsEntityEvent, this listener
 * adds "openregister" with a validation closure that checks if the object UUID
 * exists in the MagicMapper, and one collection per claiming app whose closure
 * also requires the object to be of a pair that app claimed. Every closure
 * reads the object as the current user, so a collection never makes an
 * object readable that was not (D-3).
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @template-implements IEventListener<CommentsEntityEvent>
 */
class CommentsEntityListener implements IEventListener {

	/**
	 * Object entity mapper for validating object existence.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectEntityMapper;

	/**
	 * Logger for error reporting.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface $objectEntityMapper Mapper for object validation
	 * @param LoggerInterface $logger Logger for error reporting
	 * @param CommentsCollectionCatalog $collections The collections and the pairs each accepts
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		MagicMapperInterface $objectEntityMapper,
		LoggerInterface $logger,
		private readonly CommentsCollectionCatalog $collections,
	) {
		$this->objectEntityMapper = $objectEntityMapper;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Handle the CommentsEntityEvent.
	 *
	 * Registers "openregister" as an entity collection with a closure
	 * that validates whether the given object UUID exists.
	 *
	 * @param Event $event The event to handle
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 * @spec openspec/specs/object-interactions/spec.md
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.1
	 */
	public function handle(Event $event): void {
		if (($event instanceof CommentsEntityEvent) === false) {
			return;
		}

		$event->addEntityCollection(CommentsCollectionCatalog::LEGACY_COLLECTION, $this->objectExists(...));

		try {
			$names = $this->collections->names();
		} catch (Throwable $e) {
			$this->logger->warning(
				'CommentsEntityListener: the claiming apps could not be read; only the openregister comments collection is registered',
				['exception' => $e->getMessage()]
			);
			return;
		}

		foreach ($names as $name) {
			if ($name === CommentsCollectionCatalog::LEGACY_COLLECTION) {
				continue;
			}

			$this->addAppCollection(event: $event, name: $name);
		}
	}//end handle()

	/**
	 * Register one app's collection, unless another component already took the name.
	 *
	 * @param CommentsEntityEvent $event The comments entity event.
	 * @param string              $name  The app id the collection is named for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.1
	 */
	private function addAppCollection(CommentsEntityEvent $event, string $name): void {
		try {
			$event->addEntityCollection(
				$name,
				fn (string $objectUuid): bool => $this->claimedObjectExists(collection: $name, objectUuid: $objectUuid)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CommentsEntityListener: comments collection name already taken; skipped',
				['collection' => $name, 'exception' => $e->getMessage()]
			);
		}

	}//end addAppCollection()

	/**
	 * Whether an object exists, is readable, and is of a pair the collection's app claimed.
	 *
	 * @param string $collection The collection name.
	 * @param string $objectUuid The object uuid the comments are about.
	 *
	 * @return bool True when the collection accepts the object.
	 *
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.2
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.5
	 */
	private function claimedObjectExists(string $collection, string $objectUuid): bool {
		try {
			$object = $this->objectEntityMapper->find($objectUuid);
		} catch (\Exception $e) {
			$this->logger->debug(
				'Object UUID not found for comments entity: ' . $objectUuid,
				['collection' => $collection, 'exception' => $e]
			);
			return false;
		}

		return $this->collections->accepts(
			collection: $collection,
			registerId: (int)$object->getRegister(),
			schemaId: (int)$object->getSchema()
		);

	}//end claimedObjectExists()

	/**
	 * Whether an object with this uuid exists, so comments may be attached to it.
	 *
	 * @param string $objectUuid The object uuid the comments are about.
	 *
	 * @return bool True when the object is found.
	 */
	private function objectExists(string $objectUuid): bool {
		try {
			$this->objectEntityMapper->find($objectUuid);
			return true;
		} catch (\Exception $e) {
			$this->logger->debug(
				'Object UUID not found for comments entity: ' . $objectUuid,
				['exception' => $e]
			);
			return false;
		}
	}//end objectExists()
}//end class
