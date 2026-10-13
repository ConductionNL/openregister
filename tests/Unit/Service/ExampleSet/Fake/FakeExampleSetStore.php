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

	/** @var int Number of validations. */
	public int $validations = 0;

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

	public function find(string $register, string $schema, array $filters): array {
		return array_values($this->rows[$register.' '.$schema] ?? []);
	}

	public function get(string $register, string $schema, string $id): ?array {
		return ($this->rows[$register.' '.$schema][$id] ?? null);
	}

	public function create(string $register, string $schema, array $data): array {
		if ($this->offers(register: $register, schema: $schema) === false) {
			return ['id' => '', 'error' => 'no such schema'];
		}

		// A save validates too, as a schema with hard validation does: no planned accounts there.
		$refused = $this->typeRefusals(type: $register.' '.$schema, data: $data, plannedUsers: []);
		if ($refused !== []) {
			return ['id' => '', 'error' => implode(' ', $refused)];
		}

		$this->creates++;
		foreach (($this->droppedFields[$register.' '.$schema] ?? []) as $field) {
			unset($data[$field]);
		}

		return ['id' => $this->seed(register: $register, schema: $schema, data: $data), 'error' => ''];
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

		$this->updates++;
		unset($data['@self'], $data['id']);
		$this->rows[$register.' '.$schema][$id] = (['id' => $id] + $data);

		return '';
	}

	public function delete(string $register, string $schema, string $id): bool {
		if (in_array($id, $this->undeletable, true) === true) {
			return false;
		}

		unset($this->rows[$register.' '.$schema][$id]);

		return true;
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
