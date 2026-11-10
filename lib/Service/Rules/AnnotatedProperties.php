<?php

/**
 * OpenRegister AnnotatedProperties
 *
 * Finds the properties of a schema that carry one annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * The one scan every per-property annotation starts with.
 *
 * A property counts as annotated when its declaration is a map and carries
 * the annotation with a non-null value, which is what `isset` answers.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AnnotatedProperties {

	/**
	 * Whether any property carries the annotation.
	 *
	 * Asked first on the save paths, so a schema declaring none costs one
	 * array scan and nothing else.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 * @param string               $annotation The annotation key.
	 *
	 * @return bool True when at least one property carries it.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function declares(array $properties, string $annotation): bool {
		return $this->annotations(properties: $properties, annotation: $annotation) !== [];
	}//end declares()

	/**
	 * The annotation's value on every property that carries it, keyed by property name.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 * @param string               $annotation The annotation key.
	 *
	 * @return array<string, mixed> Property name => annotation value, in declaration order.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function annotations(array $properties, string $annotation): array {
		$annotated = [];
		foreach ($properties as $name => $property) {
			if (is_array($property) === true && isset($property[$annotation]) === true) {
				$annotated[$name] = $property[$annotation];
			}
		}

		return $annotated;
	}//end annotations()
}//end class
