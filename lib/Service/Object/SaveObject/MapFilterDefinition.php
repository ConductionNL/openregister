<?php

/**
 * OpenRegister MapFilterDefinition
 *
 * The parsed "key1=val1, key2=val2" of a metadata template's map filter.
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

/**
 * A map filter definition: field values and what each renders as.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class MapFilterDefinition {

	/**
	 * The trimmed keys and values; entries without '=' are skipped.
	 *
	 * @var array<string, string>
	 */
	private array $map;

	/**
	 * Constructor.
	 *
	 * @param string $definition The map definition, e.g. "key1=val1, key2=val2".
	 */
	public function __construct(string $definition) {
		$map = [];
		foreach (array_map('trim', explode(',', $definition)) as $mapping) {
			$parts = explode('=', $mapping, 2);
			if (count($parts) === 2) {
				$map[trim($parts[0])] = trim($parts[1]);
			}
		}

		$this->map = $map;
	}//end __construct()

	/**
	 * The first mapped value.
	 *
	 * @return string|null The value, or null for a definition without mappings.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function firstValue(): ?string {
		$firstKey = array_key_first($this->map);
		if ($firstKey === null) {
			return null;
		}

		return $this->map[$firstKey];
	}//end firstValue()

	/**
	 * The mapped value of a field value.
	 *
	 * @param string $value The trimmed field value.
	 *
	 * @return string The mapped value, or the field value itself when it is not mapped.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function lookup(string $value): string {
		return $this->map[$value] ?? $value;
	}//end lookup()
}//end class
