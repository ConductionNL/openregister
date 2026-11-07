<?php

/**
 * SearchReferenceKey: one place in a search query that can hold a register or schema reference.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Reads a reference from one query key and writes its resolved id back.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchReferenceKey {
	/**
	 * The containing key (`@self`), or null for top level.
	 *
	 * @var string|null
	 */
	private readonly ?string $parent;

	/**
	 * The key holding the reference.
	 *
	 * @var string
	 */
	private readonly string $key;

	/**
	 * Whether the key holds a list.
	 *
	 * @var bool
	 */
	private readonly bool $plural;

	/**
	 * Constructor.
	 *
	 * @param array{0: string|null, 1: string, 2: bool} $spelling The parent, the key and whether it holds a list.
	 * @param string                                    $kind     Either `register` or `schema`.
	 */
	public function __construct(
		array $spelling,
		public readonly string $kind,
	) {
		$this->parent = $spelling[0];
		$this->key    = $spelling[1];
		$this->plural = $spelling[2];
	}//end __construct()

	/**
	 * The reference a query carries at this key, when it carries one.
	 *
	 * Null means there is nothing to resolve: the key is absent, its value is
	 * not a reference shape, or it is a list key spelled as a single value.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return int|string|array|null The reference, or null when there is none to read.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function readFrom(array $query): int|string|array|null {
		$value = ($this->holderIn(query: $query)[$this->key] ?? null);
		if ($this->isReference(value: $value) === false) {
			return null;
		}

		return $value;
	}//end readFrom()

	/**
	 * Put a resolved reference back, or drop the key when it said nothing.
	 *
	 * @param array<string, mixed> $query    A search query that holds this key.
	 * @param int|array|null       $resolved The resolved id(s), or null.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function writeTo(array $query, int|array|null $resolved): array {
		if ($this->parent === null) {
			return $this->writeInto(holder: $query, resolved: $resolved);
		}

		$query[$this->parent] = $this->writeInto(holder: $query[$this->parent], resolved: $resolved);

		return $query;
	}//end writeTo()

	/**
	 * The array this key sits in.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return array<string, mixed> The holder, empty when the query has none.
	 */
	private function holderIn(array $query): array {
		if ($this->parent === null) {
			return $query;
		}

		$holder = ($query[$this->parent] ?? null);
		if (is_array($holder) === true) {
			return $holder;
		}

		return [];
	}//end holderIn()

	/**
	 * Whether a value has the shape of a reference at this key.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a list, and at a single key also for an id or text.
	 */
	private function isReference(mixed $value): bool {
		if ($this->plural === true) {
			return is_array($value);
		}

		return is_int($value) || is_string($value) || is_array($value);
	}//end isReference()

	/**
	 * Set or drop this key in its holder.
	 *
	 * @param array<string, mixed> $holder   The array the key sits in.
	 * @param int|array|null       $resolved The resolved id(s), or null.
	 *
	 * @return array<string, mixed> The holder.
	 */
	private function writeInto(array $holder, int|array|null $resolved): array {
		if ($resolved === null) {
			unset($holder[$this->key]);
			return $holder;
		}

		$holder[$this->key] = $resolved;

		return $holder;
	}//end writeInto()
}//end class
