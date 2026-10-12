<?php

/**
 * OpenRegister DecisionTableValidationListener
 *
 * Refuses to store a named decision table the evaluator cannot execute, or a
 * second table under a key another table already carries.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * The save-time half of a stored decision table's contract.
 *
 * The inline flow step refuses a table it cannot run when the flow is saved.
 * A stored table gets the same refusal when the table is saved, through the
 * same validator, so a table that reaches a flow by reference was executable
 * when it was written. The store checks again on read, for a row written
 * around this listener (an import, a direct write).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
class DecisionTableValidationListener implements IEventListener {

	/**
	 * The error code a refused save carries.
	 */
	public const ERROR_CODE = 'decision-table-invalid';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper           $schemas   Tells a decision-table row from any other.
	 * @param DecisionTableValidator $validator The validator the inline step uses.
	 * @param ObjectService          $objects   Finds a table already carrying the key.
	 * @param RegisterMapper         $registers Tells the decision-tables register from another app's.
	 */
	public function __construct(
		private readonly SchemaMapper $schemas,
		private readonly DecisionTableValidator $validator,
		private readonly ObjectService $objects,
		private readonly RegisterMapper $registers,
	) {
	}//end __construct()

	/**
	 * Handle a create or update of any object; act only on decision tables.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->validate(event: $event, object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->validate(event: $event, object: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Refuse the save when the object is a decision table that cannot be stored.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The save event.
	 * @param ObjectEntity                            $object The object being saved.
	 *
	 * @return void
	 */
	private function validate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		if ($this->isDecisionTable(object: $object) === false) {
			return;
		}

		$data = $object->getObject();
		if (is_array($data) === false) {
			$data = [];
		}

		$problems = $this->validator->validate(table: $data);
		$clash    = $this->keyTakenBy(key: trim((string)($data['key'] ?? '')), uuid: (string)$object->getUuid());
		if ($clash !== null) {
			$problems[] = sprintf('the key "%s" is already used by decision table %s', (string)$data['key'], $clash);
		}

		if ($problems === []) {
			return;
		}

		$event->setErrors(
			[
				'code' => self::ERROR_CODE,
				'message' => 'The decision table cannot be saved: ' . implode('; ', $problems) . '.',
			]
		);
		$event->stopPropagation();
	}//end validate()

	/**
	 * Whether the object is a row of the decision-table schema.
	 *
	 * @param ObjectEntity $object The object being saved.
	 *
	 * @return bool True for a decision table.
	 */
	private function isDecisionTable(ObjectEntity $object): bool {
		$reference = $object->getSchema();
		if ($reference === null || $reference === '') {
			return false;
		}

		try {
			$schema = $this->schemas->find($reference, _rbac: false, _multitenancy: false);
		} catch (Throwable $unresolved) {
			return false;
		}

		if ($schema->getSlug() !== DecisionTableStore::SCHEMA) {
			return false;
		}

		// The slug alone is not the table: another app may still hold a schema
		// of the same slug in its own register (buildiq did until decision
		// 196), and its rows are not OpenRegister's tables to validate.
		return $this->inDecisionTableRegister(object: $object);
	}//end isDecisionTable()

	/**
	 * Whether the object lives in the decision-tables register.
	 *
	 * @param ObjectEntity $object The object being saved.
	 *
	 * @return bool True when its register is `decision-tables`.
	 */
	private function inDecisionTableRegister(ObjectEntity $object): bool {
		$reference = $object->getRegister();
		if ($reference === null || $reference === '') {
			return false;
		}

		try {
			$register = $this->registers->find($reference, _rbac: false, _multitenancy: false);
		} catch (Throwable $unresolved) {
			return false;
		}

		return $register->getSlug() === DecisionTableStore::REGISTER;
	}//end inDecisionTableRegister()

	/**
	 * The uuid of another table already carrying the key, if any.
	 *
	 * @param string $key  The key being saved.
	 * @param string $uuid The uuid of the row being saved.
	 *
	 * @return string|null The other table's uuid, or null when the key is free.
	 */
	private function keyTakenBy(string $key, string $uuid): ?string {
		if ($key === '') {
			return null;
		}

		try {
			$rows = $this->objects->searchObjectsBySlug(
				registerSlug: DecisionTableStore::REGISTER,
				schemaSlug: DecisionTableStore::SCHEMA,
				filters: ['key' => $key],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $unreadable) {
			return null;
		}

		foreach ((array)$rows as $entity) {
			$other = (string)$entity->getUuid();
			$data  = $entity->getObject();
			if ($other !== $uuid && is_array($data) === true && trim((string)($data['key'] ?? '')) === $key) {
				return $other;
			}
		}

		return null;
	}//end keyTakenBy()
}//end class
