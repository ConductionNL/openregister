<?php

/**
 * OpenRegister PropertySourceLanguage
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * The source language of a translatable property: the object's
 * `_translationMeta.<property>.sourceLanguage`, else the schema property's
 * `sourceLanguage`, else the register default, else `nl`.
 *
 * @spec openspec/specs/i18n-source-of-truth/spec.md
 */
final class PropertySourceLanguage {
	/**
	 * @param Schema $schema          The schema declaring the properties.
	 * @param string $registerDefault Register-level default language, '' when unset.
	 */
	public function __construct(
		private readonly Schema $schema,
		private readonly string $registerDefault,
	) {
	}//end __construct()

	/**
	 * The source language of one property of an object body.
	 *
	 * @param array<string, mixed> $body     The object data, `_translationMeta` included.
	 * @param string               $property The translatable property name.
	 *
	 * @return string BCP-47 language tag.
	 */
	public function forProperty(array $body, string $property): string {
		$meta = $body['_translationMeta'] ?? null;
		if (is_array($meta) === false) {
			$meta = [];
		}

		$declared = $this->declared(entry: $meta[$property] ?? null)
			?? $this->declared(entry: ($this->schema->getProperties() ?? [])[$property] ?? null);
		if ($declared !== null) {
			return $declared;
		}

		if ($this->registerDefault !== '') {
			return $this->registerDefault;
		}

		return 'nl';
	}//end forProperty()

	/**
	 * The non-empty `sourceLanguage` string of a meta entry or property definition.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string|null
	 */
	private function declared(mixed $entry): ?string {
		if (is_array($entry) === false || is_string($entry['sourceLanguage'] ?? null) === false || $entry['sourceLanguage'] === '') {
			return null;
		}

		return $entry['sourceLanguage'];
	}//end declared()
}//end class
