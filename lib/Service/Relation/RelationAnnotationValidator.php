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

/**
 * Reads and refuses relation declarations on a schema.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class RelationAnnotationValidator {
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

		foreach ($properties as $name => $property) {
			$errors = array_merge(
				$errors,
				$this->validateProperty(
					name: (string)$name,
					property: $property,
					vocabularyKeys: array_keys($vocabulary)
				)
			);
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
			$errors[] = [
				'code' => 'relation-vocabulary-malformed',
				'message' => sprintf(
					'"%s" must be a list of relation types.',
					self::VOCABULARY_ANNOTATION
				),
			];
			return [];
		}

		$entries = [];
		foreach ($raw as $index => $entry) {
			if (is_object($entry) === true) {
				$entry = (array)$entry;
			}

			if (is_array($entry) === false) {
				$errors[] = [
					'code' => 'relation-vocabulary-malformed',
					'message' => sprintf(
						'Entry %s of "%s" must be an object.',
						(string)$index,
						self::VOCABULARY_ANNOTATION
					),
				];
				continue;
			}

			$key = ($entry['key'] ?? null);
			if (is_string($key) === false || trim($key) === '') {
				$errors[] = [
					'code' => 'relation-vocabulary-key-missing',
					'message' => sprintf(
						'Entry %s of "%s" declares no "key", so no property can point at it.',
						(string)$index,
						self::VOCABULARY_ANNOTATION
					),
				];
				continue;
			}

			$key = trim($key);
			if (isset($entries[$key]) === true) {
				$errors[] = [
					'code' => 'relation-vocabulary-key-duplicate',
					'message' => sprintf(
						'"%s" declares the key "%s" twice, and a property pointing at it would resolve to whichever came last.',
						self::VOCABULARY_ANNOTATION,
						$key
					),
				];
				continue;
			}

			$errors = array_merge(
				$errors,
				$this->validateDeclaration(
					declaration: $entry,
					subject: sprintf('The relation type "%s"', $key)
				)
			);

			$entries[$key] = $entry;
		}//end foreach

		return $entries;
	}//end vocabulary()

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
			return [
				[
					'code' => 'relation-annotation-malformed',
					'message' => sprintf(
						'%s declares "%s", which must be an object.',
						$subject,
						self::PROPERTY_ANNOTATION
					),
				],
			];
		}

		$errors = [];

		if (self::isReferenceProperty(property: $property) === false) {
			$errors[] = [
				'code' => 'relation-without-ref',
				'message' => sprintf(
					'%s declares "%s" but holds no "$ref", so there is no link for the labels to name.',
					$subject,
					self::PROPERTY_ANNOTATION
				),
			];
		}

		$type = ($declaration['type'] ?? null);
		if ($type !== null) {
			if (is_string($type) === false || trim($type) === '') {
				$errors[] = [
					'code' => 'relation-type-unknown',
					'message' => sprintf('%s declares a relation "type" that is not a vocabulary key.', $subject),
				];
			} elseif (in_array(trim($type), $vocabularyKeys, true) === false) {
				// The refusal the spec names. Left unrefused this is silent:
				// the property renders as the "referenced by" fallback while
				// its author believes the link is typed.
				$errors[] = [
					'code' => 'relation-type-unknown',
					'message' => sprintf(
						'%s points at the relation type "%s", which "%s" does not declare.',
						$subject,
						(string)$type,
						self::VOCABULARY_ANNOTATION
					),
				];
			}//end if
		}//end if

		if ($type === null && isset($declaration['label']) === false) {
			$errors[] = [
				'code' => 'relation-label-missing',
				'message' => sprintf(
					'%s declares "%s" with neither a "label" nor a "type".',
					$subject,
					self::PROPERTY_ANNOTATION
				),
			];
		}

		return array_merge($errors, $this->validateDeclaration(declaration: $declaration, subject: $subject));
	}//end validateProperty()

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
		$errors = [];
		$symmetric = ($declaration['symmetric'] ?? false);

		if (is_bool($symmetric) === false) {
			$errors[] = [
				'code' => 'relation-symmetric-malformed',
				'message' => sprintf('%s declares "symmetric" as something other than true or false.', $subject),
			];
			$symmetric = false;
		}

		$label = ($declaration['label'] ?? null);
		if ($label !== null && $this->isLabel(value: $label) === false) {
			$errors[] = [
				'code' => 'relation-label-malformed',
				'message' => sprintf(
					'%s declares a "label" that is neither a string nor a map of language tag to string.',
					$subject
				),
			];
		}

		$inverse = ($declaration['inverseLabel'] ?? null);
		if ($inverse !== null && $this->isLabel(value: $inverse) === false) {
			$errors[] = [
				'code' => 'relation-label-malformed',
				'message' => sprintf(
					'%s declares an "inverseLabel" that is neither a string nor a map of language tag to string.',
					$subject
				),
			];
		}

		if ($symmetric === true && $inverse !== null) {
			// The refusal the spec names. A symmetric relation reads the same
			// from both ends by definition, so a second label is a contradiction
			// that would otherwise be stored and rendered on one side only.
			$errors[] = [
				'code' => 'relation-symmetric-inverse-label',
				'message' => sprintf(
					'%s is symmetric and also declares an "inverseLabel". A symmetric relation reads the same from both sides.',
					$subject
				),
			];
		}

		return array_merge($errors, $this->validateInherits(declaration: $declaration, subject: $subject));
	}//end validateDeclaration()

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
		$inherits = ($declaration['inherits'] ?? null);
		if ($inherits === null) {
			return [];
		}

		if (is_object($inherits) === true) {
			$inherits = (array)$inherits;
		}

		if (is_array($inherits) === false) {
			return [
				[
					'code' => 'relation-inherits-malformed',
					'message' => sprintf(
						'%s declares "inherits" as neither a list of names nor a map of name to property.',
						$subject
					),
				],
			];
		}

		$errors = [];
		foreach ($inherits as $key => $value) {
			$role = $key;
			if (is_int($key) === true) {
				$role = $value;
			}

			if (is_string($role) === false || in_array($role, self::INHERITABLE, true) === false) {
				$errors[] = [
					'code' => 'relation-inherits-unknown',
					'message' => sprintf(
						'%s declares inheritance of "%s", which is not one of: %s.',
						$subject,
						is_string($role) === true ? $role : gettype($role),
						implode(', ', self::INHERITABLE)
					),
				];
				continue;
			}

			if (is_int($key) === false && (is_string($value) === false || trim($value) === '')) {
				$errors[] = [
					'code' => 'relation-inherits-malformed',
					'message' => sprintf(
						'%s maps the inherited "%s" onto something that is not a property name.',
						$subject,
						$role
					),
				];
			}
		}//end foreach

		return $errors;
	}//end validateInherits()

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

		if (is_object($value) === true) {
			$value = (array)$value;
		}

		if (is_array($value) === false || $value === []) {
			return false;
		}

		foreach ($value as $tag => $text) {
			if (is_string($tag) === false || is_string($text) === false) {
				return false;
			}
		}

		return true;
	}//end isLabel()

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
		if (is_object($property) === true) {
			$property = (array)$property;
		}

		if (is_array($property) === false) {
			return null;
		}

		$declaration = ($property[self::PROPERTY_ANNOTATION] ?? null);
		if ($declaration === null) {
			$items = ($property['items'] ?? null);
			if (is_object($items) === true) {
				$items = (array)$items;
			}

			if (is_array($items) === true) {
				$declaration = ($items[self::PROPERTY_ANNOTATION] ?? null);
			}
		}

		if (is_object($declaration) === true) {
			return (array)$declaration;
		}

		return $declaration;
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
		if (is_object($property) === true) {
			$property = (array)$property;
		}

		if (is_array($property) === false) {
			return false;
		}

		if (isset($property['$ref']) === true || isset($property['inversedBy']) === true) {
			return true;
		}

		$items = ($property['items'] ?? null);
		if (is_object($items) === true) {
			$items = (array)$items;
		}

		if (is_array($items) === false) {
			return false;
		}

		return isset($items['$ref']) === true || isset($items['inversedBy']) === true;
	}//end isReferenceProperty()
}//end class
