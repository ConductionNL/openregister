<?php

/**
 * OpenRegister MappingSlug
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Mapping
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

namespace OCA\OpenRegister\Db\Mapping;

/**
 * The slug a mapping without a stored slug reads as.
 *
 * The name is transliterated to ASCII where the PHP build can (intl, else
 * iconv), lower-cased, and every run of other characters becomes one hyphen.
 * A name that leaves nothing falls back to `mapping-<id>`, or to a random
 * `mapping-<8 hex>` when there is no id yet.
 *
 * @spec exclude mapping plumbing moved out of the entity, not a capability of its own
 */
final class MappingSlug {

	/**
	 * The generated slug.
	 *
	 * @param string|null $name The mapping's name.
	 * @param mixed       $id   The mapping's id.
	 *
	 * @return string
	 *
	 * @spec exclude mapping plumbing moved out of the entity, not a capability of its own
	 */
	public function generated(string|null $name, mixed $id): string {
		$name = trim((string)($name ?? ''));
		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($this->ascii(name: $name))), '-');
		if ($slug !== '') {
			return $slug;
		}

		return $this->fallback(name: $name, id: $id);
	}//end generated()

	/**
	 * The name transliterated to ASCII, or as it is when the build cannot.
	 *
	 * @param string $name The trimmed name.
	 *
	 * @return string
	 */
	private function ascii(string $name): string {
		if ($name === '') {
			return $name;
		}

		if (class_exists('\Transliterator') === true) {
			return $this->transliterated(name: $name);
		}

		if (function_exists('iconv') === true) {
			return $this->iconvAscii(name: $name);
		}

		return $name;
	}//end ascii()

	/**
	 * The name through iconv's ASCII//TRANSLIT//IGNORE, its notices silenced, or as it is when iconv fails.
	 *
	 * @param string $name The trimmed name.
	 *
	 * @return string
	 */
	private function iconvAscii(string $name): string {
		set_error_handler(static fn (): bool => true);
		try {
			$converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
		} finally {
			restore_error_handler();
		}

		if ($converted === false) {
			return $name;
		}

		return $converted;
	}//end iconvAscii()

	/**
	 * The name through intl's Any-Latin; Latin-ASCII transliterator, when it can be created.
	 *
	 * @param string $name The trimmed name.
	 *
	 * @return string
	 *
	 * @psalm-suppress UndefinedFunction transliterator_create is in the optional intl extension, checked by the caller
	 */
	private function transliterated(string $name): string {
		$transliterator = transliterator_create('Any-Latin; Latin-ASCII');
		if ($transliterator === null) {
			return $name;
		}

		return (string)$transliterator->transliterate($name);
	}//end transliterated()

	/**
	 * `mapping-<id>`, or a random suffix when there is no id yet.
	 *
	 * @param string $name The trimmed name.
	 * @param mixed  $id   The mapping's id.
	 *
	 * @return string
	 */
	private function fallback(string $name, mixed $id): string {
		if ((string)$id !== '') {
			return 'mapping-' . (string)$id;
		}

		try {
			return 'mapping-' . bin2hex(random_bytes(4));
		} catch (\Exception $e) {
			// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only derives a stable identifier, not a security value.
			return 'mapping-' . substr(md5($name), 0, 8);
		}
	}//end fallback()
}//end class
