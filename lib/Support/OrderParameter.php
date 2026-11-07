<?php

/**
 * OrderParameter: one answer to "which fields does `_order` ask for?"
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-multi-field-sorting-with-metadata-and-relevance-support
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

/**
 * Read every form of the `_order` parameter as a map of field to direction.
 *
 * The forms: a map (`_order[title]=desc`), that map as JSON, a list of objects
 * (`[{"field":"title","direction":"DESC"}]`, what GraphQL sends), a list of
 * names, and one name, where a name is `field` or `field:direction`.
 *
 * A digit-only field comes back as an int key, as any PHP array key does.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-multi-field-sorting-with-metadata-and-relevance-support
 */
final class OrderParameter {
	/**
	 * Constructor.
	 *
	 * @param mixed $order The `_order` value, in any of the forms.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-multi-field-sorting-with-metadata-and-relevance-support
	 */
	public function __construct(private readonly mixed $order) {
	}//end __construct()

	/**
	 * The fields the value asks for.
	 *
	 * @return array<array-key, string> Field => `ASC` or `DESC`, in the order asked for.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-multi-field-sorting-with-metadata-and-relevance-support
	 */
	public function fields(): array {
		$order = $this->order;
		if (is_string($order) === true) {
			$order = $this->decoded(order: $order);
		}

		if (is_array($order) === false) {
			return [];
		}

		if (array_is_list($order) === false) {
			return array_map(fn (mixed $value): string => $this->direction(value: $value), $order);
		}

		return $this->listed(entries: $order);
	}//end fields()

	/**
	 * The fields of a list; of two entries for one field the first wins.
	 *
	 * @param array<int, mixed> $entries The entries.
	 *
	 * @return array<array-key, string> Field => direction.
	 */
	private function listed(array $entries): array {
		$fields = [];
		foreach ($entries as $entry) {
			$fields += $this->entry(entry: $entry);
		}

		return $fields;
	}//end listed()

	/**
	 * A string value: JSON, or else one name.
	 *
	 * @param string $order The value.
	 *
	 * @return mixed The decoded value, or a list holding the name.
	 */
	private function decoded(string $order): mixed {
		$decoded = json_decode($order, true);
		if ($decoded === null) {
			return [$order];
		}

		return $decoded;
	}//end decoded()

	/**
	 * The field of one list entry.
	 *
	 * @param mixed $entry A name, or an object with a `field` and a `direction`.
	 *
	 * @return array<array-key, string> The field, or nothing for any other entry.
	 */
	private function entry(mixed $entry): array {
		if (is_string($entry) === true) {
			return $this->named(name: $entry);
		}

		if (is_string($entry['field'] ?? null) === false || $entry['field'] === '') {
			return [];
		}

		return [$entry['field'] => $this->direction(value: $entry['direction'] ?? null)];
	}//end entry()

	/**
	 * The field of a name.
	 *
	 * @param string $name `field` or `field:direction`.
	 *
	 * @return array<array-key, string> The field, or nothing for an empty name.
	 */
	private function named(string $name): array {
		if ($name === '') {
			return [];
		}

		if (preg_match('/^(.+):(asc|desc)$/i', $name, $parts) === 1) {
			return [$parts[1] => $this->direction(value: $parts[2])];
		}

		return [$name => 'ASC'];
	}//end named()

	/**
	 * The direction a value asks for; anything but `desc` is ascending.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string `ASC` or `DESC`.
	 */
	private function direction(mixed $value): string {
		if (is_string($value) === true && strtoupper($value) === 'DESC') {
			return 'DESC';
		}

		return 'ASC';
	}//end direction()
}//end class
