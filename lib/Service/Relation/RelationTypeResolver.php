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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * Reason: resolution IS a fallback chain. A label may be inline or a
 * vocabulary key, a string or a per-language map, and when none of those
 * answers it falls back to the property's title and then to its name. Each
 * branch is one of those cases, and removing any of them means a relation
 * somewhere reads as nothing.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * Reason: RelationAnnotationValidator::declarationOf() and
 * isReferenceProperty() are pure functions over the annotation shape, shared
 * by the validator that refuses it and the resolver that reads it. Injecting
 * the validator here would be an instance held for no state, and duplicating
 * the two readers is how the refusal and the resolution drift apart.
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

		$memoKey = self::memoKey(schema: $schema, language: $language);
		if (isset($this->memo[$memoKey]) === false) {
			$this->memo[$memoKey] = $this->describeAll(schema: $schema, language: $language);
		}

		return $this->memo[$memoKey];
	}//end descriptors()


	/**
	 * The key a schema's descriptor set is memoised under, per language.
	 *
	 * @param Schema $schema   The schema.
	 * @param string $language The BCP-47 tag.
	 *
	 * @return string The memo key.
	 */
	private static function memoKey(Schema $schema, string $language): string {
		return ((string)($schema->getId() ?? $schema->getSlug() ?? spl_object_id($schema))).'|'.$language;
	}//end memoKey()

	/**
	 * Build the descriptor of every reference property a schema declares.
	 *
	 * @param Schema $schema   The schema owning the properties.
	 * @param string $language The BCP-47 tag to resolve labels in.
	 *
	 * @return array<string, array<string, mixed>> Descriptors, keyed by property name.
	 */
	private function describeAll(Schema $schema, string $language): array {
		$vocabulary = $this->vocabularyOf(schema: $schema);
		$properties = ($schema->getProperties() ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		$descriptors = [];
		foreach ($properties as $name => $property) {
			if (RelationAnnotationValidator::isReferenceProperty(property: $property) === true) {
				$descriptors[(string)$name] = $this->describe(name: (string)$name, property: $property, vocabulary: $vocabulary, language: $language);
			}
		}

		return $descriptors;
	}//end describeAll()

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
		$descriptor = ($descriptor ?? self::fallbackDescriptor(path: $path));

		return array_merge(
			$descriptor,
			[
				'direction' => $direction,
				'displayLabel' => self::displayLabel(descriptor: $descriptor, direction: $direction),
				'path' => $path,
			]
		);
	}//end row()


	/**
	 * The descriptor a row reads under when its property declares nothing resolvable.
	 *
	 * @param string|null $path The stored relation path, when known.
	 *
	 * @return array<string, mixed> The fallback descriptor.
	 */
	private static function fallbackDescriptor(?string $path): array {
		$fallbackProperty = null;
		if ($path !== null) {
			$fallbackProperty = self::propertyNameOf(path: $path);
		}

		return [
			'property' => $fallbackProperty,
			'type' => null,
			'label' => $fallbackProperty,
			'inverseLabel' => self::FALLBACK_INVERSE_LABEL,
			'symmetric' => false,
			'inherits' => [],
		];
	}//end fallbackDescriptor()

	/**
	 * The label a row shows in one direction.
	 *
	 * @param array<string, mixed> $descriptor The descriptor.
	 * @param string               $direction  One of the DIRECTION_* constants.
	 *
	 * @return mixed The forward label outgoing, the inverse label incoming.
	 */
	private static function displayLabel(array $descriptor, string $direction): mixed {
		if ($direction === self::DIRECTION_INCOMING) {
			return ($descriptor['inverseLabel'] ?? self::FALLBACK_INVERSE_LABEL);
		}

		return ($descriptor['label'] ?? null);
	}//end displayLabel()

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
		$declaration = self::declarationArray(property: $property);

		// A `type` resolves only when the vocabulary actually holds it. One
		// that does not is dropped rather than carried, so a property naming a
		// key nobody declared reads as the fallback and never as a half-resolved
		// entry. The save-time refusal keeps that case out of new schemas.
		$type = self::vocabularyType(declared: ($declaration['type'] ?? null), vocabulary: $vocabulary);

		// The property's own declaration wins over the vocabulary entry it
		// points at, so one property can share a vocabulary and still say
		// something of its own.
		$merged = array_merge(self::entryOf(type: $type, vocabulary: $vocabulary), $declaration);

		return self::withExposure(
			descriptor: ['property' => $name, 'type' => $type] + $this->readingOf(name: $name, property: $property, merged: $merged, language: $language),
			merged: $merged
		);
	}//end describe()


	/**
	 * How a relation reads: its two labels, whether it is symmetric, and what it inherits.
	 *
	 * @param string               $name     The property name.
	 * @param mixed                $property The property definition.
	 * @param array<string, mixed> $merged   The vocabulary entry under the property's own declaration.
	 * @param string               $language The BCP-47 tag.
	 *
	 * @return array{label: string, inverseLabel: string, symmetric: bool, inherits: array<string, string>} The reading.
	 */
	private function readingOf(string $name, mixed $property, array $merged, string $language): array {
		$symmetric = self::symmetricOf(merged: $merged);
		$labels    = $this->labelsFor(name: $name, property: $property, merged: $merged, symmetric: $symmetric, language: $language);

		return [
			'label' => $labels['label'],
			'inverseLabel' => $labels['inverse'],
			'symmetric' => $symmetric,
			'inherits' => $this->inheritsOf(declaration: $merged),
		];
	}//end readingOf()

	/**
	 * A property's own relation declaration, as an array; empty when it declares none.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private static function declarationArray(mixed $property): array {
		$declaration = RelationAnnotationValidator::declarationOf(property: $property);
		if (is_array($declaration) === false) {
			return [];
		}

		return $declaration;
	}//end declarationArray()

	/**
	 * The vocabulary key a declared `type` resolves to, or null when the vocabulary does not hold it.
	 *
	 * @param mixed                               $declared   The declared type.
	 * @param array<string, array<string, mixed>> $vocabulary The schema's relation types.
	 *
	 * @return string|null The resolved key.
	 */
	private static function vocabularyType(mixed $declared, array $vocabulary): ?string {
		if (is_string($declared) === true && isset($vocabulary[trim($declared)]) === true) {
			return trim($declared);
		}

		return null;
	}//end vocabularyType()

	/**
	 * The vocabulary entry of a resolved type; empty when there is none.
	 *
	 * @param string|null                         $type       The resolved key.
	 * @param array<string, array<string, mixed>> $vocabulary The schema's relation types.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private static function entryOf(?string $type, array $vocabulary): array {
		if ($type === null) {
			return [];
		}

		return $vocabulary[$type];
	}//end entryOf()

	/**
	 * Whether a merged declaration reads the same from both ends; anything but a boolean reads as no.
	 *
	 * @param array<string, mixed> $merged The vocabulary entry under the property's own declaration.
	 *
	 * @return bool True when symmetric.
	 */
	private static function symmetricOf(array $merged): bool {
		return (($merged['symmetric'] ?? false) === true);
	}//end symmetricOf()

	/**
	 * The descriptor with what the link exposes, when that is declared.
	 *
	 * What the link exposes rides the descriptor rather than being read
	 * from the vocabulary a second time. There is one reader of
	 * `x-openregister-relation-types` and it is this class; a render path
	 * that parsed the annotation for itself would be a second reader of one
	 * vocabulary, which is the thing this resolver exists to prevent.
	 *
	 * The key is added only when it is DECLARED. An absent key means the
	 * link narrows nothing, which is what every relation type does today
	 * and what every existing schema must keep doing; a present-but-empty
	 * list means it exposes nothing, which is a different statement and a
	 * legitimate one. Writing an empty list for "undeclared" would turn
	 * every existing link into one that hands over nothing.
	 *
	 * @param array<string, mixed> $descriptor The descriptor so far.
	 * @param array<string, mixed> $merged     The merged declaration.
	 *
	 * @return array<string, mixed> The descriptor.
	 */
	private static function withExposure(array $descriptor, array $merged): array {
		if (is_array($merged[LinkExposure::KEY] ?? null) === false) {
			return $descriptor;
		}

		$descriptor[LinkExposure::KEY] = array_values(
			array_map(static fn (mixed $property): string => (string)$property, $merged[LinkExposure::KEY])
		);

		return $descriptor;
	}//end withExposure()

	/**
	 * The label and inverse label one relation reads under, in one language.
	 *
	 * Neither may come back null. A relation whose forward label fell through
	 * to nothing would render as a blank chip, and an inverse that did would
	 * render the other end of the same link as a blank one.
	 *
	 * @param string               $name      The property name.
	 * @param mixed                $property  The property definition.
	 * @param array<string, mixed> $merged    The vocabulary entry under the property's own declaration.
	 * @param boolean              $symmetric Whether the relation reads the same from both ends.
	 * @param string               $language  The BCP-47 tag.
	 *
	 * @return array{label: string, inverse: string} The two labels.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function labelsFor(string $name, mixed $property, array $merged, bool $symmetric, string $language): array {
		$label = ($this->text(value: ($merged['label'] ?? null), language: $language) ?? $this->titleOf(property: $property) ?? $name);

		$inverse = $this->text(value: ($merged['inverseLabel'] ?? null), language: $language);
		if ($symmetric === true) {
			// A symmetric relation reads the same from both ends. The save-time
			// refusal keeps an inverseLabel out of a symmetric declaration, so
			// this only has to hold for rows written before that refusal.
			$inverse = $label;
		}

		return [
			'label' => $label,
			'inverse' => ($inverse ?? self::FALLBACK_INVERSE_LABEL),
		];
	}//end labelsFor()

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
		$inherits = self::asArray(value: ($declaration['inherits'] ?? null));
		if (is_array($inherits) === false) {
			return [];
		}

		$resolved = [];
		foreach ($inherits as $key => $value) {
			$resolved = array_merge($resolved, self::inheritedEntry(key: $key, value: $value));
		}

		return $resolved;
	}//end inheritsOf()


	/**
	 * One inheritance entry resolved to role => property, or nothing when it is not valid.
	 *
	 * @param int|string $key   The list position, or the role in the map form.
	 * @param mixed      $value The role in the list form, or the property in the map form.
	 *
	 * @return array<string, string> The resolved entry, or an empty array.
	 */
	private static function inheritedEntry(int|string $key, mixed $value): array {
		if (is_int($key) === true) {
			return self::listedInheritance(value: $value);
		}

		return self::mappedInheritance(role: $key, value: $value);
	}//end inheritedEntry()

	/**
	 * A list-form entry: a role inherited from a property of the same name.
	 *
	 * @param mixed $value The role.
	 *
	 * @return array<string, string> The resolved entry, or an empty array.
	 */
	private static function listedInheritance(mixed $value): array {
		if (is_string($value) === true
			&& in_array($value, RelationAnnotationValidator::INHERITABLE, true) === true
		) {
			return [$value => $value];
		}

		return [];
	}//end listedInheritance()

	/**
	 * A map-form entry: a role pointed at whatever the schema calls the property.
	 *
	 * @param string $role  The role.
	 * @param mixed  $value The property name.
	 *
	 * @return array<string, string> The resolved entry, or an empty array.
	 */
	private static function mappedInheritance(string $role, mixed $value): array {
		if (is_string($value) === true
			&& trim($value) !== ''
			&& in_array($role, RelationAnnotationValidator::INHERITABLE, true) === true
		) {
			return [$role => trim($value)];
		}

		return [];
	}//end mappedInheritance()

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
		$raw = (($schema->getConfiguration() ?? [])[RelationAnnotationValidator::VOCABULARY_ANNOTATION] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$entries = [];
		foreach ($raw as $entry) {
			$entry = self::asArray(value: $entry);
			$key   = self::entryKey(entry: $entry);
			if ($key !== null) {
				$entries[$key] = $entry;
			}
		}

		return $entries;
	}//end vocabularyOf()


	/**
	 * The trimmed key of a vocabulary entry, or null when it is not an entry with a key.
	 *
	 * @param mixed $entry The entry, objects already turned into arrays.
	 *
	 * @return string|null The key.
	 */
	private static function entryKey(mixed $entry): ?string {
		if (is_array($entry) === false) {
			return null;
		}

		return self::nonBlank(value: ($entry['key'] ?? null));
	}//end entryKey()

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
			return self::nonBlank(value: $value);
		}

		$value = self::asArray(value: $value);
		if (is_array($value) === false) {
			return null;
		}

		$preferred = array_map(
			static fn (string $tag): mixed => ($value[$tag] ?? null),
			[$language, substr($language, 0, 2), 'nl', 'en']
		);

		return (self::firstNonBlank(candidates: $preferred) ?? self::firstNonBlank(candidates: $value));
	}//end text()


	/**
	 * The first candidate that is a non-blank string, trimmed.
	 *
	 * @param array<mixed> $candidates The candidates, in order of preference.
	 *
	 * @return string|null The text.
	 */
	private static function firstNonBlank(array $candidates): ?string {
		foreach ($candidates as $candidate) {
			$text = self::nonBlank(value: $candidate);
			if ($text !== null) {
				return $text;
			}
		}

		return null;
	}//end firstNonBlank()

	/**
	 * A value as trimmed text, or null when it is not a string or is blank.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 */
	private static function nonBlank(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end nonBlank()

	/**
	 * A decoded JSON object as an array; any other value as it is.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function asArray(mixed $value): mixed {
		if (is_object($value) === true) {
			return (array)$value;
		}

		return $value;
	}//end asArray()

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
		$property = self::asArray(value: $property);
		if (is_array($property) === false) {
			return null;
		}

		return self::nonBlank(value: ($property['title'] ?? null));
	}//end titleOf()
}//end class
