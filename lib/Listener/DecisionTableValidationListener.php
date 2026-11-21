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
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Dmn\DecisionTableLocation;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidatorInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
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
class DecisionTableValidationListener implements IEventListener, DecisionTableValidationListenerInterface {
	use ValidatesPreWriteObject;

	/**
	 * The error code a refused save carries.
	 */
	public const ERROR_CODE = 'decision-table-invalid';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface           $schemas   Tells a decision-table row from any other.
	 * @param DecisionTableValidatorInterface $validator The validator the inline step uses.
	 * @param ObjectOperationsInterface          $objects   Finds a table already carrying the key.
	 * @param RegisterMapperInterface         $registers Tells the decision-tables register from another app's.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly DecisionTableValidatorInterface $validator,
		private readonly ObjectOperationsInterface $objects,
		private readonly RegisterMapperInterface $registers,
	) {
	}//end __construct()

	/**
	 * Refuse the save when the object is a decision table that cannot be stored.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The save event.
	 * @param ObjectEntity                            $object The object being saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	protected function validate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		$data = $this->writtenData(object: $object);
		$row = new ObjectRef(register: (string)$object->getRegister(), schema: (string)$object->getSchema(), id: (string)$object->getUuid());
		$problems = $this->problemsOf(row: $row, data: $data);
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
	 * @param ObjectRef            $row  The row's register and schema reference (id or slug, '' for none) and uuid ('' for a new row).
	 * @param array<string, mixed> $data The row's own properties.
	 *
	 * @return array<int, string>|null Null when the row is no decision table, else the problems (empty when it is valid).
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function problemsOf(ObjectRef $row, array $data): ?array {
		$isTable = $this->isDecisionTableRef(schema: (string)$row->schema, register: (string)$row->register);
		if ($isTable !== true) {
			return $this->uncheckedProblems(isTable: $isTable);
		}

		$problems = $this->validator->validate(table: $data);
		return array_merge($problems, $this->keyProblems(data: $data, uuid: $row->id));
	}//end problemsOf()

	/**
	 * The answer for a row that is no decision table, or that could not be told apart from one.
	 *
	 * @param bool|null $isTable False for another object, null when it could not be told.
	 *
	 * @return array<int, string>|null Null for another object, the one problem when it could not be told.
	 */
	private function uncheckedProblems(?bool $isTable): ?array {
		if ($isTable === false) {
			return null;
		}

		return ['The object could not be checked against the decision-table rules, so it was not saved: its schema or register could not be read'];
	}//end uncheckedProblems()

	/**
	 * What is wrong with the table's key: taken by another table, or not checkable.
	 *
	 * @param array<string, mixed> $data The row's own properties.
	 * @param string               $uuid The uuid of the row being saved.
	 *
	 * @return array<int, string> The problems, empty when the key is free.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	private function keyProblems(array $data, string $uuid): array {
		$key = trim((string)($data['key'] ?? ''));
		$clash = $this->keyTakenBy(key: $key, uuid: $uuid);
		if ($clash === null) {
			return [sprintf('the key "%s" could not be checked against the tables already stored', $key)];
		}

		if ($clash === '') {
			return [];
		}

		return [sprintf('the key "%s" is already used by decision table %s', $key, $clash)];
	}//end keyProblems()

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
		$find = fn (string $reference) => $this->schemas->find($reference, _rbac: false, _multitenancy: false);
		$slug = $this->slugOf(find: $find, reference: (string)$schema);
		if ($slug !== DecisionTableLocation::SCHEMA) {
			return $this->notATable(slug: $slug);
		}

		// The slug alone is not the table: another app may still hold a schema
		// of the same slug in its own register (buildiq did until decision
		// 196), and its rows are not OpenRegister's tables to validate.
		return $this->inDecisionTableRegister(reference: $register);
	}//end isDecisionTableRef()

	/**
	 * The answer for a schema that is not the decision-table one: false, or null when it could not be read.
	 *
	 * @param string|null $slug The schema's slug, null when it could not be read.
	 *
	 * @return bool|null
	 */
	private function notATable(?string $slug): ?bool {
		if ($slug === null) {
			return null;
		}

		return false;
	}//end notATable()

	/**
	 * Whether the object lives in the decision-tables register.
	 *
	 * @param string|null $reference The register reference.
	 *
	 * @return bool|null True when its register is `decision-tables`, null when the register could not be read.
	 */
	private function inDecisionTableRegister(?string $reference): ?bool {
		$find = fn (string $ref) => $this->registers->find($ref, _rbac: false, _multitenancy: false);
		$slug = $this->slugOf(find: $find, reference: (string)$reference);
		if ($slug === null) {
			return null;
		}

		return $slug === DecisionTableLocation::REGISTER;
	}//end inDecisionTableRegister()

	/**
	 * The slug of the schema or register a reference names.
	 *
	 * A reference that is empty or names nothing answers '': that is not a
	 * decision table. One that could not be read (a database error) answers
	 * null: it cannot be told, and the guard must not wave a table through
	 * because it could not look.
	 *
	 * @param callable  $find      Reads the schema or register a reference names.
	 * @param string    $reference The reference, '' when there is none.
	 *
	 * @return string|null The slug, '' when there is none, null when it could not be read.
	 */
	private function slugOf(callable $find, string $reference): ?string {
		if ($reference === '') {
			return '';
		}

		try {
			return (string)$find($reference)->getSlug();
		} catch (DoesNotExistException $unresolved) {
			return '';
		} catch (Throwable $unreadable) {
			return null;
		}
	}//end slugOf()

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

		$rows = $this->tablesWithKey(key: $key);
		if ($rows === null) {
			return null;
		}

		$others = array_filter($rows, static fn (ObjectEntity $entity): bool => (string)$entity->getUuid() !== $uuid);
		return $this->otherTableWithKey(others: $others, key: $key);
	}//end keyTakenBy()

	/**
	 * The stored tables the search finds under a key, or null when the search could not run.
	 *
	 * @param string $key The key being saved.
	 *
	 * @return array<int, ObjectEntity>|null The rows.
	 */
	private function tablesWithKey(string $key): ?array {
		try {
			$rows = $this->objects->searchObjectsBySlug(
				registerSlug: DecisionTableLocation::REGISTER,
				schemaSlug: DecisionTableLocation::SCHEMA,
				filters: ['key' => $key],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $unreadable) {
			return null;
		}

		return (array)$rows;
	}//end tablesWithKey()

	/**
	 * The uuid of the first of the other tables that carries the key, or ''.
	 *
	 * @param array<int, ObjectEntity> $others The rows the search found, minus the row being saved.
	 * @param string                   $key    The key being saved.
	 *
	 * @return string The other table's uuid, '' when there is none.
	 */
	private function otherTableWithKey(array $others, string $key): string {
		foreach ($others as $entity) {
			if ($this->carriesKey(data: $entity->getObject(), key: $key) === true) {
				return (string)$entity->getUuid();
			}
		}

		return '';
	}//end otherTableWithKey()

	/**
	 * Whether a row's data carries the key.
	 *
	 * @param array<string, mixed>|null $data The row's data.
	 * @param string                    $key  The key.
	 *
	 * @return bool
	 */
	private function carriesKey(?array $data, string $key): bool {
		return is_array($data) === true && trim((string)($data['key'] ?? '')) === $key;
	}//end carriesKey()
}//end class
