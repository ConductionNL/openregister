<?php

/**
 * OpenRegister TranslatableGroupKeyProjection
 *
 * Projects the translatable group keys of a grouped aggregation envelope to
 * the negotiated display language.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\TranslationHandler;

/**
 * Projects each translatable group key of the grouped rows.
 *
 * Built by AggregationRunner once it knows at least one grouped field is
 * translatable and the caller did not ask for every translation. Both row
 * shapes are handled: a composite grouping's `keys` map (every translatable
 * member projected against its own field name; others left byte-for-byte
 * unchanged) and the single-field scalar `key`.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class TranslatableGroupKeyProjection {

	/**
	 * Constructor.
	 *
	 * @param TranslationHandler $translations       Resolves a language-keyed map to the negotiated language.
	 * @param Schema             $schema             The schema being aggregated.
	 * @param Register           $register           The owning register (language config).
	 * @param array<int, string> $translatableFields The grouped fields that ARE translatable, in group order.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		private readonly TranslationHandler $translations,
		private readonly Schema $schema,
		private readonly Register $register,
		private readonly array $translatableFields,
	) {
	}//end __construct()

	/**
	 * The grouped rows with their translatable key(s) projected; non-array rows untouched.
	 *
	 * @param array<int|string, mixed> $groups The grouped rows.
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function projectGroups(array $groups): array {
		foreach ($groups as $index => $group) {
			if (is_array($group) === false) {
				continue;
			}

			$groups[$index] = $this->projectRow(group: $group);
		}

		return $groups;
	}//end projectGroups()

	/**
	 * Project the translatable key(s) of ONE grouped row.
	 *
	 * A row carrying neither `keys` nor `key` is returned untouched.
	 *
	 * @param array<string, mixed> $group The grouped row.
	 *
	 * @return array<string, mixed>
	 */
	private function projectRow(array $group): array {
		if (isset($group['keys']) === true && is_array($group['keys']) === true) {
			$group['keys'] = $this->projectCompositeKeys(keys: $group['keys']);
			return $group;
		}

		// Single-field grouping: the legacy scalar `key` shape.
		if (array_key_exists('key', $group) === false) {
			return $group;
		}

		$group['key'] = $this->projectKey(rawKey: $group['key'], field: $this->translatableFields[0]);
		return $group;
	}//end projectRow()

	/**
	 * Project every translatable member of a composite `keys` map against its OWN field name.
	 *
	 * @param array<string, mixed> $keys The composite key map.
	 *
	 * @return array<string, mixed>
	 */
	private function projectCompositeKeys(array $keys): array {
		foreach ($this->translatableFields as $field) {
			if (array_key_exists($field, $keys) === false) {
				continue;
			}

			$keys[$field] = $this->projectKey(rawKey: $keys[$field], field: $field);
		}

		return $keys;
	}//end projectCompositeKeys()

	/**
	 * Project a single grouped key value to the negotiated language.
	 *
	 * Normalizes the raw key to a language-keyed map (native path yields a
	 * JSON string, PHP path an array), then delegates to
	 * {@see TranslationHandler::resolveTranslationsForRender()} by wrapping
	 * the map under the grouped field name and reading the resolved value
	 * back. Non-map keys (already scalar, e.g. legacy untranslated rows) are
	 * returned unchanged.
	 *
	 * @param mixed  $rawKey The raw group key (JSON string or array).
	 * @param string $field  The grouped field name.
	 *
	 * @return mixed The projected scalar value, or the original key when not a map.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function projectKey(mixed $rawKey, string $field): mixed {
		$map = $rawKey;

		// Native SQL returns the JSON column value as a string; decode it.
		if (is_string($rawKey) === true) {
			$decoded = json_decode($rawKey, true);
			if (is_array($decoded) === true) {
				$map = $decoded;
			}
		}

		// Not a language-keyed map (scalar / legacy untranslated) — leave it.
		if (is_array($map) === false) {
			return $rawKey;
		}

		$resolved = $this->translations->resolveTranslationsForRender(
			objectData: [$field => $map],
			schema: $this->schema,
			register: $this->register
		);

		return $resolved[$field] ?? $rawKey;
	}//end projectKey()
}//end class
