<?php

/**
 * OpenRegister ExtendingFormDeclaration
 *
 * Defines `x-openregister-extends-form`: the declaration an app makes when its
 * own form lets an administrator author schema properties. It says which
 * vocabulary keys that form forwards, so a narrower editor is a stated
 * narrowing rather than an oversight nobody can see.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * The extending-form declaration, validated against the published vocabulary.
 *
 * The annotation already existed in the wild before it existed here. dossiq
 * carries `x-openregister-extends-form.map` on a case-type property and
 * forwards eight keys through it, in OpenRegister's own `x-` namespace, and a
 * code search of this repository on 2026-09-14 returned nothing that defined
 * it. This class is the definition: the shape, the validation and the reading
 * that lets anybody count what an app's form leaves out.
 *
 * The annotation sits either on the schema configuration, or on the property
 * that points at the thing being configured. Both are read.
 *
 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
 */
final class ExtendingFormDeclaration {

	/**
	 * The annotation key, in OpenRegister's own namespace.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-extends-form';

	/**
	 * Wire the vocabulary the declaration is checked against.
	 *
	 * @param PropertyVocabulary $vocabulary The published vocabulary.
	 *
	 * @return void
	 */
	public function __construct(private readonly PropertyVocabulary $vocabulary = new PropertyVocabulary()) {
	}//end __construct()

	/**
	 * Find every declaration on a schema, keyed by where it sits.
	 *
	 * @param array $configuration The schema configuration.
	 * @param array $properties The schema properties.
	 *
	 * @return array<string, array<string, mixed>> Path to declaration.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function fromSchema(array $configuration, array $properties): array {
		$found = [];

		$onSchema = ($configuration[self::ANNOTATION] ?? null);
		if ($onSchema !== null) {
			$found['configuration/' . self::ANNOTATION] = $this->asArray(value: $onSchema);
		}

		foreach ($properties as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			$onProperty = ($property[self::ANNOTATION] ?? null);
			if ($onProperty === null) {
				continue;
			}

			$found['properties/' . (string)$name . '/' . self::ANNOTATION] = $this->asArray(value: $onProperty);
		}

		return $found;
	}//end fromSchema()

	/**
	 * Check one declaration against the vocabulary.
	 *
	 * @param mixed $annotation The declaration as stored.
	 * @param string $path Where the declaration sits, for the error message.
	 *
	 * @return array<int, array{code: string, key: string, path: string, message: string}> The refusals, empty when valid.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per rule, each naming its own refusal.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      One branch per rule, each naming its own refusal.
	 */
	public function validate(mixed $annotation, string $path = self::ANNOTATION): array {
		if (is_array($annotation) === false) {
			return [
				[
					'code' => 'extends-form-not-an-object',
					'key' => self::ANNOTATION,
					'path' => $path,
					'message' => "'" . self::ANNOTATION . "' at '$path' must be an object.",
				],
			];
		}

		$errors = array_merge(
			$this->validateAppAndForm(annotation: $annotation, path: $path),
			$this->validateShape(annotation: $annotation, path: $path)
		);
		if ($errors !== []) {
			return $errors;
		}

		foreach ($this->pairs(annotation: $annotation) as $field => $key) {
			if (is_string($key) === false || $key === '') {
				$errors[] = [
					'code' => 'extends-form-key-not-a-string',
					'key' => (string)$field,
					'path' => $path,
					'message' => "The key forwarded by '{$field}' at '$path' must be a non-empty string.",
				];
				continue;
			}

			if ($this->vocabulary->hasKey(key: $key) === true) {
				continue;
			}

			$errors[] = [
				'code' => 'extends-form-unknown-key',
				'key' => $key,
				'path' => $path,
				'message' => "'{$key}' at '$path' is not in the property vocabulary, so no form can forward it.",
			];
		}

		return array_merge($errors, $this->validateNoDuplicateTargets(annotation: $annotation, path: $path));
	}//end validate()

