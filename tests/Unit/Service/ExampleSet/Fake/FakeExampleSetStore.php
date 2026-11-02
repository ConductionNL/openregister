<?php

/**
 * In-memory register store for the example-set tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetStore;

/**
 * Keeps rows in memory, per register and schema.
 */
class FakeExampleSetStore extends ExampleSetStore {
	/** @var array<string, array<string, array<string, mixed>>> Rows by "register schema", then id. */
	public array $rows = [];

	/** @var array<string, bool> Offered "register schema" pairs. */
	public array $offered = [];

	/** @var array<string, array<int, string>> Field names a schema refuses, by "register schema". */
	public array $refusedFields = [];

	/** @var array<string, array<int, string>> Field names a schema silently drops, by "register schema". */
	public array $droppedFields = [];

	/** @var array<string, array<string, \Closure(mixed): mixed>> How a schema stores a field (a datetime in UTC, '' as null), by "register schema", then field. */
	public array $storedAs = [];

	/** @var array<string, array<int, string>> Fields a schema keeps as text (`string or null`), by "register schema". */
	public array $textFields = [];

	/** @var array<string, array<int, string>> Fields of format `user`, by "register schema". */
	public array $userFields = [];

	/** @var (\Closure(string): bool)|null Whether an account exists, for the `user` format; null: none does. */
	public ?\Closure $userExists = null;

	/** @var array<string, int> Register ids by slug. */
	public array $registerIds = [];

	/** @var array<string, int> Schema ids by "register/schema". */
	public array $schemaIds = [];

	/** @var (\Closure(string): bool)|null Whether a register is on the instance; null: every one with an id is. */
	public ?\Closure $registerExists = null;

	/** @var array<int, string> Ids whose delete fails. */
	public array $undeletable = [];

	/** @var int Number of creates. */
	public int $creates = 0;

	/** @var list<array{cause: string, run: string|null}> The write cause in effect at each create and update. */
	public array $causes = [];

	/** @var int Number of validations. */
	public int $validations = 0;

	/** @var array<string, array<int, string>> Fields a schema keeps as written (readOnly, immutable), by "register schema". */
	public array $keptFields = [];

	/** @var array<string, array<int, string>> Lens fields, resolved on read and refused on write, by "register schema". */
	public array $lensFields = [];

	/** @var array<string, bool> Append-only schemas, by "register schema". */
	public array $appendOnly = [];

	/** @var array<string, string> The owner of each row, by id. */
	public array $owners = [];

	/** @var int Number of owner hand-overs. */
	public int $ownerChanges = 0;

	/** @var array<string, array<int, string>> Fields a schema refuses null for, by "register schema"; reads give them null when unset. */
	public array $nonNullFields = [];

	/** @var int Next id number. */
	private int $next = 1;

	/**
	 * No container, no logger.
	 */
	public function __construct() {
	}

	/**
	 * Offer a schema.
	 *
	 * @param string $register Register slug.
	 * @param string $schema   Schema slug.
	 *
	 * @return void
	 */
	public function offer(string $register, string $schema): void {
		$this->offered[$register.' '.$schema] = true;
	}

	/**
	 * Put a row there that the set did not write.
	 *
	 * @param string               $register Register slug.
	 * @param string               $schema   Schema slug.
	 * @param array<string, mixed> $data     The row.
	 *
	 * @return string The id.
	 */
	public function seed(string $register, string $schema, array $data): string {
		$id = sprintf('00000000-0000-4000-8000-%012d', $this->next++);
		$this->rows[$register.' '.$schema][$id] = (['id' => $id] + $data);

		return $id;
	}

	public function registerId(string $register): ?int {
		if ($this->registerExists !== null && ($this->registerExists)($register) === false) {
			return null;
		}

		return ($this->registerIds[$register] ?? null);
	}

	public function schemaId(string $register, string $schema): ?int {
		if ($this->registerId(register: $register) === null) {
			return null;
		}

		return ($this->schemaIds[$register.'/'.$schema] ?? null);
	}

	public function offers(string $register, string $schema): bool {
		return isset($this->offered[$register.' '.$schema]);
	}

