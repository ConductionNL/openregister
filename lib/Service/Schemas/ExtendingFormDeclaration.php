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
 * carries `x-openregister-extends-form` on a case-type property, in
 * OpenRegister's own `x-` namespace, and a code search of this repository on
 * 2026-09-14 returned nothing that defined it. This class is the definition:
 * the shape, the validation and the reading that lets anybody count what an
 * app's form leaves out.
 *
 * **The map reads role to field, and that direction is not a choice.** The
 * shipped consumer is `propertiesFromDefinitions` in
 * `@conduction/nextcloud-vue`, whose `mapped($record, $map, $role)` does
 * `$map[$role]` and then `$record[$field]`, with a `DEFAULT_MAP` of
 * `title: 'name'`, `type: 'propertyType'`, `enum: 'enumValues'`. So the key is
 * the vocabulary role this platform owns, and the value is the app's own field
 * name, which this platform does not own. Defining it the other way round
 * would have refused five of dossiq's six keys by name, which is how a
 * platform breaks a consumer while believing it is documenting one.
 *
 * `definitions` names where the records come from; the consumer ignores a
 * declaration without it, so a declaration without it forwards nothing and is
 * refused here rather than stored as a no-op.
 *
 * The annotation sits either on the schema configuration, or on the property
 * that points at the thing being configured. Both are read.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class ExtendingFormDeclaration {

	/**
	 * The annotation key, in OpenRegister's own namespace.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-extends-form';

	/**
	 * Roles that are not vocabulary keys, and the key each one supplies.
	 *
	 * Exactly one today. `propertiesFromDefinitions` reads
	 * `mapped($record, $map, 'definition')` as the fallback source for a
	 * description, so `definition` is a second source for `description`
	 * rather than a property key of its own. It is declared here because a
	 * shipped role the platform refuses by name is the same breakage as a
	 * mis-read map, one release later.
	 *
	 * @var array<string, string>
	 */
	public const SOURCE_ALIASES = ['definition' => 'description'];

	/**
	 * Roles the consumer reads to steer the form, not to fill a property key.
	 *
	 * `@conduction/nextcloud-vue` 2.79.0 added `hideOnCreate` to the
	 * `DEFAULT_MAP` of `propertiesFromDefinitions`: a definition whose mapped
	 * field is `true` gets no field on a create form, because the app fills
	 * that answer in itself. It forwards no vocabulary key, so it is neither
	 * counted as forwarded nor written onto a property built from a form.
	 * Refusing it by name refused dossiq's whole case schema at import.
	 *
	 * @var array<string, string>
	 */
	public const FORM_ROLES = [
		'hideOnCreate' => 'The create form leaves the field out, because the app fills it in itself. Edit forms still show it.',
	];

	/**
	 * The refusals a declaration can earn, by template name.
	 *
	 * `{path}` is where the declaration sits; `{key}` is the key the refusal
	 * names, which is the template's own `key` unless the caller names one.
	 *
	 * @var array<string, array{code: string, key: string, message: string}>
	 */
	private const REFUSALS = [
		'not-an-object' => [
			'code' => 'extends-form-not-an-object',
			'key' => self::ANNOTATION,
			'message' => "'" . self::ANNOTATION . "' at '{path}' must be an object.",
		],
		'two-spellings' => [
			'code' => 'extends-form-two-spellings',
			'key' => 'map',
			'message' => "'map' and 'forwards' at '{path}' both say what the form forwards. Keep one.",
		],
		'forwards-nothing' => [
			'code' => 'extends-form-forwards-nothing',
			'key' => 'map',
			'message' => "'" . self::ANNOTATION . "' at '{path}' must carry a 'map' object or a 'forwards' list.",
		],
		'no-definitions' => [
			'code' => 'extends-form-no-definitions',
			'key' => 'definitions',
			'message' => "'" . self::ANNOTATION . "' at '{path}' must name its 'definitions', or the form renders no fields.",
		],
		'not-a-string' => [
			'code' => 'extends-form-field-not-a-string',
			'key' => '',
			'message' => "'{key}' at '{path}' must be a string.",
		],
		'field-not-a-string' => [
			'code' => 'extends-form-field-not-a-string',
			'key' => '',
			'message' => "The field '{key}' reads at '{path}' must be a non-empty string.",
		],
		'unknown-key' => [
			'code' => 'extends-form-unknown-key',
			'key' => '',
			'message' => "'{key}' at '{path}' is not in the property vocabulary, so no form can forward it.",
		],
	];

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
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function fromSchema(array $configuration, array $properties): array {
		$found = [];
		$onSchema = ($configuration[self::ANNOTATION] ?? null);
		if ($onSchema !== null) {
			$found['configuration/' . self::ANNOTATION] = $this->asArray(value: $onSchema);
		}

		return $found + $this->fromProperties(properties: $properties);
	}//end fromSchema()

	/**
	 * The declarations the schema's properties carry, keyed by where they sit.
	 *
	 * @param array $properties The schema properties.
	 *
	 * @return array<string, array<string, mixed>> Path to declaration.
	 */
	private function fromProperties(array $properties): array {
		$found = [];
		foreach ($properties as $name => $property) {
			if (is_array($property) === false || ($property[self::ANNOTATION] ?? null) === null) {
				continue;
			}

			$found['properties/' . (string)$name . '/' . self::ANNOTATION] = $this->asArray(value: $property[self::ANNOTATION]);
		}

		return $found;
	}//end fromProperties()

	/**
	 * Check one declaration against the vocabulary.
	 *
	 * The app, form and shape are checked first; the role-to-field pairs only
	 * when those hold.
	 *
	 * @param mixed $annotation The declaration as stored.
	 * @param string $path Where the declaration sits, for the error message.
	 *
	 * @return array<int, array{code: string, key: string, path: string, message: string}> The refusals, empty when valid.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function validate(mixed $annotation, string $path = self::ANNOTATION): array {
		if (is_array($annotation) === false) {
			return [$this->refusal(template: 'not-an-object', where: ['path' => $path])];
		}

		$errors = array_merge(
			$this->validateAppAndForm(annotation: $annotation, path: $path),
			$this->validateShape(annotation: $annotation, path: $path)
		);
		if ($errors !== []) {
			return $errors;
		}

		return $this->validatePairs(annotation: $annotation, path: $path);
	}//end validate()

	/**
	 * Check every role-to-field pair: the field is a non-empty string, the role one the consumer reads.
	 *
	 * @param array  $annotation The declaration.
	 * @param string $path       Where the declaration sits.
	 *
	 * @return array<int, array{code: string, key: string, path: string, message: string}> The refusals.
	 */
	private function validatePairs(array $annotation, string $path): array {
		$errors = [];
		foreach ($this->pairs(annotation: $annotation) as $role => $field) {
			$where = ['path' => $path, 'key' => (string)$role];
			if (is_string($field) === false || $field === '') {
				$errors[] = $this->refusal(template: 'field-not-a-string', where: $where);
			}

			if ($this->isReadableRole(role: (string)$role) === false) {
				$errors[] = $this->refusal(template: 'unknown-key', where: $where);
			}
		}

		return $errors;
	}//end validatePairs()

	/**
	 * Whether the consumer reads a role: a vocabulary key, a source alias or a form role.
	 *
	 * A source alias or a form role is read by the consumer without being a
	 * property key of its own.
	 *
	 * @param string $role The vocabulary role.
	 *
	 * @return bool True when the role is read.
	 */
	private function isReadableRole(string $role): bool {
		return $this->vocabulary->hasKey(key: $role) === true
			|| array_key_exists($role, (self::SOURCE_ALIASES + self::FORM_ROLES)) === true;
	}//end isReadableRole()

	/**
	 * The vocabulary keys a declaration forwards.
	 *
	 * @param mixed $annotation The declaration as stored.
	 *
	 * @return array<int, string> The forwarded keys, de-duplicated, in declaration order.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function forwards(mixed $annotation): array {
		if (is_array($annotation) === false) {
			return [];
		}

		$keys = [];
		foreach (array_keys($this->pairs(annotation: $annotation)) as $role) {
			$key = (self::SOURCE_ALIASES[(string)$role] ?? (string)$role);
			if ($this->vocabulary->hasKey(key: $key) === true) {
				$keys[] = $key;
			}
		}

		return array_values(array_unique($keys));
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
	 * @spec openspec/specs/runtime-schema-api/spec.md
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
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function describe(mixed $annotation, string $path = self::ANNOTATION): array {
		$forwards = $this->forwards(annotation: $annotation);
		$narrows = $this->narrowing(annotation: $annotation);

		return array_merge(
			$this->identity(declaration: $this->asArray(value: $annotation), path: $path),
			[
				'forwards' => $forwards,
				'narrows' => $narrows,
				'forwardsType' => in_array('type', $forwards, true),
				'counts' => $this->counts(forwards: $forwards, narrows: $narrows),
				'errors' => $this->validate(annotation: $annotation, path: $path),
			]
		);
	}//end describe()

	/**
	 * Where a declaration sits and what it names: app, form, definitions and map.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 * @param string               $path        Where the declaration sits.
	 *
	 * @return array<string, mixed> The identifying part of the description.
	 */
	private function identity(array $declaration, string $path): array {
		return [
			'path' => $path,
			'app' => (string)($declaration['app'] ?? ''),
			'form' => (string)($declaration['form'] ?? ''),
			'definitions' => ($declaration['definitions'] ?? null),
			'map' => $this->pairs(annotation: $declaration),
		];
	}//end identity()

	/**
	 * How many keys are forwarded, left out, and in the vocabulary.
	 *
	 * @param array<int, string> $forwards The forwarded keys.
	 * @param array<int, string> $narrows  The keys left out.
	 *
	 * @return array{forwards: int, narrows: int, vocabulary: int} The counts.
	 */
	private function counts(array $forwards, array $narrows): array {
		return [
			'forwards' => count($forwards),
			'narrows' => count($narrows),
			'vocabulary' => count($this->vocabulary->keys()),
		];
	}//end counts()

	/**
	 * The role-to-field pairs, whichever spelling the declaration used.
	 *
	 * `map` names the vocabulary role on the LEFT and the app's own field on
	 * the right, which is the direction `propertiesFromDefinitions` reads.
	 * `forwards` is the short spelling for a form whose fields are already
	 * named after the vocabulary, so role and field are the same word.
	 *
	 * @param array $annotation The declaration.
	 *
	 * @return array<string, mixed> Vocabulary role to the app's field name.
	 */
	private function pairs(array $annotation): array {
		$map = ($annotation['map'] ?? null);
		if (is_array($map) === true) {
			return $this->asArray(value: $map);
		}

		$forwards = ($annotation['forwards'] ?? null);
		if (is_array($forwards) === false) {
			return [];
		}

		$named = array_values(array_filter($forwards, 'is_string'));
		return array_combine($named, $named);
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
			if ($value !== null && is_string($value) === false) {
				$errors[] = $this->refusal(template: 'not-a-string', where: ['path' => $path, 'key' => $field]);
			}
		}

		return $errors;
	}//end validateAppAndForm()

	/**
	 * Check the declaration says what it forwards, once, and names its definitions.
	 *
	 * @param array $annotation The declaration.
	 * @param string $path Where the declaration sits.
	 *
	 * @return array<int, array{code: string, key: string, path: string, message: string}> The refusals.
	 */
	private function validateShape(array $annotation, string $path): array {
		$template = ($this->spellingRefusal(annotation: $annotation) ?? $this->definitionsRefusal(annotation: $annotation));
		if ($template === null) {
			return [];
		}

		return [$this->refusal(template: $template, where: ['path' => $path])];
	}//end validateShape()

	/**
	 * The refusal for a declaration that spells what it forwards twice, or not at all.
	 *
	 * @param array $annotation The declaration.
	 *
	 * @return string|null The refusal template, or null when there is exactly one spelling.
	 */
	private function spellingRefusal(array $annotation): ?string {
		$hasMap = is_array($annotation['map'] ?? null);
		$hasForwards = is_array($annotation['forwards'] ?? null);
		if ($hasMap === true && $hasForwards === true) {
			return 'two-spellings';
		}

		if ($hasMap === false && $hasForwards === false) {
			return 'forwards-nothing';
		}

		return null;
	}//end spellingRefusal()

	/**
	 * The refusal for a declaration without `definitions`.
	 *
	 * `propertiesFromDefinitions` skips a declaration with no `definitions`,
	 * so storing one would be an annotation that reads as configured and
	 * renders no field at all.
	 *
	 * @param array $annotation The declaration.
	 *
	 * @return string|null The refusal template, or null when definitions are named.
	 */
	private function definitionsRefusal(array $annotation): ?string {
		$definitions = ($annotation['definitions'] ?? null);
		if ($definitions === null || $definitions === '' || $definitions === []) {
			return 'no-definitions';
		}

		return null;
	}//end definitionsRefusal()

	/**
	 * One refusal, built from its template.
	 *
	 * @param string                $template The template name in REFUSALS.
	 * @param array<string, string> $where    The `path`, and the `key` when the template leaves it open.
	 *
	 * @return array{code: string, key: string, path: string, message: string} The refusal.
	 */
	private function refusal(string $template, array $where): array {
		$refusal = self::REFUSALS[$template];
		$key = ($where['key'] ?? $refusal['key']);

		return [
			'code' => $refusal['code'],
			'key' => $key,
			'path' => $where['path'],
			'message' => strtr($refusal['message'], ['{key}' => $key, '{path}' => $where['path']]),
		];
	}//end refusal()

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
