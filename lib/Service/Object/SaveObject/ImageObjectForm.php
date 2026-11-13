<?php

/**
 * OpenRegister ImageObjectForm
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * Writes every `image-url` value in its object form: a bare URL becomes `{ url }`.
 *
 * The value has passed validation before this runs, so a string here is a
 * valid URL. Only top-level properties are rewritten, the depth the schema
 * declares a type at.
 *
 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
 */
final class ImageObjectForm {

	/**
	 * The data with each bare-URL `image-url` value in its object form.
	 *
	 * @param array $data       The object data.
	 * @param array $properties The schema's property definitions, keyed by property name.
	 *
	 * @return array The data.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public function apply(array $data, array $properties): array {
		foreach ($properties as $name => $definition) {
			if (is_array($definition) === false || ($definition['type'] ?? null) !== 'image-url') {
				continue;
			}

			if (is_string($data[$name] ?? null) === true) {
				$data[$name] = ['url' => $data[$name]];
			}
		}

		return $data;
	}//end apply()
}//end class
