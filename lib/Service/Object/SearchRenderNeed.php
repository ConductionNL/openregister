<?php

/**
 * SearchRenderNeed: whether the rows of a search must take the render path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * Decides whether list rows can skip the full render.
 *
 * Skipping it is only sound when nothing asked for it and no schema needs it:
 * property authorization and lenses are resolved while an object is rendered,
 * so a list would otherwise answer differently from a single read.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchRenderNeed {
	/**
	 * Whether the query asks for what only rendering gives.
	 *
	 * @param array $query  The search query.
	 * @param array $extend The extends that apply to a row.
	 *
	 * @return bool True when it does.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function asked(array $query, array $extend): bool {
		return empty($extend) === false
			|| empty($query['_fields'] ?? null) === false
			|| empty($query['_filter'] ?? null) === false
			|| empty($query['_unset'] ?? null) === false;
	}//end asked()

	/**
	 * Whether any of the schemas declares property authorization or a lens.
	 *
	 * @param array<int|string, mixed> $schemas Schema entities or serialised schemas.
	 *
	 * @return bool True when one does.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function declaredBy(array $schemas): bool {
		foreach ($schemas as $schema) {
			if ($this->declaresAuthorization(schema: $schema) === true || $this->declaresLens(schema: $schema) === true) {
				return true;
			}
		}

		return false;
	}//end declaredBy()

	/**
	 * Whether one schema declares property authorization.
	 *
	 * @param mixed $schema A schema entity or a serialised schema.
	 *
	 * @return bool True when it does.
	 */
	private function declaresAuthorization(mixed $schema): bool {
		if ($schema instanceof Schema) {
			return $schema->hasPropertyAuthorization();
		}

		return is_array($schema) === true && $this->propertiesDeclareAuthorization(properties: ($schema['properties'] ?? null));
	}//end declaresAuthorization()

	/**
	 * Whether a serialised schema's properties declare authorization.
	 *
	 * @param mixed $properties The properties.
	 *
	 * @return bool True when one does.
	 */
	private function propertiesDeclareAuthorization(mixed $properties): bool {
		foreach ($properties ?? [] as $propertyConfig) {
			if (is_array($propertyConfig) === true && empty($propertyConfig['authorization'] ?? null) === false) {
				return true;
			}
		}

		return false;
	}//end propertiesDeclareAuthorization()

	/**
	 * Whether one schema declares a lens.
	 *
	 * @param mixed $schema A schema entity or a serialised schema.
	 *
	 * @return bool True when it does.
	 */
	private function declaresLens(mixed $schema): bool {
		if ($schema instanceof Schema) {
			return $schema->getLenses() !== [];
		}

		return is_array($schema) === true && empty($schema['configuration'][Schema::LENS_ANNOTATION] ?? null) === false;
	}//end declaresLens()
}//end class
