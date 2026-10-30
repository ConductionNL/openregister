<?php

/**
 * Keeps a form submit from naming an existing object through a cascading property.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\Schema;

/**
 * Drops the identity keys of the nested objects under a schema's cascading properties.
 *
 * The mapping drops the top-level `id`, but a property that cascades (its
 * definition, or its `items`, has a `$ref` or an `inversedBy`) saves each
 * nested object through the cascade, which takes the nested `id` (or
 * `@self.id`) as the uuid of an existing object and updates it. A submitter
 * who names one would write over that object, so no nested identity under a
 * cascading property survives, however deep. Data under a property that does
 * not cascade is left alone.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
class NestedIdentityGuard {

	/**
	 * The object without the identity keys of its cascading properties' nested objects.
	 *
	 * @param array<string, mixed> $object The mapped object.
	 * @param Schema               $schema Its destination schema.
	 *
	 * @return array<string, mixed> The object, safe to save.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function strip(array $object, Schema $schema): array {
		foreach ((array)$schema->getProperties() as $name => $definition) {
			if (is_array($definition) === true && is_array($object[$name] ?? null) === true && $this->cascades(definition: $definition) === true) {
				$object[$name] = $this->withoutIdentity(value: $object[$name]);
			}
		}

		return $object;
	}//end strip()

	/**
	 * Whether a property definition, or its `items`, cascades.
	 *
	 * @param array<string, mixed> $definition The property definition.
	 *
	 * @return bool True when it carries a `$ref` or an `inversedBy`.
	 */
	private function cascades(array $definition): bool {
		$items = [];
		if (is_array($definition['items'] ?? null) === true) {
			$items = $definition['items'];
		}

		foreach ([$definition, $items] as $level) {
			if (isset($level['$ref']) === true || isset($level['inversedBy']) === true) {
				return true;
			}
		}

		return false;
	}//end cascades()

	/**
	 * Remove `id` and `@self.id` from a value and from every array nested in it.
	 *
	 * @param array<mixed> $value A nested object, or a list of them.
	 *
	 * @return array<mixed> The value without identity keys.
	 */
	private function withoutIdentity(array $value): array {
		unset($value['id']);
		if (is_array($value['@self'] ?? null) === true) {
			unset($value['@self']['id']);
		}

		foreach ($value as $key => $nested) {
			if (is_array($nested) === true) {
				$value[$key] = $this->withoutIdentity(value: $nested);
			}
		}

		return $value;
	}//end withoutIdentity()
}//end class