	/**
	 * The vocabulary keys a declaration forwards.
	 *
	 * @param mixed $annotation The declaration as stored.
	 *
	 * @return array<int, string> The forwarded keys, de-duplicated, in declaration order.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function forwards(mixed $annotation): array {
		if (is_array($annotation) === false) {
			return [];
		}

		$keys = [];
		foreach ($this->pairs(annotation: $annotation) as $key) {
			if (is_string($key) === true && $key !== '' && in_array($key, $keys, true) === false) {
				$keys[] = $key;
			}
		}

		return $keys;
	}//end forwards()

	/**
	 * The vocabulary keys a declaration does NOT forward.
	 *
	 * This is the narrowing, and it is the number the study was counting when
	 * it found an editor eight types wide on a layer that validates twenty.
	 *
	 * @param mixed $annotation The declaration as stored.
	 *
	 * @return array<int, string> The keys the app's form leaves out.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function narrowing(mixed $annotation): array {
		$forwarded = $this->forwards(annotation: $annotation);

		return array_values(array_diff($this->vocabulary->keys(), $forwarded));
	}//end narrowing()

	/**
	 * A declaration in the shape the read answers with.
	 *
	 * @param mixed $annotation The declaration as stored.
	 * @param string $path Where the declaration sits.
	 *
	 * @return array<string, mixed> The app, the form, what it forwards, what it leaves out and the counts.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function describe(mixed $annotation, string $path = self::ANNOTATION): array {
		$forwards = $this->forwards(annotation: $annotation);
		$narrows = $this->narrowing(annotation: $annotation);
		$declaration = $this->asArray(value: $annotation);

		return [
			'path' => $path,
			'app' => (string)($declaration['app'] ?? ''),
			'form' => (string)($declaration['form'] ?? ''),
			'map' => $this->pairs(annotation: $declaration),
			'forwards' => $forwards,
			'narrows' => $narrows,
			'forwardsType' => in_array('type', $forwards, true),
			'counts' => [
				'forwards' => count($forwards),
				'narrows' => count($narrows),
				'vocabulary' => count($this->vocabulary->keys()),
			],
			'errors' => $this->validate(annotation: $annotation, path: $path),
		];
	}//end describe()

	/**
	 * Turn one form submission into a property definition.
	 *
	 * The result goes through {@see PropertyValidatorHandler::validateProperty}
	 * exactly like a hand-written property, which is the whole point: a
	 * property authored through an app's form is not a second class of
	 * property with a second set of rules.
	 *
	 * @param mixed $annotation The declaration as stored.
	 * @param array $formValues The values the app's form collected, keyed by its own field names.
	 *
	 * @return array<string, mixed> The property definition.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function toProperty(mixed $annotation, array $formValues): array {
		$property = [];
		foreach ($this->pairs(annotation: $annotation) as $field => $key) {
			if (is_string($key) === false || array_key_exists($field, $formValues) === false) {
				continue;
			}

			$property[$key] = $formValues[$field];
		}

		return $property;
	}//end toProperty()

	/**
	 * The field-to-key pairs, whichever spelling the declaration used.
	 *
	 * `map` names the app's own form field on the left. `forwards` is the
	 * short spelling for a form whose fields are already named after the
	 * vocabulary.
	 *
	 * @param array $annotation The declaration.
	 *
	 * @return array<string, mixed> Form field name to vocabulary key.
	 */
	private function pairs(array $annotation): array {
		$map = ($annotation['map'] ?? null);
		if (is_array($map) === true) {
			$pairs = [];
			foreach ($map as $field => $key) {
				$pairs[(string)$field] = $key;
			}

			return $pairs;
		}

		$forwards = ($annotation['forwards'] ?? null);
		if (is_array($forwards) === true) {
			$pairs = [];
			foreach ($forwards as $key) {
				if (is_string($key) === true) {
					$pairs[$key] = $key;
				}
			}

			return $pairs;
		}

		return [];
	}//end pairs()

	/**
	 * Check `app` and `form` are strings when they are given.
	 *
	 * @param array $annotation The declaration.
	 * @param string $path Where the declaration sits.
	 *
	 * @return array<int, array{code: string, key: string, path: string, message: string}> The refusals.
	 */
	private function validateAppAndForm(array $annotation, string $path): array {
		$errors = [];
		foreach (['app', 'form'] as $field) {
			$value = ($annotation[$field] ?? null);
			if ($value === null || is_string($value) === true) {
				continue;
			}

			$errors[] = [
				'code' => 'extends-form-field-not-a-string',
				'key' => $field,
				'path' => $path,
				'message' => "'{$field}' at '$path' must be a string.",
			];
		}

		return $errors;
	}//end validateAppAndForm()

	/**
	 * Check the declaration says what it forwards, once.
	 *
	 * @param array $annotation The declaration.
	 * @param string $path Where the declaration sits.
	 *
	 * @return array<int, array{code: string, key: string, path: string, message: string}> The refusals.
	 */
	private function validateShape(array $annotation, string $path): array {
		$hasMap = is_array($annotation['map'] ?? null);
		$hasForwards = is_array($annotation['forwards'] ?? null);

		if ($hasMap === true && $hasForwards === true) {
			return [
				[
					'code' => 'extends-form-two-spellings',
					'key' => 'map',
					'path' => $path,
					'message' => "'map' and 'forwards' at '$path' both say what the form forwards. Keep one.",
				],
			];
		}

		if ($hasMap === false && $hasForwards === false) {
			return [
				[
					'code' => 'extends-form-forwards-nothing',
					'key' => 'map',
					'path' => $path,
					'message' => "'" . self::ANNOTATION . "' at '$path' must carry a 'map' object or a 'forwards' list.",
				],
			];
		}

		return [];
	}//end validateShape()

	/**
	 * Refuse two form fields that write the same property key.
	 *
	 * @param array $annotation The declaration.
	 * @param string $path Where the declaration sits.
	 *
	 * @return array<int, array{code: string, key: string, path: string, message: string}> The refusals.
	 */
	private function validateNoDuplicateTargets(array $annotation, string $path): array {
		$seen = [];
		$errors = [];
		foreach ($this->pairs(annotation: $annotation) as $field => $key) {
			if (is_string($key) === false) {
				continue;
			}

			if (in_array($key, $seen, true) === true) {
				$errors[] = [
					'code' => 'extends-form-duplicate-key',
					'key' => $key,
					'path' => $path,
					'message' => "Two form fields at '$path' forward '{$key}', and no rule says which wins. Field '{$field}' is the second.",
				];
				continue;
			}

			$seen[] = $key;
		}

		return $errors;
	}//end validateNoDuplicateTargets()

	/**
	 * Read a stored annotation as an array.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<string, mixed> The declaration, empty when it was not an object.
	 */
	private function asArray(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$declaration = [];
		foreach ($value as $key => $entry) {
			$declaration[(string)$key] = $entry;
		}

		return $declaration;
	}//end asArray()
}//end class
