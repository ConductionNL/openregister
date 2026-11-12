<?php

/**
 * OpenRegister DependentValueWrite
 *
 * The object save a dependent value table is judged against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * The payload and the stored object of one save, read the way the table reads them.
 *
 * The controlling value is read from the payload when the payload carries it
 * and from the stored object otherwise, because a PATCH that changes only the
 * dependent property is still judged against the zaaktype the object has.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class DependentValueWrite {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data   The object as it would be saved.
	 * @param array<string, mixed> $stored The object as currently stored, or an empty array on create.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $data,
		private readonly array $stored,
	) {
	}//end __construct()

	/**
	 * The value proposed for a dependent property, when there is one to judge.
	 *
	 * Nothing proposed means the table has nothing to say. Whether the property
	 * may be empty at all is `required`'s question, not this one's.
	 *
	 * @param string|int $name The dependent property.
	 *
	 * @return string|null The value as a string, or null when nothing scalar was proposed.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function proposed(string|int $name): ?string {
		$value = ($this->data[$name] ?? null);
		if ($value === null || $value === '' || is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end proposed()

	/**
	 * The controlling property's value, from the payload or from the store.
	 *
	 * @param string $name The controlling property's name.
	 *
	 * @return string|null The value as a string, or null when there is none to read.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function controllingValue(string $name): ?string {
		$value = ($this->data[$name] ?? $this->stored[$name] ?? null);
		if ($value === null || is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end controllingValue()
}//end class
