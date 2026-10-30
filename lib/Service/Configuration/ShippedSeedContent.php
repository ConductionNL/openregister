<?php

/**
 * What an import writes to a stored seed object whose shipped version did not move.
 *
 * Most seed objects ship without a `@self.version`, so an app that changes a
 * seed's content (a status type gaining its `phase`) never moves its version,
 * and the version gate keeps the stored object as it was. The content decides
 * instead: the import remembers the hash of the content it last shipped per
 * seed. When the shipped content differs from the remembered hash, it is a new
 * release of the seed and is written, as a version bump would be. When the
 * content is unchanged, the stored object keeps every value it holds and only
 * gains a field it lacks (an earlier write may have been refused). A seed with
 * no remembered hash cannot be told apart from an edited one, so it is treated
 * the same way.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * Decides, from the shipped content, what a re-import writes to a stored seed object.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class ShippedSeedContent {

	/**
	 * The hash of a seed's shipped content, its `@self` left out.
	 *
	 * @param array<string, mixed> $shipped The seed as listed.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function hash(array $shipped): string {
		unset($shipped['@self']);

		return sha1((string) json_encode($this->sorted(value: $shipped), (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
	}//end hash()

	/**
	 * The object to write over a stored seed, or null when the stored seed stays.
	 *
	 * @param array<string, mixed> $stored     The stored object, with `@self`.
	 * @param array<string, mixed> $shipped    The seed as listed, with `@self`.
	 * @param string|null          $remembered The hash of the content last shipped; null when none was kept.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function update(array $stored, array $shipped, ?string $remembered): ?array {
		if ($remembered !== null && $remembered !== $this->hash(shipped: $shipped)) {
			return $shipped;
		}

		$data    = $stored;
		$missing = false;
		unset($data['@self']);
		foreach ($shipped as $field => $value) {
			if ($field !== '@self' && ($data[$field] ?? null) === null && $value !== null) {
				$data[$field] = $value;
				$missing      = true;
			}
		}

		if ($missing === false) {
			return null;
		}

		$data['@self'] = $shipped['@self'];

		return $data;
	}//end update()

	/**
	 * A value with every object's keys in order, so equal content hashes equal.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return array_map(fn (mixed $item): mixed => $this->sorted(value: $item), $value);
	}//end sorted()
}//end class
