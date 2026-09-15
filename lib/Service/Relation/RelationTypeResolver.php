<?php

/**
 * Resolves what a relation is called, from each side.
 *
 * A relation row on its own is a uuid and a property path. What a reverse
 * panel needs is the sentence: "blocked by case 2026-0042". That sentence is
 * two halves that live in different places. The near half is the property's
 * own label, and the far half is the inverse label the referencing schema
 * declares. This class reads both, resolves a vocabulary key to its entry,
 * applies the fallbacks, and hands back one descriptor a caller can render
 * without knowing any schema.
 *
 * It is memoised per schema for the length of a request (ADR-009): a reverse
 * lookup over four hundred rows resolves at most one descriptor set per
 * referencing schema, not one per row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\Schema;

/**
 * Resolves per-property relation descriptors from a schema.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
class RelationTypeResolver {
	/**
	 * What the far side reads when the referencing property declares nothing.
	 *
	 * @var string
	 */
	public const FALLBACK_INVERSE_LABEL = 'referenced by';

	/**
	 * The row travels from the object being read towards the object it names.
	 *
	 * @var string
	 */
	public const DIRECTION_OUTGOING = 'outgoing';

	/**
	 * The row travels from another object towards the object being read.
	 *
	 * @var string
	 */
	public const DIRECTION_INCOMING = 'incoming';

	/**
	 * Descriptor sets already resolved this request, keyed by schema and language.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $memo = [];

	/**
	 * Every relation descriptor a schema declares, keyed by property name.
	 *
	 * Every `$ref` property gets a descriptor, annotated or not, because a
	 * caller that has to ask "is there one" per row is a caller that will
	 * forget to. An unannotated property resolves to its own title and the
	 * generic fallback, which is exactly what the spec says it reads as.
	 *
	 * @param Schema|null $schema The schema owning the properties.
	 * @param string $language The BCP-47 tag to resolve labels in.
	 *
	 * @return array<string, array<string, mixed>> Descriptors, keyed by property name.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function descriptors(?Schema $schema, string $language = 'nl'): array {
		if ($schema === null) {
			return [];
		}

		$memoKey = ((string)($schema->getId() ?? $schema->getSlug() ?? spl_object_id($schema))).'|'.$language;
		if (isset($this->memo[$memoKey]) === true) {
			return $this->memo[$memoKey];
		}

		$vocabulary = $this->vocabularyOf(schema: $schema);
		$properties = ($schema->getProperties() ?? []);
		$descriptors = [];

		if (is_array($properties) === true) {
			foreach ($properties as $name => $property) {
				if (RelationAnnotationValidator::isReferenceProperty(property: $property) === false) {
					continue;
				}

				$descriptors[(string)$name] = $this->describe(
					name: (string)$name,
					property: $property,
					vocabulary: $vocabulary,
					language: $language
				);
			}
		}

		$this->memo[$memoKey] = $descriptors;

		return $descriptors;
	}//end descriptors()

	/**
	 * One property's descriptor, or null when the property holds no reference.
	 *
	 * @param Schema|null $schema The schema owning the property.
	 * @param string $property The property name, or a dot path whose first
	 *                         segment is the property name.
	 * @param string $language The BCP-47 tag to resolve labels in.
	 *
	 * @return array<string, mixed>|null The descriptor.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function descriptorFor(?Schema $schema, string $property, string $language = 'nl'): ?array {
		$descriptors = $this->descriptors(schema: $schema, language: $language);
		$name = self::propertyNameOf(path: $property);

		return ($descriptors[$name] ?? null);
	}//end descriptorFor()

	/**
	 * A descriptor turned into the row a client renders, for one direction.
	 *
	 * `displayLabel` is the whole point: a caller that has to pick between
	 * `label` and `inverseLabel` per direction is a caller that will pick the
	 * wrong one on the reverse panel, which is the bug this change exists to
	 * end. Both halves stay on the row so a caller that wants the other one
	 * still has it.
	 *
	 * @param array<string, mixed>|null $descriptor The resolved descriptor.
	 * @param string $direction One of the DIRECTION_* constants.
	 * @param string|null $path The stored relation path, when known.
	 *
	 * @return array<string, mixed> The relation row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function row(?array $descriptor, string $direction, ?string $path = null): array {
		if ($descriptor === null) {
			$fallbackProperty = null;
			if ($path !== null) {
				$fallbackProperty = self::propertyNameOf(path: $path);
			}

			$descriptor = [
				'property' => $fallbackProperty,
				'type' => null,
				'label' => $fallbackProperty,
				'inverseLabel' => self::FALLBACK_INVERSE_LABEL,
				'symmetric' => false,
				'inherits' => [],
			];
		}

		$display = ($descriptor['label'] ?? null);
		if ($direction === self::DIRECTION_INCOMING) {
			$display = ($descriptor['inverseLabel'] ?? self::FALLBACK_INVERSE_LABEL);
		}

		return array_merge(
			$descriptor,
			[
				'direction' => $direction,
				'displayLabel' => $display,
				'path' => $path,
			]
		);
	}//end row()

	/**
	 * The property name a stored relation path belongs to.
	 *
	 * Paths are stored as `blocks`, `blocks.0` or `deelzaken.2.zaak`, and only
	 * the first segment is a property of the schema.
	 *
	 * @param string $path The stored relation path.
	 *
	 * @return string The property name.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public static function propertyNameOf(string $path): string {
		$segments = explode('.', $path);

		return (string)($segments[0] ?? $path);
	}//end propertyNameOf()

	/**
	 * Build one property's descriptor.
	 *
	 * @param string $name The property name.
	 * @param mixed $property The property definition.
	 * @param array<string, array<string, mixed>> $vocabulary The schema's relation types.
	 * @param string $language The BCP-47 tag.
	 *
	 * @return array<string, mixed> The descriptor.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function describe(string $name, mixed $property, array $vocabulary, string $language): array {
		$declaration = RelationAnnotationValidator::declarationOf(property: $property);
		if (is_array($declaration) === false) {
			$declaration = [];
		}

		$type = ($declaration['type'] ?? null);
		$entry = [];
		if (is_string($type) === true && isset($vocabulary[trim($type)]) === true) {
			$entry = $vocabulary[trim($type)];
			$type = trim($type);
		} else {
			$type = null;
		}

		// The property's own declaration wins over the vocabulary entry it
		// points at, so one property can share a vocabulary and still say
		// something of its own.
		$merged = array_merge($entry, $declaration);

		$symmetric = ($merged['symmetric'] ?? false);
		if (is_bool($symmetric) === false) {
			$symmetric = false;
		}

		$label = $this->text(value: ($merged['label'] ?? null), language: $language);
		if ($label === null) {
			$label = $this->titleOf(property: $property) ?? $name;
		}

		$inverse = $this->text(value: ($merged['inverseLabel'] ?? null), language: $language);
		if ($symmetric === true) {
			// A symmetric relation reads the same from both ends. The save-time
			// refusal keeps an inverseLabel out of a symmetric declaration, so
			// this only has to hold for rows written before that refusal.
			$inverse = $label;
		}

		if ($inverse === null) {
			$inverse = self::FALLBACK_INVERSE_LABEL;
		}

		return [
			'property' => $name,
			'type' => $type,
			'label' => $label,
			'inverseLabel' => $inverse,
			'symmetric' => $symmetric,
			'inherits' => $this->inheritsOf(declaration: $merged),
		];
	}//end describe()

	/**
	 * The inheritance a declaration asks for, as role to property name.
	 *
	 * The list form `["classification"]` names a property called
	 * `classification`; the map form points the role at whatever the schema
	 * actually calls it. Both resolve to the same shape so no caller has to
	 * know which was written.
	 *
	 * @param array<string, mixed> $declaration The merged declaration.
	 *
	 * @return array<string, string> Role to property name.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function inheritsOf(array $declaration): array {
		$inherits = ($declaration['inherits'] ?? null);
		if (is_object($inherits) === true) {
			$inherits = (array)$inherits;
		}

		if (is_array($inherits) === false) {
			return [];
		}

		$resolved = [];
		foreach ($inherits as $key => $value) {
			if (is_int($key) === true) {
				if (is_string($value) === true
					&& in_array($value, RelationAnnotationValidator::INHERITABLE, true) === true
				) {
					$resolved[$value] = $value;
				}
				continue;
			}

			// $key is a string by elimination: the int branch above continues,
			// and an array key is int or string.
			if (is_string($value) === true
				&& trim($value) !== ''
				&& in_array($key, RelationAnnotationValidator::INHERITABLE, true) === true
			) {
				$resolved[$key] = trim($value);
			}
		}

		return $resolved;
	}//end inheritsOf()

	/**
	 * The schema's relation vocabulary, keyed by key.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function vocabularyOf(Schema $schema): array {
		$configuration = ($schema->getConfiguration() ?? []);
		$raw = ($configuration[RelationAnnotationValidator::VOCABULARY_ANNOTATION] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$entries = [];
		foreach ($raw as $entry) {
			if (is_object($entry) === true) {
				$entry = (array)$entry;
			}

			if (is_array($entry) === false) {
				continue;
			}

			$key = ($entry['key'] ?? null);
			if (is_string($key) === false || trim($key) === '') {
				continue;
			}

			$entries[trim($key)] = $entry;
		}

		return $entries;
	}//end vocabularyOf()

	/**
	 * Resolve a label to text in one language.
	 *
	 * A plain string is the label in every language, which is what an
	 * organisation with one language writes. A map is resolved by exact tag,
	 * then primary subtag, then Dutch, then English, then whatever is there:
	 * a label in the wrong language beats no label, which is the fallback and
	 * says nothing at all.
	 *
	 * @param mixed $value The declared label.
	 * @param string $language The BCP-47 tag asked for.
	 *
	 * @return string|null The resolved text.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function text(mixed $value, string $language): ?string {
		if (is_string($value) === true) {
			$value = trim($value);
			if ($value === '') {
				return null;
			}

			return $value;
		}

		if (is_object($value) === true) {
			$value = (array)$value;
		}

		if (is_array($value) === false) {
			return null;
		}

		foreach ([$language, substr($language, 0, 2), 'nl', 'en'] as $tag) {
			$candidate = ($value[$tag] ?? null);
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		foreach ($value as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return null;
	}//end text()

	/**
	 * A property's own title, when it has one.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return string|null The title.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function titleOf(mixed $property): ?string {
		if (is_object($property) === true) {
			$property = (array)$property;
		}

		if (is_array($property) === false) {
			return null;
		}

		$title = ($property['title'] ?? null);
		if (is_string($title) === true && trim($title) !== '') {
			return trim($title);
		}

		return null;
	}//end titleOf()
}//end class
