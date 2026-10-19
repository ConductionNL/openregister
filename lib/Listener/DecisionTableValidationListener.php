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
use OCP\AppFramework\Db\DoesNotExistException;
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
		$data = $object->getObject();
		if (is_array($data) === false) {
			$data = [];
		}

		$problems = $this->problemsFor(
			schema: $object->getSchema(),
			register: $object->getRegister(),
			data: $data,
			uuid: (string)$object->getUuid()
		);
		if ($problems === null || $problems === []) {
			return;
		}

		$this->refuse(event: $event, message: 'The decision table cannot be saved: ' . implode('; ', $problems) . '.');
	}//end validate()

	/**
	 * What stops a row from being stored as a decision table, for the single save and for the bulk path.
	 *
	 * The bulk writer dispatches no ObjectCreatingEvent, so it asks this directly: one rule, two entry points.
	 *
	 * @param string|null          $schema   The row's schema reference (id or slug).
	 * @param string|null          $register The row's register reference (id or slug).
	 * @param array<string, mixed> $data     The row's own properties.
	 * @param string               $uuid     The row's uuid, '' for a new row.
	 *
	 * @return array<int, string>|null Null when the row is no decision table, else the problems (empty when it is valid).
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function problemsFor(?string $schema, ?string $register, array $data, string $uuid): ?array {
		$isTable = $this->isDecisionTableRef(schema: $schema, register: $register);
		if ($isTable === false) {
			return null;
		}

		if ($isTable === null) {
			return ['The object could not be checked against the decision-table rules, so it was not saved: its schema or register could not be read'];
		}

		$problems = $this->validator->validate(table: $data);
		$key      = trim((string)($data['key'] ?? ''));
		$clash    = $this->keyTakenBy(key: $key, uuid: $uuid);
		if ($clash === null) {
			$problems[] = sprintf('the key "%s" could not be checked against the tables already stored', $key);
		} elseif ($clash !== '') {
			$problems[] = sprintf('the key "%s" is already used by decision table %s', $key, $clash);
		}

		return $problems;
	}//end problemsFor()

	/**
	 * Refuse the save.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The save event.
	 * @param string                                  $message Why.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $message): void {
		$event->setErrors(['code' => self::ERROR_CODE, 'message' => $message]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * Whether the object is a row of the decision-table schema.
	 *
	 * A schema or register that does not exist means the object is not a
	 * decision table. One that could not be read (a database error) means it
	 * cannot be told, and that answer is null: the guard must not wave a table
	 * through because it could not look.
	 *
	 * @param string|null $schema   The schema reference.
	 * @param string|null $register The register reference.
	 *
	 * @return bool|null True for a decision table, false for any other object, null when it cannot be told.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function isDecisionTableRef(?string $schema, ?string $register): ?bool {
		$reference = $schema;
		if ($reference === null || $reference === '') {
			return false;
		}

		try {
			$schema = $this->schemas->find($reference, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $unresolved) {
			return false;
		} catch (Throwable $unreadable) {
			return null;
		}

		if ($schema->getSlug() !== DecisionTableStore::SCHEMA) {
			return false;
		}

		// The slug alone is not the table: another app may still hold a schema
		// of the same slug in its own register (buildiq did until decision
		// 196), and its rows are not OpenRegister's tables to validate.
		return $this->inDecisionTableRegister(reference: $register);
	}//end isDecisionTableRef()

	/**
	 * Whether the object lives in the decision-tables register.
	 *
	 * @param string|null $reference The register reference.
	 *
	 * @return bool|null True when its register is `decision-tables`, null when the register could not be read.
	 */
	private function inDecisionTableRegister(?string $reference): ?bool {
		if ($reference === null || $reference === '') {
			return false;
		}

		try {
			$register = $this->registers->find($reference, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $unresolved) {
			return false;
		} catch (Throwable $unreadable) {
			return null;
		}

		return $register->getSlug() === DecisionTableStore::REGISTER;
	}//end inDecisionTableRegister()

	/**
	 * The uuid of another table already carrying the key, if any.
	 *
	 * @param string $key  The key being saved.
	 * @param string $uuid The uuid of the row being saved.
	 *
	 * @return string|null The other table's uuid, '' when the key is free, null when the search could not run.
	 */
	private function keyTakenBy(string $key, string $uuid): ?string {
		if ($key === '') {
			return '';
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

		return '';
	}//end keyTakenBy()
}//end class
