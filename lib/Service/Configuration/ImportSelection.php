<?php

/**
 * OpenRegister Import Selection
 *
 * Narrows a remote configuration document to what an administrator picked in
 * the preview.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

/**
 * Pure filter over a configuration document (no I/O).
 *
 * A selection has up to three lists: `registers` and `schemas` hold slugs,
 * `objects` holds `register:schema:slug` keys, the shape the preview modal
 * posts. Slugs compare case-insensitively.
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-selective-configuration-import-and-auto-update-must-import-what-they-name
 */
final class ImportSelection {

	/**
	 * Whether a selection names nothing, which means "the whole document".
	 *
	 * @param array<string, mixed> $selection The posted selection.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-selective-configuration-import-and-auto-update-must-import-what-they-name
	 */
	public static function isEmpty(array $selection): bool {
		foreach (['registers', 'schemas', 'objects'] as $key) {
			if (is_array($selection[$key] ?? null) === true && $selection[$key] !== []) {
				return false;
			}
		}

		return true;

	}//end isEmpty()

	/**
	 * Narrow a remote document to the selected registers, schemas and objects.
	 *
	 * Document metadata (`openapi`, `info`, `version`, `x-openregister`) is kept
	 * so the importer sees the same version, but seed data is dropped: it would
	 * bring in objects nobody selected. Components the preview cannot select
	 * (endpoints, mappings, sources and the like) are left out.
	 *
	 * @param array<string, mixed> $document  The remote configuration document.
	 * @param array<string, mixed> $selection The posted selection.
	 *
	 * @return array<string, mixed> The narrowed document.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-selective-configuration-import-and-auto-update-must-import-what-they-name
	 */
	public static function filter(array $document, array $selection): array {
		$filtered = self::withoutSeedData(document: $document);
		$components = $document['components'] ?? [];
		$filtered['components'] = [
			'registers' => self::pickBySlug(items: $components['registers'] ?? [], wanted: $selection['registers'] ?? []),
			'schemas' => self::pickBySlug(items: $components['schemas'] ?? [], wanted: $selection['schemas'] ?? []),
			'objects' => self::pickObjects(objects: $components['objects'] ?? [], wanted: $selection['objects'] ?? []),
		];

		return $filtered;

	}//end filter()

	/**
	 * A document without its seed data: the top-level objects and `x-openregister.seedData`.
	 *
	 * @param array<string, mixed> $document The remote configuration document.
	 *
	 * @return array<string, mixed> The document without seed data.
	 */
	private static function withoutSeedData(array $document): array {
		unset($document['objects']);
		if (is_array($document['x-openregister'] ?? null) === true) {
			unset($document['x-openregister']['seedData']);
		}

		return $document;

	}//end withoutSeedData()

	/**
	 * Whether a narrowed document carries anything to import.
	 *
	 * @param array<string, mixed> $filtered A document returned by filter().
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-selective-configuration-import-and-auto-update-must-import-what-they-name
	 */
	public static function hasContent(array $filtered): bool {
		$components = $filtered['components'];
		return $components['registers'] !== [] || $components['schemas'] !== [] || $components['objects'] !== [];

	}//end hasContent()

	/**
	 * Keep the slug-keyed entries whose slug was selected.
	 *
	 * @param mixed $items  Slug-keyed map from the document.
	 * @param mixed $wanted Selected slugs.
	 *
	 * @return array<string, mixed>
	 */
	private static function pickBySlug(mixed $items, mixed $wanted): array {
		if (is_array($items) === false) {
			return [];
		}

		$wantedLower = self::lowered(values: $wanted);
		return array_filter(
			$items,
			static fn ($slug): bool => in_array(strtolower((string)$slug), $wantedLower, true),
			ARRAY_FILTER_USE_KEY
		);

	}//end pickBySlug()

	/**
	 * Keep the objects whose `register:schema:slug` key was selected.
	 *
	 * @param mixed $objects Object rows from the document.
	 * @param mixed $wanted  Selected keys.
	 *
	 * @return array<int, mixed>
	 */
	private static function pickObjects(mixed $objects, mixed $wanted): array {
		if (is_array($objects) === false) {
			return [];
		}

		$wantedLower = self::lowered(values: $wanted);
		if ($wantedLower === []) {
			return [];
		}

		$picked = array_filter($objects, static fn ($object): bool => in_array(self::objectKey(object: $object), $wantedLower, true));
		return array_values($picked);

	}//end pickObjects()

	/**
	 * The selected slugs or keys, lowercased; none when the selection list is not an array.
	 *
	 * @param mixed $values Selected slugs or keys.
	 *
	 * @return array<int|string, string>
	 */
	private static function lowered(mixed $values): array {
		if (is_array($values) === false) {
			return [];
		}

		return array_map(static fn ($value): string => strtolower((string)$value), $values);

	}//end lowered()

	/**
	 * The lowercased `register:schema:slug` key of an object row.
	 *
	 * @param mixed $object An object row from the document.
	 *
	 * @return string
	 */
	private static function objectKey(mixed $object): string {
		$self = $object['@self'] ?? [];
		return strtolower(($self['register'] ?? '') . ':' . ($self['schema'] ?? '') . ':' . ($self['slug'] ?? ''));

	}//end objectKey()
}//end class