	/**
	 * Refuses what the real validator refuses for the fields named above,
	 * with OpenRegister's own wording.
	 */
	public function validate(string $register, string $schema, array $data, array $plannedUsers = []): array {
		$this->validations++;
		if ($this->offers(register: $register, schema: $schema) === false) {
			return ['this instance has no schema "'.$schema.'" in a register "'.$register.'"'];
		}

		$refusals = [];
		foreach (($this->refusedFields[$register.' '.$schema] ?? []) as $field) {
			if (array_key_exists($field, $data) === true) {
				$refusals[] = 'The property '.$field.' is not allowed';
			}
		}

		return array_merge($refusals, $this->typeRefusals(type: $register.' '.$schema, data: $data, plannedUsers: $plannedUsers));
	}

	/**
	 * Text fields that hold something else, and user fields naming nobody.
	 *
	 * @param string               $type         "register schema".
	 * @param array<string, mixed> $data         The object.
	 * @param array<int, string>   $plannedUsers Account ids accepted although they do not exist.
	 *
	 * @return array<int, string>
	 */
	private function typeRefusals(string $type, array $data, array $plannedUsers): array {
		$refusals = [];
		foreach (($this->textFields[$type] ?? []) as $field) {
			if (array_key_exists($field, $data) === true && is_string($data[$field]) === false && $data[$field] !== null) {
				$refusals[] = "Property '".$field."' should be type 'string or null' but is '".gettype($data[$field])."'.";
			}
		}

		foreach (($this->userFields[$type] ?? []) as $field) {
			$uid = ($data[$field] ?? null);
			if ($uid === null) {
				continue;
			}

			$exists = ($this->userExists !== null && is_string($uid) === true && ($this->userExists)($uid) === true);
			if ($exists === false && in_array($uid, $plannedUsers, true) === false) {
				$refusals[] = "Property '".$field."' should match format 'user' but '".(is_string($uid) ? $uid : json_encode($uid))."' does not.";
			}
		}

		return $refusals;
	}

	public function textProperties(string $register, string $schema): array {
		return ($this->textFields[$register.' '.$schema] ?? []);
	}

	public function find(string $register, string $schema, array $filters): array {
		return array_values($this->rows[$register.' '.$schema] ?? []);
	}

	/** @var array<int, string> Ids whose read fails with an error, as a database that is down would. */
	public array $unreadable = [];

	public function get(string $register, string $schema, string $id): ?array {
		if (in_array($id, $this->unreadable, true) === true) {
			return null;
		}

		return $this->withLenses(type: $register.' '.$schema, row: ($this->rows[$register.' '.$schema][$id] ?? null));
	}

	public function read(string $register, string $schema, string $id): array {
		if (in_array($id, $this->unreadable, true) === true) {
			return ['row' => null, 'error' => 'database is gone'];
		}

		return ['row' => $this->withLenses(type: $register.' '.$schema, row: ($this->rows[$register.' '.$schema][$id] ?? null)), 'error' => ''];
	}

	public function create(string $register, string $schema, array $data, ?string $owner = null): array {
		if ($this->offers(register: $register, schema: $schema) === false) {
			return ['id' => '', 'error' => 'no such schema'];
		}

		// A save validates too, as a schema with hard validation does: no planned accounts there.
		$refused = $this->typeRefusals(type: $register.' '.$schema, data: $data, plannedUsers: []);
		if ($refused !== []) {
			return ['id' => '', 'error' => implode(' ', $refused)];
		}

		$this->creates++;
		$this->causes[] = \OCA\OpenRegister\Service\WriteCause::current();
		foreach (($this->droppedFields[$register.' '.$schema] ?? []) as $field) {
			unset($data[$field]);
		}

		$id = $this->seed(register: $register, schema: $schema, data: $this->asStored(type: $register.' '.$schema, data: $data));
		$this->owners[$id] = ($owner ?? '__system__');

		return ['id' => $id, 'error' => ''];
	}

	/** @var int Number of updates. */
	public int $updates = 0;

	public function update(string $register, string $schema, string $id, array $data): string {
		if (isset($this->rows[$register.' '.$schema][$id]) === false) {
			return 'no such row';
		}

		$refused = $this->typeRefusals(type: $register.' '.$schema, data: $data, plannedUsers: []);
		if ($refused !== []) {
			return implode(' ', $refused);
		}

		$refused = $this->writeRefusal(type: $register.' '.$schema, stored: $this->rows[$register.' '.$schema][$id], data: $data);
		if ($refused !== '') {
			return $refused;
		}

		$this->updates++;
		$this->causes[] = \OCA\OpenRegister\Service\WriteCause::current();
		unset($data['@self'], $data['id']);
		$this->rows[$register.' '.$schema][$id] = (['id' => $id] + $this->asStored(type: $register.' '.$schema, data: $data));

		return '';
	}

