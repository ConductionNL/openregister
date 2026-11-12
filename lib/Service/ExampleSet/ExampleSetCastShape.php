<?php

/**
 * OpenRegister Example Set Cast Shape
 *
 * Checks and keys the cast of one example set, and the OpenRegister registers
 * it requires, naming the cast file in every refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The cast and the required registers of one set, for one cast file.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */
class ExampleSetCastShape {
	use ExampleSetKeyOf;

	/**
	 * The cast so far: people, organisations and groups by key, and all three merged as `entries`.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $keyed = ['people' => [], 'organisations' => [], 'groups' => [], 'entries' => []];

	/**
	 * Constructor.
	 *
	 * @param string $file The cast file, for messages.
	 * @param string $set  The set id.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $file,
		private readonly string $set,
	) {
	}//end __construct()

	/**
	 * The cast, keyed, with every key checked to be unique across people, organisations and groups.
	 *
	 * Every person and organisation gets a `contactsUid` when it declares none:
	 * `example-set-<set>-<key>`, stable across installs, so an app that keeps a
	 * Contacts uid can quote `{{cast:key.contactsUid}}`.
	 *
	 * @param array<string, mixed> $cast The declared cast.
	 *
	 * @return array{people: array<string, array<string, mixed>>, organisations: array<string, array<string, mixed>>,
	 *               groups: array<string, array<string, mixed>>, entries: array<string, array<string, mixed>>}
	 *
	 * @throws ExampleSetException When a key is missing or used twice.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	public function cast(array $cast): array {
		foreach (['people', 'organisations', 'groups'] as $part) {
			foreach ((array) ($cast[$part] ?? []) as $entry) {
				$this->add(part: $part, entry: $entry);
			}
		}

		return $this->keyed;
	}//end cast()

	/**
	 * Key one cast entry, giving a person or organisation its default `contactsUid`.
	 *
	 * @param string $part  `people`, `organisations` or `groups`.
	 * @param mixed  $entry The declared entry.
	 *
	 * @return void
	 *
	 * @throws ExampleSetException When the key is missing or used twice.
	 */
	private function add(string $part, mixed $entry): void {
		$key = $this->keyOf(entry: $entry);
		if ($key === '' || isset($this->keyed['entries'][$key]) === true) {
			throw new ExampleSetException($this->file.': every cast entry needs a key of its own; "'.$key.'" in '.$part.' is missing or used twice.');
		}

		// The entry has a key, so it is an array; the cast only tells static analysis so.
		$entry = (array) $entry;
		if ($part !== 'groups' && is_string($entry['contactsUid'] ?? null) === false) {
			$entry['contactsUid'] = 'example-set-'.$this->set.'-'.$key;
		}

		$this->keyed[$part][$key]    = $entry;
		$this->keyed['entries'][$key] = $entry;
	}//end add()

	/**
	 * The OpenRegister registers the cast requires, keyed by slug.
	 *
	 * An entry is a slug, imported from OpenRegister's real descriptor, or
	 * `{"register": "<slug>", "mock": true}`, imported from its mock (sample)
	 * descriptor.
	 *
	 * @param array<int|string, mixed> $declared The declared requirements.
	 *
	 * @return array<string, array{mock: bool}> Slug to how it is imported.
	 *
	 * @throws ExampleSetException When an entry names no register.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function requires(array $declared): array {
		$registers = [];
		foreach ($declared as $index => $entry) {
			[$slug, $mock]     = $this->requirement(index: $index, entry: $entry);
			$registers[$slug] = ['mock' => $mock];
		}

		return $registers;
	}//end requires()

	/**
	 * One required register: its slug and whether its mock descriptor is imported.
	 *
	 * @param int|string $index The entry's position, for messages.
	 * @param mixed      $entry The declared entry.
	 *
	 * @return array{0: string, 1: bool}
	 *
	 * @throws ExampleSetException When the entry names no register.
	 */
	private function requirement(int|string $index, mixed $entry): array {
		if (is_array($entry) === false) {
			$entry = ['register' => $entry];
		}

		$slug = ($entry['register'] ?? '');
		$mock = ($entry['mock'] ?? false);
		if (is_string($slug) === false || $slug === '' || is_bool($mock) === false) {
			throw new ExampleSetException($this->file.': requires '.$index.' needs a register slug, or {"register": "<slug>", "mock": true}.');
		}

		return [$slug, $mock];
	}//end requirement()
}//end class
