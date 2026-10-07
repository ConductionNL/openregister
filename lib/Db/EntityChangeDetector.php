<?php

/**
 * OpenRegister EntityChangeDetector.
 *
 * Tells whether a save changed the stored register or schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Compares a register or schema before and after a save.
 *
 * RegisterMapper::update() and SchemaMapper::update() ask it before
 * dispatching their update event, so a save that changed nothing reaches no
 * listener. An event fires at the level where the change happened, and only
 * when something there really changed.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-schema-save-that-changes-nothing-publishes-nothing
 * @spec openspec/specs/activity-provider/spec.md#requirement-a-register-save-that-changes-nothing-publishes-nothing
 */
class EntityChangeDetector {
	/**
	 * Tell whether a save changed the stored entity.
	 *
	 * Compares the serialised entity before and after the save, leaving out
	 * the `updated` timestamp: a timestamp bump alone is not a change anyone
	 * needs to hear about. Scalars are compared as strings, so a schema id
	 * re-hydrated as "28" equals the stored 28.
	 *
	 * @param Entity $old The entity as stored before the save.
	 * @param Entity $new The entity after the save.
	 *
	 * @return bool True when something other than the timestamp changed.
	 *
	 * @spec openspec/specs/activity-provider/spec.md#requirement-a-register-save-that-changes-nothing-publishes-nothing
	 */
	public function changed(Entity $old, Entity $new): bool {
		$before = $old->jsonSerialize();
		$after = $new->jsonSerialize();
		unset($before['updated'], $after['updated']);

		return $this->normaliseForCompare(value: $before) !== $this->normaliseForCompare(value: $after);
	}//end changed()

	/**
	 * Normalise a serialised value so equal content compares equal.
	 *
	 * Map keys are sorted, scalars become strings, null stays null and a
	 * DateTime becomes its ISO 8601 form.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return mixed The normalised value.
	 *
	 * @spec openspec/specs/activity-provider/spec.md#requirement-a-register-save-that-changes-nothing-publishes-nothing
	 */
	private function normaliseForCompare(mixed $value): mixed {
		if (is_array($value) === true) {
			$normalised = [];
			foreach ($value as $key => $item) {
				$normalised[(string)$key] = $this->normaliseForCompare(value: $item);
			}

			if (array_is_list($value) === false) {
				ksort($normalised);
			}

			return $normalised;
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format('c');
		}

		if ($value instanceof \JsonSerializable) {
			return $this->normaliseForCompare(value: $value->jsonSerialize());
		}

		if (is_bool($value) === true) {
			return (string)(int)$value;
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return $value;
	}//end normaliseForCompare()
}//end class
