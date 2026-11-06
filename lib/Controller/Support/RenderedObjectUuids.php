<?php

/**
 * OpenRegister rendered object uuids
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Formats\UuidFormat;

/**
 * Collects the uuids a rendered object refers to, in reading order.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class RenderedObjectUuids {

	/**
	 * Keys whose value is the object's own identity, not a reference.
	 */
	private const SKIPPED_KEYS = ['@self', 'id', '_id'];

	/**
	 * The uuids found so far.
	 *
	 * @var list<string>
	 */
	private array $uuids = [];

	/**
	 * The uuids in the relations and the properties of a rendered object.
	 *
	 * @param array $rendered The rendered object.
	 *
	 * @return list<string> The uuids, duplicates included.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function inRendered(array $rendered): array {
		$relations = ($rendered['@self']['relations'] ?? []);
		if (is_array($relations) === true) {
			array_walk($relations, fn (mixed $relation) => $this->addRelation(relation: $relation));
		}

		$properties = ($rendered['@self']['object'] ?? $rendered);
		if (is_array($properties) === true) {
			$this->addProperties(data: $properties);
		}

		return $this->uuids;
	}//end inRendered()

	/**
	 * Add one relation: a uuid, or an array of them.
	 *
	 * @param mixed $relation The relation.
	 *
	 * @return void
	 */
	private function addRelation(mixed $relation): void {
		$this->addUuid(value: $relation);
		if (is_array($relation) === true) {
			array_walk($relation, fn (mixed $uuid) => $this->addUuid(value: $uuid));
		}
	}//end addRelation()

	/**
	 * Add the uuids of a map of properties, at every depth.
	 *
	 * @param array $data The properties.
	 *
	 * @return void
	 */
	private function addProperties(array $data): void {
		foreach ($data as $key => $value) {
			if (in_array($key, self::SKIPPED_KEYS, true) === false) {
				$this->addPropertyValue(value: $value);
			}
		}
	}//end addProperties()

	/**
	 * Add one property value: a uuid, or an array of uuids and nested properties.
	 *
	 * @param mixed $value The value.
	 *
	 * @return void
	 */
	private function addPropertyValue(mixed $value): void {
		$this->addUuid(value: $value);
		if (is_array($value) === false) {
			return;
		}

		foreach ($value as $item) {
			$this->addItem(item: $item);
		}
	}//end addPropertyValue()

	/**
	 * Add one item of an array property.
	 *
	 * @param mixed $item The item.
	 *
	 * @return void
	 */
	private function addItem(mixed $item): void {
		$this->addUuid(value: $item);
		if (is_array($item) === true) {
			$this->addProperties(data: $item);
		}
	}//end addItem()

	/**
	 * Add a value when it is a uuid.
	 *
	 * @param mixed $value The value.
	 *
	 * @return void
	 */
	private function addUuid(mixed $value): void {
		if (is_string($value) === false) {
			return;
		}

		if (UuidFormat::isCanonical(value: $value) === true) {
			$this->uuids[] = $value;
		}
	}//end addUuid()
}//end class
