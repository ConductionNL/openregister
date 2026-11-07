<?php

/**
 * VocabularyEntries: the linkRoles and partyKinds vocabularies of a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

use InvalidArgumentException;

/**
 * Validates and normalises `linkRoles` and `partyKinds`, on save and on read.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class VocabularyEntries {
	/**
	 * Validate and normalise `linkRoles`: the roles a person can hold on an
	 * object of this schema (people-on-objects).
	 *
	 * Each entry is `{key, label, description?}`; a bare string reads as
	 * `{key: s, label: s}`. Keys are unique, non-empty and at most 64
	 * characters, the width of the link table's role column.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return array<int, array{key: string, label: string, description?: string}> The normalised entries.
	 *
	 * @throws InvalidArgumentException When the value is not a list of valid entries.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function validateLinkRolesValue(mixed $value): array {
		return $this->validateVocabulary(value: $value, vocabulary: ['config' => 'linkRoles', 'noun' => 'roles']);
	}//end validateLinkRolesValue()

	/**
	 * Validate and normalise a list of vocabulary entries (`linkRoles`, `partyKinds`): each with a usable key, no key twice.
	 *
	 * @param mixed                                $value      The configured value.
	 * @param array{config: string, noun: string} $vocabulary The configuration key, and the plural noun for its entries.
	 *
	 * @return array<int, array<string, mixed>> The normalised entries.
	 *
	 * @throws InvalidArgumentException When the value is not a list of valid entries.
	 */
	private function validateVocabulary(mixed $value, array $vocabulary): array {
		if (is_array($value) === false || array_is_list($value) === false) {
			throw new InvalidArgumentException("Configuration '" . $vocabulary['config'] . "' must be a list of " . $vocabulary['noun']);
		}

		$entries = [];
		foreach ($value as $entry) {
			$entries[] = $this->vocabularyEntry(entry: $entry, vocabulary: $vocabulary + ['entries' => $entries]);
		}

		return $entries;
	}//end validateVocabulary()

	/**
	 * One vocabulary entry, normalised; refused when it has no usable key or repeats an earlier key.
	 *
	 * @param mixed                $entry      The configured entry.
	 * @param array<string, mixed> $vocabulary The `config` key, and the `entries` accepted before this one.
	 *
	 * @return array<string, mixed> The normalised entry.
	 *
	 * @throws InvalidArgumentException When the entry has no usable key or repeats one.
	 */
	private function vocabularyEntry(mixed $entry, array $vocabulary): array {
		$normalised = self::normaliseVocabularyEntry(config: $vocabulary['config'], entry: $entry);
		if ($normalised === null) {
			throw new InvalidArgumentException("Each '" . $vocabulary['config'] . "' entry needs a non-empty key of at most 64 characters");
		}

		if (in_array($normalised['key'], array_column($vocabulary['entries'], 'key'), true) === true) {
			throw new InvalidArgumentException("'" . $vocabulary['config'] . "' names the key '" . $normalised['key'] . "' twice");
		}

		return $normalised;
	}//end vocabularyEntry()

	/**
	 * One entry of a vocabulary, normalised as that vocabulary normalises it; null when it has no usable key.
	 *
	 * @param string $config `linkRoles` or `partyKinds`.
	 * @param mixed  $entry  The configured entry.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function normaliseVocabularyEntry(string $config, mixed $entry): ?array {
		if ($config === 'partyKinds') {
			return self::normalisePartyKind(entry: $entry);
		}

		return self::normaliseLinkRole(entry: $entry);
	}//end normaliseVocabularyEntry()

	/**
	 * The usable entries of a stored vocabulary, normalised; [] when the configuration holds no list for it.
	 *
	 * @param mixed  $configuration The schema's stored configuration.
	 * @param string $config        `linkRoles` or `partyKinds`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function configuredVocabulary(mixed $configuration, string $config): array {
		$configured = null;
		if (is_array($configuration) === true) {
			$configured = ($configuration[$config] ?? null);
		}

		if (is_array($configured) === false) {
			return [];
		}

		$entries = array_map(static fn (mixed $entry): ?array => self::normaliseVocabularyEntry(config: $config, entry: $entry), $configured);

		return array_values(array_filter($entries, static fn (?array $entry): bool => $entry !== null));
	}//end configuredVocabulary()

	/**
	 * One `linkRoles` entry as `{key, label, description?}`, or null when it has no usable key.
	 *
	 * @param mixed $entry A string or an array.
	 *
	 * @return array{key: string, label: string, description?: string}|null The entry.
	 */
	private static function normaliseLinkRole(mixed $entry): ?array {
		if (is_string($entry) === true) {
			$entry = ['key' => $entry, 'label' => $entry];
		}

		$key = self::entryKey(entry: $entry);
		if ($key === null) {
			return null;
		}

		return self::withDescription(normalised: ['key' => $key, 'label' => self::entryLabel(entry: $entry, key: $key)], entry: $entry);
	}//end normaliseLinkRole()

	/**
	 * The trimmed key of a vocabulary entry, or null when the entry is no array or its key is empty or over 64 characters.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string|null
	 */
	private static function entryKey(mixed $entry): ?string {
		if (is_array($entry) === false) {
			return null;
		}

		$key = trim((string)($entry['key'] ?? ''));
		if ($key === '' || strlen($key) > 64) {
			return null;
		}

		return $key;
	}//end entryKey()

	/**
	 * The trimmed label of a vocabulary entry, its key when it has none.
	 *
	 * @param array<string, mixed> $entry The entry.
	 * @param string               $key   The entry's key.
	 *
	 * @return string
	 */
	private static function entryLabel(array $entry, string $key): string {
		$label = trim((string)($entry['label'] ?? ''));
		if ($label === '') {
			return $key;
		}

		return $label;
	}//end entryLabel()

	/**
	 * A normalised entry with the entry's trimmed description, when it has one.
	 *
	 * @param array<string, string> $normalised The normalised key and label.
	 * @param array<string, mixed>  $entry      The entry.
	 *
	 * @return array<string, string>
	 */
	private static function withDescription(array $normalised, array $entry): array {
		$description = trim((string)($entry['description'] ?? ''));
		if ($description !== '') {
			$normalised['description'] = $description;
		}

		return $normalised;
	}//end withDescription()

	/**
	 * Validate and normalise `partyKinds`: the kinds of party an object of
	 * this schema accepts, and per kind the roles it may hold.
	 *
	 * Each entry is `{key, label, description?, roles?, identityShapes?}`; a
	 * bare string reads as `{key: s, label: s}`. Keys are unique, non-empty and
	 * at most 64 characters, the width of the link table's `party_kind` column.
	 * `roles` is an optional list of role keys: naming it binds those roles to
	 * that kind, leaving it out lets the kind hold any role the schema
	 * declares. `identityShapes` names the identity shapes a party of this
	 * kind has in a shared party-kind vocabulary (`naturalPerson`,
	 * `organization`), so a host can check a party against a vocabulary that
	 * accepts shapes rather than this schema's kind keys.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return array<int, array<string, mixed>> The normalised entries, each
	 *   `{key, label, description?, roles?, identityShapes?}`.
	 *
	 * @throws InvalidArgumentException When the value is not a list of valid entries.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function validatePartyKindsValue(mixed $value): array {
		return $this->validateVocabulary(value: $value, vocabulary: ['config' => 'partyKinds', 'noun' => 'party kinds']);
	}//end validatePartyKindsValue()

	/**
	 * One `partyKinds` entry as `{key, label, description?, roles?, identityShapes?}`, or null when it has no usable key.
	 *
	 * @param mixed $entry A string or an array.
	 *
	 * @return array{key: string, label: string, description?: string, roles?: array<int, string>, identityShapes?: array<int, string>}|null The entry.
	 */
	private static function normalisePartyKind(mixed $entry): ?array {
		$normalised = self::normaliseLinkRole(entry: $entry);
		if ($normalised === null || is_array($entry) === false) {
			return $normalised;
		}

		foreach (['roles', 'identityShapes'] as $listKey) {
			$normalised = self::withList(normalised: $normalised, listKey: $listKey, list: self::distinctStrings(value: ($entry[$listKey] ?? null)));
		}

		return $normalised;
	}//end normalisePartyKind()

	/**
	 * The entry with a list added under its key, unless the list is empty.
	 *
	 * @param array<string, mixed> $normalised The entry so far.
	 * @param string               $listKey    `roles` or `identityShapes`.
	 * @param array<int, string>   $list       The distinct strings declared for it.
	 *
	 * @return array<string, mixed>
	 */
	private static function withList(array $normalised, string $listKey, array $list): array {
		if ($list !== []) {
			$normalised[$listKey] = $list;
		}

		return $normalised;
	}//end withList()

	/**
	 * The distinct, trimmed, non-empty strings of a list, in order; [] for anything else.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return array<int, string> The strings.
	 */
	private static function distinctStrings(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $item) {
			$item = trim((string)$item);
			if ($item !== '' && in_array($item, $out, true) === false) {
				$out[] = $item;
			}
		}

		return $out;
	}//end distinctStrings()
}//end class
