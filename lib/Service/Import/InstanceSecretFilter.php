<?php

/**
 * InstanceSecretFilter: leave secret values out of a serialised instance and say where.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
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

namespace OCA\OpenRegister\Service\Import;

/**
 * Walks a nested array and drops every entry whose key names a secret
 * (a password, token, API key, client secret, private key, credential or
 * authorization header), returning the cleaned array and the dotted path of
 * every entry it dropped.
 *
 * The paths are the record that secrets were excluded (D-5): a serialised
 * instance carries them so the absence of a value is not read as there
 * having been none. A path never carries the value itself.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
 */
class InstanceSecretFilter {

	/**
	 * Key fragments that name a secret, matched case-insensitively after
	 * removing `_`, `-`, `.` and spaces.
	 *
	 * @var list<string>
	 */
	private const SECRET_FRAGMENTS = [
		'password',
		'passwd',
		'passphrase',
		'secret',
		'token',
		'apikey',
		'privatekey',
		'credential',
		'authorization',
		'bearer',
		'jwt',
	];

	/**
	 * Whether a key names a secret.
	 *
	 * @param string|int $key The array key.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
	 */
	public function isSecretKey(string|int $key): bool {
		if (is_int($key) === true) {
			return false;
		}

		$normalised = strtolower(str_replace(['_', '-', '.', ' '], '', $key));
		foreach (self::SECRET_FRAGMENTS as $fragment) {
			if (str_contains($normalised, $fragment) === true) {
				return true;
			}
		}

		return false;
	}//end isSecretKey()

	/**
	 * The array without its secret entries, and the paths of the entries left out.
	 *
	 * @param array<array-key, mixed> $node The array to clean.
	 * @param string                  $path The dotted path of $node, '' at the root.
	 *
	 * @return array{0: array<array-key, mixed>, 1: list<string>} The cleaned array and the excluded paths.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
	 */
	public function strip(array $node, string $path=''): array {
		$clean    = [];
		$excluded = [];
		foreach ($node as $key => $value) {
			$at = $this->join(path: $path, key: $key);
			if ($this->isSecretKey(key: $key) === true && $this->holdsValue(value: $value) === true) {
				$excluded[] = $at;
				continue;
			}

			if (is_array($value) === true) {
				[$value, $inner] = $this->strip(node: $value, path: $at);
				$excluded        = array_merge($excluded, $inner);
			}

			$clean[$key] = $value;
		}

		return [$clean, $excluded];
	}//end strip()

	/**
	 * Whether a value under a secret-named key holds anything worth leaving out.
	 *
	 * An empty string, null or empty array carries no secret, and keeping it
	 * keeps the shape of the configuration (a `token: null` stays `null`).
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function holdsValue(mixed $value): bool {
		return $value !== null && $value !== '' && $value !== [];
	}//end holdsValue()

	/**
	 * A dotted path one key deeper.
	 *
	 * @param string     $path The parent path.
	 * @param string|int $key  The key.
	 *
	 * @return string
	 */
	private function join(string $path, string|int $key): string {
		if ($path === '') {
			return (string) $key;
		}

		return $path.'.'.$key;
	}//end join()
}//end class
