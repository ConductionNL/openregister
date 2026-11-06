<?php

/**
 * Minimal stand-ins for the Nextcloud Tables services, for tests that run in a
 * separate process: loading this file makes `class_exists()` true for them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Tables\Service;

use RuntimeException;

/**
 * Records every call and serves the canned reply of that method.
 */
trait RecordsCalls {

	/**
	 * Calls seen, as `[method, ...args]`.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public static array $calls = [];

	/**
	 * Canned reply per method; a Throwable is thrown.
	 *
	 * @var array<string, mixed>
	 */
	public static array $replies = [];

	/**
	 * Record a call and return (or throw) its canned reply.
	 *
	 * @param string $method The method name.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return mixed The canned reply.
	 */
	public function __call(string $method, array $args): mixed {
		self::$calls[] = array_merge([$method], $args);
		$reply = (self::$replies[$method] ?? null);
		if ($reply instanceof \Throwable) {
			throw $reply;
		}

		return $reply;
	}//end __call()
}//end trait

class TableService {
	use RecordsCalls;
}

class ColumnService {
	use RecordsCalls;
}

class RowService {
	use RecordsCalls;
}

class ViewService {
	use RecordsCalls;
}

class PermissionsService {
	use RecordsCalls;
}

/**
 * An entity whose getters answer from a map; a Throwable value makes the getter throw,
 * a missing key behaves like a getter that does not exist.
 */
class FakeEntity {

	/**
	 * Build the entity.
	 *
	 * @param array<string, mixed> $values Getter name => value.
	 */
	public function __construct(private array $values) {
	}

	/**
	 * Answer a getter.
	 *
	 * @param string $getter The getter name.
	 * @param array<int, mixed> $args Unused.
	 *
	 * @return mixed The value.
	 */
	public function __call(string $getter, array $args): mixed {
		if (array_key_exists($getter, $this->values) === false) {
			throw new RuntimeException('no ' . $getter);
		}

		if ($this->values[$getter] instanceof \Throwable) {
			throw $this->values[$getter];
		}

		return $this->values[$getter];
	}//end __call()
}//end class
