<?php

/**
 * ReferenceNormaliser: reduces every $ref of a schema to a scalar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\SchemaMapper;

use Exception;
use OCA\OpenRegister\Db\Schema;

/**
 * Reduces each `$ref` in a schema's properties to a string or an integer.
 *
 * An empty-string `$ref` is not rejected, despite what the message says.
 * Tightening that would reject schemas that currently import.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class ReferenceNormaliser {

	/**
	 * Clean $ref properties to ensure they are strings.
	 *
	 * @param Schema $schema Schema to clean.
	 *
	 * @return void
	 *
	 * @throws Exception If a $ref is not a string or cannot be converted.
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function normalise(Schema $schema): void {
		$schema->setProperties($this->normalised(properties: $schema->getProperties()));
	}//end normalise()

	/**
	 * The given properties with each `$ref` reduced, array items and nested properties included.
	 *
	 * @param array<mixed> $properties The properties.
	 *
	 * @return array<mixed>
	 */
	private function normalised(array $properties): array {
		foreach ($properties as $key => $property) {
			if (is_array($property) === true) {
				$properties[$key] = $this->normalisedProperty(key: (string)$key, property: $property);
			}
		}

		return $properties;
	}//end normalised()

	/**
	 * One property with its `$ref` reduced and its nested levels walked.
	 *
	 * @param string       $key      The property name.
	 * @param array<mixed> $property The property definition.
	 *
	 * @return array<mixed>
	 */
	private function normalisedProperty(string $key, array $property): array {
		if (($property['$ref'] ?? null) !== null) {
			$property['$ref'] = $this->scalarReference(key: $key, reference: $property['$ref']);
		}

		foreach (['items', 'properties'] as $nested) {
			if (is_array(($property[$nested] ?? null)) === true) {
				$property[$nested] = $this->normalised(properties: $property[$nested]);
			}
		}

		return $property;
	}//end normalisedProperty()

	/**
	 * The id of an object-form reference, or a reference that already is a string or integer.
	 *
	 * @param string $key       The property name, for the message.
	 * @param mixed  $reference The `$ref` value.
	 *
	 * @return mixed
	 *
	 * @throws Exception When the reference is neither.
	 */
	private function scalarReference(string $key, mixed $reference): mixed {
		$identifier = $this->identifierIn(reference: $reference);
		if ($identifier !== null) {
			return $identifier;
		}

		if (is_int($reference) === true || is_string($reference) === true) {
			return $reference;
		}

		throw new Exception("Schema property '$key' has a \$ref that is not a string or empty: " . json_encode($reference));
	}//end scalarReference()

	/**
	 * The `id` an array or object reference holds, or null.
	 *
	 * @param mixed $reference The `$ref` value.
	 *
	 * @return mixed
	 */
	private function identifierIn(mixed $reference): mixed {
		if (is_array($reference) === true) {
			return ($reference['id'] ?? null);
		}

		if (is_object($reference) === true) {
			return ($reference->id ?? null);
		}

		return null;
	}//end identifierIn()
}//end class
