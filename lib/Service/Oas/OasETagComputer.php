<?php

/**
 * OasETagComputer — deterministic ETag for the generated OAS document.
 *
 * The OAS spec is generated from the live route table + schema
 * registrations, so two requests with the same registered shape MUST
 * produce the same ETag. The hash is computed over a canonical-JSON
 * representation (sorted keys) so structurally-equivalent specs that
 * differ only in property order produce the same ETag.
 *
 * Used by OasController to short-circuit identical fetches with a
 * `304 Not Modified` when the client sends an `If-None-Match` matching
 * the current ETag.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-validation/spec.md "Performance Impact of Validation / ETag caching"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Compute and verify ETags for OAS documents.
 */
class OasETagComputer implements OasETagComputerInterface {
	/**
	 * Compute the strong ETag for an OAS document.
	 *
	 * @param array $oas The generated OAS payload.
	 *
	 * @return string Strong ETag, quoted per RFC 7232 (e.g. `"abc123"`).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-7
	 */
	public function computeETag(array $oas): string {
		return '"' . $this->hash(spec: $oas) . '"';
	}//end computeETag()

	/**
	 * Compute the raw hash (unquoted).
	 *
	 * @param array $spec The OAS payload.
	 *
	 * @return string The hex-encoded SHA-256 hash.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-7
	 */
	public function hash(array $spec): string {
		$canonical = $this->canonicalise(value: $spec);
		return hash('sha256', (string)json_encode($canonical));
	}//end hash()

	/**
	 * Test whether an `If-None-Match` header value matches the current ETag.
	 *
	 * Implements the RFC 7232 weak-comparison rule for `If-None-Match`:
	 * a `*` matches any current ETag; a list of ETags matches when any
	 * one is equal to the current. Whitespace is stripped.
	 *
	 * @param string $ifNoneMatch The raw `If-None-Match` header value.
	 * @param string $currentETag The currently computed ETag (quoted).
	 *
	 * @return bool True when a 304 response can be returned.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-7
	 */
	public function matches(string $ifNoneMatch, string $currentETag): bool {
		$needle = trim($ifNoneMatch);
		if ($needle === '') {
			return false;
		}

		if ($needle === '*') {
			return true;
		}

		foreach (explode(',', $needle) as $candidate) {
			if (self::withoutWeakPrefix(candidate: trim($candidate)) === $currentETag) {
				return true;
			}
		}

		return false;
	}//end matches()

	/**
	 * An entity tag without its optional weak prefix, per RFC 7232.
	 *
	 * @param string $candidate One trimmed tag from the header.
	 *
	 * @return string The tag.
	 */
	private static function withoutWeakPrefix(string $candidate): string {
		if (str_starts_with($candidate, 'W/') === true) {
			return substr($candidate, 2);
		}

		return $candidate;
	}//end withoutWeakPrefix()

	/**
	 * Recursively sort object keys to produce a deterministic JSON shape.
	 *
	 * @param mixed $value The value to canonicalise.
	 *
	 * @return mixed Canonical-shape value.
	 */
	private function canonicalise(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		// Numerically-indexed arrays (lists) keep their order;
		// associative arrays (objects) get sorted by key.
		if (array_is_list($value) === true) {
			return $this->canonicalList(list: $value);
		}

		return $this->canonicalObject(object: $value);
	}//end canonicalise()

	/**
	 * A list with each item canonicalised, in its own order.
	 *
	 * @param array<int, mixed> $list The list.
	 *
	 * @return array<int, mixed> The canonical list.
	 */
	private function canonicalList(array $list): array {
		$out = [];
		foreach ($list as $item) {
			$out[] = $this->canonicalise(value: $item);
		}

		return $out;
	}//end canonicalList()

	/**
	 * An object with its keys sorted and each value canonicalised.
	 *
	 * @param array<int|string, mixed> $object The object.
	 *
	 * @return array<int|string, mixed> The canonical object.
	 */
	private function canonicalObject(array $object): array {
		$keys = array_keys($object);
		sort($keys);

		$out = [];
		foreach ($keys as $key) {
			$out[$key] = $this->canonicalise(value: $object[$key]);
		}

		return $out;
	}//end canonicalObject()
}//end class
