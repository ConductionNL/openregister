<?php

/**
 * OpenRegister LanguageMapText
 *
 * The one string a translatable property's language map denormalises to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\LanguageKeyedMap;

/**
 * A language map, read for its first filled value.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class LanguageMapText {

	/**
	 * Constructor.
	 *
	 * @param array $value The language map, keyed by language tag.
	 */
	public function __construct(
		private array $value,
	) {
	}//end __construct()

	/**
	 * Whether every key is a BCP 47 language tag, as LanguageKeyedMap decides
	 * it for TranslationHandler too.
	 *
	 * @return bool True when the array is keyed by language tags.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isLanguageKeyed(): bool {
		return (new LanguageKeyedMap(value: $this->value))->isLanguageKeyed();
	}//end isLanguageKeyed()

	/**
	 * The first filled value, in the order the author declared the languages.
	 *
	 * @return string|null The value, or null for a nested map or when every language is empty.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function firstValue(): ?string {
		// A nested map is not a language value: leave it all to json_encode.
		foreach ($this->value as $entry) {
			if (is_scalar($entry) === false) {
				return null;
			}

			if (trim((string)$entry) !== '') {
				return (string)$entry;
			}
		}

		// All languages empty is no name; `{"nl":""}` must not reach the page.
		return null;
	}//end firstValue()
}//end class