	/**
	 * The data as the schema stores it, per $storedAs.
	 *
	 * @param string               $type "register schema".
	 * @param array<string, mixed> $data The data sent.
	 *
	 * @return array<string, mixed>
	 */
	public function ownerOf(string $register, string $schema, string $id): ?string {
		return ($this->owners[$id] ?? null);
	}

	public function assignOwner(string $id, string $owner): string {
		if (isset($this->owners[$id]) === false) {
			return 'no such row';
		}

		$this->owners[$id] = $owner;
		$this->ownerChanges++;

		return '';
	}

	public function writeRules(string $register, string $schema): array {
		$type = $register.' '.$schema;

		return ['appendOnly' => ($this->appendOnly[$type] ?? false), 'kept' => ($this->keptFields[$type] ?? []), 'lenses' => ($this->lensFields[$type] ?? [])];
	}

	/**
	 * What OpenRegister refuses on an update, in its wording: any update of an append-only schema, a kept field
	 * sent with another value than stored, and a lens field sent at all.
	 *
	 * @param string               $type   "register schema".
	 * @param array<string, mixed> $stored The row as stored.
	 * @param array<string, mixed> $data   The update.
	 *
	 * @return string
	 */
	private function writeRefusal(string $type, array $stored, array $data): string {
		if (($this->appendOnly[$type] ?? false) === true) {
			return 'SCHEMA_APPEND_ONLY: Schema "'.$type.'" is append-only; update operations are not permitted.';
		}

		foreach (($this->keptFields[$type] ?? []) as $field) {
			if (array_key_exists($field, $data) === true && $data[$field] !== ($stored[$field] ?? null)) {
				return 'Cannot modify readOnly property: '.$field;
			}
		}

		foreach (($this->nonNullFields[$type] ?? []) as $field) {
			if (array_key_exists($field, $data) === true && $data[$field] === null) {
				return "Property '".$field."' should be type 'string' but is 'null'.";
			}
		}

				$lenses = array_values(array_intersect(($this->lensFields[$type] ?? []), array_keys($data)));
		if ($lenses !== []) {
			return 'Cannot write lens property: '.implode(', ', $lenses);
		}

		return '';
	}

	/**
	 * A row as read: with a value for every lens field.
	 *
	 * @param string                    $type "register schema".
	 * @param array<string, mixed>|null $row  The stored row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function withLenses(string $type, ?array $row): ?array {
		if ($row === null) {
			return null;
		}

		foreach (($this->lensFields[$type] ?? []) as $field) {
			$row[$field] = 'resolved '.$field;
		}

		foreach (($this->nonNullFields[$type] ?? []) as $field) {
			$row[$field] = ($row[$field] ?? null);
		}

		return $row;
	}

	private function asStored(string $type, array $data): array {
		foreach (($this->storedAs[$type] ?? []) as $field => $store) {
			if (array_key_exists($field, $data) === true) {
				$data[$field] = $store($data[$field]);
			}
		}

		return $data;
	}

	/** @var array<int, string> Ids under an active legal hold. */
	public array $held = [];

	/**
	 * As the real store: '' when gone, otherwise why not; a held row stays.
	 * The archival system path itself is tested on the real store.
	 */
	public function deleteRecorded(string $register, string $schema, string $id): string {
		if (in_array($id, $this->undeletable, true) === true) {
			return 'the register refused it';
		}

		if (in_array($id, $this->held, true) === true) {
			return 'it is under an active legal hold';
		}

		unset($this->rows[$register.' '.$schema][$id]);

		return '';
	}

	/**
	 * Every row id of a schema.
	 *
	 * @param string $register Register slug.
	 * @param string $schema   Schema slug.
	 *
	 * @return array<int, string>
	 */
	public function ids(string $register, string $schema): array {
		return array_keys($this->rows[$register.' '.$schema] ?? []);
	}
}
