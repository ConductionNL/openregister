<?php

/**
 * PaginatedResults: the canonical pagination envelope of an integration list, for callers outside lib/Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

/**
 * Normalises an integration list response into the `{items, total, nextCursor}` envelope.
 *
 * The integrations endpoint called PaginatedResult::fromMixed()->toArray()
 * itself; this makes the same two calls, so it no longer names the class.
 */
final class PaginatedResults {

	/**
	 * The keys of the envelope itself, which an extra key never replaces.
	 */
	private const ENVELOPE_KEYS = ['items', 'results', 'total', 'nextCursor'];

	/**
	 * The canonical envelope of a raw provider value.
	 *
	 * @param mixed $value Raw value returned by a provider or service.
	 *
	 * @return array<string, mixed> The `{items, total, nextCursor}` envelope.
	 */
	public static function envelope(mixed $value): array {
		return PaginatedResult::fromMixed($value)->toArray();
	}//end envelope()

	/**
	 * The declared extra top-level keys of a raw list answer, unchanged.
	 *
	 * Only keys named in $declared and present in $value are returned; the
	 * envelope's own keys (`items`, `results`, `total`, `nextCursor`) never
	 * are, so a provider cannot override them this way. A non-array value, or
	 * a flat list of rows, has no extra keys.
	 *
	 * @param mixed $value    Raw value returned by a provider's list().
	 * @param array $declared The key names the provider declares.
	 *
	 * @return array<string, mixed> The extra keys with their values, in declared order.
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-an-integration-may-declare-extra-top-level-keys-of-its-list-answer
	 */
	public static function extras(mixed $value, array $declared): array {
		if (is_array($value) === false || array_is_list($value) === true) {
			return [];
		}

		$extras = [];
		foreach ($declared as $key) {
			if (is_string($key) === false
				|| in_array($key, self::ENVELOPE_KEYS, true) === true
				|| array_key_exists($key, $value) === false
			) {
				continue;
			}

			$extras[$key] = $value[$key];
		}

		return $extras;
	}//end extras()
}//end class
