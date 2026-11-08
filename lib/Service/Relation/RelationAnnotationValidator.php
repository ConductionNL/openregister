<?php

/**
 * Validates what a schema says about the relations its properties carry.
 *
 * A `$ref` property already says that a link exists and, through `inversedBy`,
 * where the other end is written. What it cannot say is what the link is
 * CALLED from each side: a case that `blocks` another is, from there,
 * `blockedBy`, and a reverse panel with no vocabulary can only say "referenced
 * by". `x-openregister-relation` names both ends beside the property, and
 * `x-openregister-relation-types` lets a schema name them once and point
 * several properties at the same entry.
 *
 * Two refusals are the ones the spec names, and both exist because the failure
 * they prevent is silent. A symmetric relation carrying an `inverseLabel`
 * reads one way on one side and the other way on the other, which is the
 * opposite of what symmetric means. A `type` naming a vocabulary entry that
 * does not exist does not error anywhere: it falls back to "referenced by"
 * forever, while whoever wrote it believes the link is typed.
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

use OCA\OpenRegister\Service\Schemas\SchemaAnnotationValidatorInterface;

/**
 * Reads and refuses relation declarations on a schema.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Reason: a validator's complexity IS its refusal count. Every branch here is
 * one way a declaration can be wrong, and each is named in its own message so
 * the 422 tells the author which property and which mistake. Splitting the
 * branches across helper classes to move the number under a threshold would
 * scatter one contract over several files without removing a single decision.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class RelationAnnotationValidator implements SchemaAnnotationValidatorInterface {
	/**
	 * The per-property annotation naming both ends of a link.
	 *
	 * @var string
	 */
	public const PROPERTY_ANNOTATION = 'x-openregister-relation';

	/**
	 * The schema-level annotation holding the named relation vocabulary.
	 *
	 * @var string
	 */
	public const VOCABULARY_ANNOTATION = 'x-openregister-relation-types';

	/**
	 * What a relation type may declare a child inherits from its parent.
	 *
	 * Deliberately closed, and deliberately these three. They are the three the
	 * corpus names, and all three are access-bearing: a child that silently
	 * takes a different confidentiality than its parent is a disclosure, not a
	 * cosmetic difference. An open list would let one app inherit a field
	 * another app reads as something else.
	 *
	 * @var array<int, string>
	 */
	public const INHERITABLE = ['classification', 'confidentiality', 'responsible'];

	/**
	 * The reasons a schema's relation declarations cannot be saved.
	 *
	 * @param array<string, mixed> $schema The schema shape: `properties` and,
	 *                                     beside it, the vocabulary annotation.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals,
	 *         empty when the declarations are valid.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function validate(array $schema): array {
		$errors = [];
		$vocabulary = $this->vocabulary(schema: $schema, errors: $errors);

		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			return $errors;
		}

		$keys = array_keys($vocabulary);
		foreach ($properties as $name => $property) {
			$errors = array_merge($errors, $this->validateProperty(name: (string)$name, property: $property, vocabularyKeys: $keys));
		}

		return $errors;
	}//end validate()

	/**
	 * Read the declared vocabulary, refusing a shape that cannot be resolved.
	 *
	 * @param array<string, mixed> $schema The schema shape.
	 * @param array<int, array{code: string, message: string}> $errors Collected refusals, by reference.
	 *
	 * @return array<string, array<string, mixed>> The vocabulary entries, keyed by `key`.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function vocabulary(array $schema, array &$errors): array {
		$raw = ($schema[self::VOCABULARY_ANNOTATION] ?? null);
		if ($raw === null) {
			return [];
		}

		if (is_array($raw) === false) {
			$errors[] = self::refusal(
				code: 'relation-vocabulary-malformed',
				message: sprintf('"%s" must be a list of relation types.', self::VOCABULARY_ANNOTATION)
			);
			return [];
		}

		return $this->vocabularyEntries(raw: $raw, errors: $errors);
	}//end vocabulary()

	/**
	 * The vocabulary entries that carry a usable, unique key.
	 *
	 * @param array $raw The declared vocabulary list.
	 * @param array<int, array{code: string, message: string}> $errors Collected refusals, by reference.
	 *
	 * @return array<string, array<string, mixed>> The vocabulary entries, keyed by `key`.
	 */
	private function vocabularyEntries(array $raw, array &$errors): array {
		$entries = [];
		foreach ($raw as $index => $entry) {
			$entry   = self::objectAsArray(value: $entry);
			$refusal = self::entryRefusal(index: (string)$index, entry: $entry) ?? self::duplicateRefusal(entry: $entry, entries: $entries);
			if ($refusal !== null) {
				$errors[] = $refusal;
				continue;
			}

			$key    = trim($entry['key']);
			$errors = array_merge($errors, $this->validateDeclaration(declaration: $entry, subject: sprintf('The relation type "%s"', $key)));
			$entries[$key] = $entry;
		}

		return $entries;
	}//end vocabularyEntries()

	/**
	 * The refusal of a vocabulary entry that is no object or declares no key.
	 *
	 * @param string $index The entry's position in the list.
	 * @param mixed  $entry The entry.
	 *
	 * @return array{code: string, message: string}|null The refusal, or null when the entry has a key.
	 */
	private static function entryRefusal(string $index, mixed $entry): ?array {
		if (is_array($entry) === false) {
			$message = sprintf('Entry %s of "%s" must be an object.', $index, self::VOCABULARY_ANNOTATION);
			return self::refusal(code: 'relation-vocabulary-malformed', message: $message);
		}

		$key = ($entry['key'] ?? null);
		if (is_string($key) === false || trim($key) === '') {
			$message = sprintf('Entry %s of "%s" declares no "key", so no property can point at it.', $index, self::VOCABULARY_ANNOTATION);
			return self::refusal(code: 'relation-vocabulary-key-missing', message: $message);
		}

		return null;
	}//end entryRefusal()

	/**
	 * The refusal of a vocabulary entry whose key an earlier entry already declared.
	 *
	 * @param array $entry   The entry, which has a key.
	 * @param array $entries The entries accepted so far, keyed by `key`.
	 *
	 * @return array{code: string, message: string}|null The refusal, or null when the key is new.
	 */
	private static function duplicateRefusal(array $entry, array $entries): ?array {
		$key = trim($entry['key']);
		if (isset($entries[$key]) === false) {
			return null;
		}

		return self::refusal(
			code: 'relation-vocabulary-key-duplicate',
			message: sprintf(
				'"%s" declares the key "%s" twice, and a property pointing at it would resolve to whichever came last.',
				self::VOCABULARY_ANNOTATION,
				$key
			)
		);
	}//end duplicateRefusal()

	/**
	 * Refuse one property's relation declaration.
	 *
	 * @param string $name The property name.
	 * @param mixed $property The property definition.
	 * @param array<int, string> $vocabularyKeys The keys the schema declares.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function validateProperty(string $name, mixed $property, array $vocabularyKeys): array {
		$declaration = self::declarationOf(property: $property);
		if ($declaration === null) {
			return [];
		}

		$subject = sprintf('The property "%s"', $name);
		if (is_array($declaration) === false) {
			$message = sprintf('declares "%s", which must be an object.', self::PROPERTY_ANNOTATION);
			return self::about(subject: $subject, refusals: [self::refusal(code: 'relation-annotation-malformed', message: $message)]);
		}

		$refusals = self::declarationRefusals(property: $property, declaration: $declaration, vocabularyKeys: $vocabularyKeys);

		$own = self::about(subject: $subject, refusals: $refusals);
		return array_merge($own, $this->validateDeclaration(declaration: $declaration, subject: $subject));
	}//end validateProperty()

	/**
	 * The refusals of one property's declaration itself: no `$ref`, an unknown type, a missing label.
	 *
	 * @param mixed                $property       The property definition.
	 * @param array<string, mixed> $declaration    The declaration.
	 * @param array<int, string>   $vocabularyKeys The keys the schema declares.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals, messages without their subject.
	 */
	private static function declarationRefusals(mixed $property, array $declaration, array $vocabularyKeys): array {
		return array_merge(
			self::referenceRefusals(property: $property),
			self::typeRefusals(type: ($declaration['type'] ?? null), vocabularyKeys: $vocabularyKeys),
			self::labelMissingRefusals(declaration: $declaration)
		);
	}//end declarationRefusals()

	/**
	 * The refusal of a relation declared on a property that holds no `$ref`.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return array<int, array{code: string, message: string}> The refusal, messages without their subject.
	 */
	private static function referenceRefusals(mixed $property): array {
		if (self::isReferenceProperty(property: $property) === true) {
			return [];
		}

		$message = sprintf(
			'declares "%s" but holds no "$ref", so there is no link for the labels to name.',
			self::PROPERTY_ANNOTATION
		);
		return [self::refusal(code: 'relation-without-ref', message: $message)];
	}//end referenceRefusals()

	/**
	 * The refusal of a relation type that is no vocabulary key.
	 *
	 * Left unrefused, an unknown type is silent: the property renders as the
	 * "referenced by" fallback while its author believes the link is typed.
	 *
	 * @param mixed              $type           The declared type.
	 * @param array<int, string> $vocabularyKeys The keys the schema declares.
	 *
	 * @return array<int, array{code: string, message: string}> The refusal, messages without their subject.
	 */
	private static function typeRefusals(mixed $type, array $vocabularyKeys): array {
		if ($type === null) {
			return [];
		}

		if (is_string($type) === false || trim($type) === '') {
			return [self::refusal(code: 'relation-type-unknown', message: 'declares a relation "type" that is not a vocabulary key.')];
		}

		if (in_array(trim($type), $vocabularyKeys, true) === true) {
			return [];
		}

		$message = sprintf('points at the relation type "%s", which "%s" does not declare.', $type, self::VOCABULARY_ANNOTATION);
		return [self::refusal(code: 'relation-type-unknown', message: $message)];
	}//end typeRefusals()

	/**
	 * The refusal of a property declaration with neither a label nor a type.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusal, messages without their subject.
	 */
	private static function labelMissingRefusals(array $declaration): array {
		if (($declaration['type'] ?? null) !== null || isset($declaration['label']) === true) {
			return [];
		}

		$message = sprintf('declares "%s" with neither a "label" nor a "type".', self::PROPERTY_ANNOTATION);
		return [self::refusal(code: 'relation-label-missing', message: $message)];
	}//end labelMissingRefusals()

	/**
	 * Refuse the parts a property declaration and a vocabulary entry share.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 * @param string $subject How the message names whatever declared it.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function validateDeclaration(array $declaration, string $subject): array {
		$symmetric = ($declaration['symmetric'] ?? false);
		$refusals  = [];
		if (is_bool($symmetric) === false) {
			$refusals[] = self::refusal(code: 'relation-symmetric-malformed', message: 'declares "symmetric" as something other than true or false.');
		}

		$refusals = array_merge($refusals, $this->labelShapeRefusals(declaration: $declaration));
		if ($symmetric === true && ($declaration['inverseLabel'] ?? null) !== null) {
			// A symmetric relation reads the same from both ends by definition, so a second
			// label is a contradiction that would otherwise be stored and rendered on one side only.
			$message    = 'is symmetric and also declares an "inverseLabel". A symmetric relation reads the same from both sides.';
			$refusals[] = self::refusal(code: 'relation-symmetric-inverse-label', message: $message);
		}

		$own = self::about(subject: $subject, refusals: $refusals);
		return array_merge($own, $this->validateInherits(declaration: $declaration, subject: $subject));
	}//end validateDeclaration()

	/**
	 * The refusals of a label or inverse label that cannot be resolved to text.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals, messages without their subject.
	 */
	private function labelShapeRefusals(array $declaration): array {
		$refusals = [];
		foreach (['label' => 'a "label"', 'inverseLabel' => 'an "inverseLabel"'] as $field => $named) {
			$value = ($declaration[$field] ?? null);
			if ($value !== null && $this->isLabel(value: $value) === false) {
				$message    = sprintf('declares %s that is neither a string nor a map of language tag to string.', $named);
				$refusals[] = self::refusal(code: 'relation-label-malformed', message: $message);
			}
		}

		return $refusals;
	}//end labelShapeRefusals()

	/**
	 * Refuse an inheritance declaration naming something outside the closed set.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 * @param string $subject How the message names whatever declared it.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function validateInherits(array $declaration, string $subject): array {
		$inherits = self::objectAsArray(value: ($declaration['inherits'] ?? null));
		if ($inherits === null) {
			return [];
		}

		if (is_array($inherits) === false) {
			$message = 'declares "inherits" as neither a list of names nor a map of name to property.';
			return self::about(subject: $subject, refusals: [self::refusal(code: 'relation-inherits-malformed', message: $message)]);
		}

		$refusals = [];
		foreach ($inherits as $key => $value) {
			$refusals = array_merge($refusals, self::inheritRefusals(key: $key, value: $value));
		}

		return self::about(subject: $subject, refusals: $refusals);
	}//end validateInherits()

	/**
	 * The refusal of one inheritance entry: a name outside the closed set, or a mapping onto no property.
	 *
	 * @param int|string $key   The list position, or the inherited name in the map form.
	 * @param mixed      $value The inherited name in the list form, or the property in the map form.
	 *
	 * @return array<int, array{code: string, message: string}> The refusal, messages without their subject.
	 */
	private static function inheritRefusals(int|string $key, mixed $value): array {
		$role = $key;
		if (is_int($key) === true) {
			$role = $value;
		}

		if (is_string($role) === false || in_array($role, self::INHERITABLE, true) === false) {
			return [self::unknownInheritance(role: $role)];
		}

		if (is_int($key) === true) {
			return [];
		}

		return self::mappingRefusals(role: $role, value: $value);
	}//end inheritRefusals()


	/**
	 * The refusal of a map-form inheritance entry that maps onto no property name.
	 *
	 * @param string $role  The inherited name, already known to be in the closed set.
	 * @param mixed  $value The property it is mapped onto.
	 *
	 * @return array<int, array{code: string, message: string}> The refusal, messages without their subject.
	 */
	private static function mappingRefusals(string $role, mixed $value): array {
		if (is_string($value) === true && trim($value) !== '') {
			return [];
		}

		$message = sprintf('maps the inherited "%s" onto something that is not a property name.', $role);
		return [self::refusal(code: 'relation-inherits-malformed', message: $message)];
	}//end mappingRefusals()

	/**
	 * The refusal of an inherited name outside the closed set.
	 *
	 * @param mixed $role The inherited name, or whatever stands in its place.
	 *
	 * @return array{code: string, message: string} The refusal, its message without the subject.
	 */
	private static function unknownInheritance(mixed $role): array {
		$named = gettype($role);
		if (is_string($role) === true) {
			$named = $role;
		}

		$message = sprintf('declares inheritance of "%s", which is not one of: %s.', $named, implode(', ', self::INHERITABLE));
		return self::refusal(code: 'relation-inherits-unknown', message: $message);
	}//end unknownInheritance()

	/**
	 * One refusal.
	 *
	 * @param string $code    The refusal code.
	 * @param string $message The message.
	 *
	 * @return array{code: string, message: string}
	 */
	private static function refusal(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end refusal()

	/**
	 * Refusals with their subject put in front of each message.
	 *
	 * @param string                                           $subject  How the message names whatever declared it.
	 * @param array<int, array{code: string, message: string}> $refusals The refusals, messages without their subject.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private static function about(string $subject, array $refusals): array {
		return array_map(
			static fn (array $refusal): array => self::refusal(code: $refusal['code'], message: $subject . ' ' . $refusal['message']),
			$refusals
		);
	}//end about()

	/**
	 * A decoded JSON object as an array; any other value as it is.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function objectAsArray(mixed $value): mixed {
		if (is_object($value) === true) {
			return (array)$value;
		}

		return $value;
	}//end objectAsArray()

	/**
	 * Whether a value is usable as a label: a string, or a per-language map.
	 *
	 * @param mixed $value The declared label.
	 *
	 * @return boolean True when it can be resolved to text.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function isLabel(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		$value = self::objectAsArray(value: $value);

		if (is_array($value) === false || $value === []) {
			return false;
		}

		return self::isLanguageMap(value: $value);
	}//end isLabel()


	/**
	 * Whether every entry of a non-empty map is a language tag naming a text.
	 *
	 * @param array<mixed> $value The map.
	 *
	 * @return boolean True when every key and every value is a string.
	 */
	private static function isLanguageMap(array $value): bool {
		foreach ($value as $tag => $text) {
			if (is_string($tag) === false || is_string($text) === false) {
				return false;
			}
		}

		return true;
	}//end isLanguageMap()

	/**
	 * Read the relation annotation off a property, at either position.
	 *
	 * An array-valued reference puts `$ref` and `inversedBy` on `items`, so a
	 * reader that only looks at the property root leaves every array relation
	 * unvalidated and unlabelled, which is the common shape rather than the
	 * exotic one. The property root wins when both carry one.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return mixed The declaration, or null when there is none.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public static function declarationOf(mixed $property): mixed {
		$property = self::objectAsArray(value: $property);
		if (is_array($property) === false) {
			return null;
		}

		$declaration = ($property[self::PROPERTY_ANNOTATION] ?? null);
		if ($declaration === null) {
			$items = self::objectAsArray(value: ($property['items'] ?? null));
			if (is_array($items) === true) {
				$declaration = ($items[self::PROPERTY_ANNOTATION] ?? null);
			}
		}

		return self::objectAsArray(value: $declaration);
	}//end declarationOf()

	/**
	 * Whether a property holds a reference at all, at either position.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return boolean True when the property carries `$ref` or `inversedBy`.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public static function isReferenceProperty(mixed $property): bool {
		$property = self::objectAsArray(value: $property);
		if (is_array($property) === false) {
			return false;
		}

		if (self::holdsReference(node: $property) === true) {
			return true;
		}

		$items = self::objectAsArray(value: ($property['items'] ?? null));
		return is_array($items) === true && self::holdsReference(node: $items) === true;
	}//end isReferenceProperty()


	/**
	 * Whether one level of a property definition carries `$ref` or `inversedBy`.
	 *
	 * @param array<mixed> $node The property root or its `items`.
	 *
	 * @return boolean True when it does.
	 */
	private static function holdsReference(array $node): bool {
		return isset($node['$ref']) === true || isset($node['inversedBy']) === true;
	}//end holdsReference()
}//end class
