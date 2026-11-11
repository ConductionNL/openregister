<?php

/**
 * OpenRegister UuidReference
 *
 * The UUID a relation value holds, as a plain string or under a common key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * A relation field value that may hold a UUID.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class UuidReference {

	/**
	 * The keys that commonly hold a UUID reference, in order of preference.
	 */
	private const UUID_KEYS = ['value', 'id', 'uuid', '@self.id'];

	/**
	 * Constructor.
	 *
	 * @param mixed $value The value: a string, an array, or anything else.
	 */
	public function __construct(
		private mixed $value,
	) {
	}//end __construct()

	/**
	 * Extract a UUID string from the value.
	 *
	 * Handles various formats:
	 * - Plain UUID string: "debe49c0-e770-5cd7-9003-e8f34274cc2a"
	 * - Object with value key: {"value": "uuid-here"}
	 * - Object with id key: {"id": "uuid-here"}
	 * - Object with uuid key: {"uuid": "uuid-here"}
	 * - Object with @self.id key: {"@self.id": "uuid-here"}
	 *
	 * @return string|null The extracted UUID or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function uuid(): ?string {
		if (is_string($this->value) === true && empty($this->value) === false) {
			return $this->value;
		}

		if (is_array($this->value) === true) {
			return $this->fromKeys(value: $this->value);
		}

		return null;
	}//end uuid()

	/**
	 * The first string under a key that commonly holds a UUID reference.
	 *
	 * @param array $value The structured value.
	 *
	 * @return string|null The UUID, or null when no such key holds a string.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function fromKeys(array $value): ?string {
		foreach (self::UUID_KEYS as $key) {
			if (isset($value[$key]) === true && is_string($value[$key]) === true) {
				return $value[$key];
			}
		}

		return null;
	}//end fromKeys()
}//end class
