<?php

/**
 * Which selectielijst category applies to one record.
 *
 * The category is declared on the schema, and a schema may name an object
 * property through which one record overrides it (DECISIONS row 48: "record
 * management and selectielijst should be on schema type, and possible to
 * overwrite per object"). Pure: no store, no session, so every caller decides
 * the same way and a test can call it without wiring.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * Resolves the schema's category and a record's override of it.
 */
class ClassificationOverride {

	/**
	 * The object property a schema lets a record override its category with.
	 *
	 * `archive.classificationProperty` in the archive block, or
	 * `categoryProperty` in an `x-openregister-archival` block, the same
	 * pairing as `classification` and `category`.
	 *
	 * @param array<string, mixed> $archive       The schema's archive block.
	 * @param array<string, mixed> $configuration The schema's configuration.
	 *
	 * @return string|null The property name, or null when the schema allows no override.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function propertyOf(array $archive, array $configuration = []): ?string {
		$property = ($archive['classificationProperty'] ?? null);
		if ($this->text(value: $property) === null) {
			$annotation = ($configuration['x-openregister-archival'] ?? null);
			$property   = null;
			if (is_array($annotation) === true) {
				$property = ($annotation['categoryProperty'] ?? null);
			}
		}

		return $this->text(value: $property);
	}//end propertyOf()

	/**
	 * The value a record gives the override property, if it gives one.
	 *
	 * An absent key, null or a blank string is no override. Anything else is
	 * returned as given, so the caller can refuse a value that is not text.
	 *
	 * @param string|null          $property The override property, if declared.
	 * @param array<string, mixed> $data     The record's data.
	 *
	 * @return mixed The requested override, or null when the record asks for none.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function requested(?string $property, array $data): mixed {
		if ($property === null || array_key_exists($property, $data) === false) {
			return null;
		}

		$value = $data[$property];
		if ($value === null || (is_string($value) === true && trim($value) === '')) {
			return null;
		}

		if (is_string($value) === true) {
			return trim($value);
		}

		return $value;
	}//end requested()

	/**
	 * The category that applies: the record's override, else the schema's.
	 *
	 * @param array<string, mixed> $archive       The schema's archive block.
	 * @param array<string, mixed> $data          The record's data.
	 * @param array<string, mixed> $configuration The schema's configuration.
	 *
	 * @return string|null The effective category, or null when neither declares one.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function effective(array $archive, array $data, array $configuration = []): ?string {
		$requested = $this->requested(
			property: $this->propertyOf(archive: $archive, configuration: $configuration),
			data: $data
		);
		if (is_string($requested) === true) {
			return $requested;
		}

		return $this->text(value: ($archive['classification'] ?? null));
	}//end effective()

	/**
	 * A non-empty trimmed string, or null.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string|null The text.
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end text()
}//end class
