<?php

/**
 * The inputs an action is run with: defaults, validation, storage and digest.
 *
 * Defaults are resolved before validation, at run time:
 *
 * - `@now` is today (`format: date`) or this moment (any other format);
 * - `@user` is the acting user's id;
 * - `@object.<path>` is the value at that path on the object the action runs on.
 *
 * Inputs are validated against the declared JSON Schema by the validator the
 * request bodies are validated with, so formats and enums behave the same in
 * a dialog as anywhere else. `writeOnly` inputs are split off before anything
 * is stored: they reach the steps through {@see ObjectActionSecrets} and are
 * left out of the run context and the audit digest.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;

/**
 * Resolves, validates and digests action inputs.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression::evaluate() reads an object path with the
 *   same `var` semantics `when` uses.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
class ObjectActionInputs {

	/**
	 * Constructor.
	 *
	 * @param OasRequestValidator $validator The JSON Schema validator request bodies use.
	 */
	public function __construct(
		private readonly OasRequestValidator $validator,
	) {
	}//end __construct()

	/**
	 * The inputs with every declared default the caller left out filled in.
	 *
	 * @param ObjectActionInvocation $invocation The action (its declared input schema) and the inputs the caller sent.
	 * @param DefaultScope           $scope      The object and acting user the defaults resolve against.
	 *
	 * @return array<string, mixed> The inputs.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function withDefaults(ObjectActionInvocation $invocation, DefaultScope $scope): array {
		$given = $invocation->inputs;
		foreach ($this->propertiesWithDefault(schema: $invocation->definition->inputs) as $name => $property) {
			if (array_key_exists($name, $given) === false) {
				$given[$name] = $this->resolveDefault(property: $property, scope: $scope);
			}
		}

		return $given;
	}//end withDefaults()

	/**
	 * The input properties that declare a default.
	 *
	 * @param array<string, mixed>|null $schema The declared input schema.
	 *
	 * @return array<array-key, array<string, mixed>> Name => property.
	 */
	private function propertiesWithDefault(?array $schema): array {
		return array_filter(
			(array)($schema['properties'] ?? []),
			static fn (mixed $property): bool => is_array($property) === true && array_key_exists('default', $property) === true
		);
	}//end propertiesWithDefault()

	/**
	 * Why these inputs do not satisfy the declared schema, per field.
	 *
	 * @param array<string, mixed>|null $schema The declared input schema, null when the action takes none.
	 * @param array<string, mixed>      $inputs The inputs, defaults resolved.
	 *
	 * @return list<array{field: string, message: string}> The errors; empty when the inputs are valid.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function errors(?array $schema, array $inputs): array {
		if ($schema === null) {
			return array_map(
				static fn (string $name): array => ['field' => $name, 'message' => 'This action takes no inputs.'],
				array_map('strval', array_keys($inputs))
			);
		}

		$errors = $this->fieldErrors(schema: $schema, inputs: $inputs);

		// The validator reports the object itself above the field that failed
		// inside it; name the field when one is known.
		$named = array_values(array_filter($errors, static fn (array $error): bool => $error['field'] !== ''));
		if ($named !== []) {
			return $named;
		}

		return $errors;
	}//end errors()

	/**
	 * The validator's errors, each named by the first segment of its path.
	 *
	 * @param array<string, mixed> $schema The declared input schema.
	 * @param array<string, mixed> $inputs The inputs.
	 *
	 * @return list<array{field: string, message: string}> The errors.
	 */
	private function fieldErrors(array $schema, array $inputs): array {
		return array_values(
			array_map(
				static fn (array $error): array => [
					'field' => explode('/', ltrim((string)$error['path'], '/'))[0],
					'message' => (string)$error['message'],
				],
				$this->validator->validate(body: (object)$inputs, schema: $schema)
			)
		);
	}//end fieldErrors()

	/**
	 * Split inputs into what may be stored and what must not.
	 *
	 * @param array<string, mixed> $inputs    The inputs.
	 * @param list<string>         $writeOnly The writeOnly input names.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The storable and the writeOnly inputs.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public function split(array $inputs, array $writeOnly): array {
		$secret = array_intersect_key($inputs, array_flip($writeOnly));

		return [array_diff_key($inputs, $secret), $secret];
	}//end split()

	/**
	 * A digest of the storable inputs, for the audit entry.
	 *
	 * @param array<string, mixed> $storable The inputs without writeOnly ones.
	 *
	 * @return string `sha256:<hex>`.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public function digest(array $storable): string {
		ksort($storable);

		return 'sha256:' . hash('sha256', (string)json_encode($storable));
	}//end digest()

	/**
	 * A lifecycle transition's `[{field, required, label?, options?}]` as a JSON Schema object.
	 *
	 * @param list<array<string, mixed>> $inputs The transition's published inputs.
	 *
	 * @return array<string, mixed> The JSON Schema.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
	 */
	public function fromTransitionInputs(array $inputs): array {
		$properties = [];
		foreach ($inputs as $input) {
			$field = (string)($input['field'] ?? '');
			if ($field !== '') {
				$properties[$field] = $this->transitionProperty(input: $input);
			}
		}

		$schema   = ['type' => 'object', 'properties' => (object)$properties];
		$required = $this->requiredFields(inputs: $inputs);
		if ($required !== []) {
			$schema['required'] = $required;
		}

		return $schema;
	}//end fromTransitionInputs()

	/**
	 * The named transition inputs marked `required: true`, in order.
	 *
	 * @param list<array<string, mixed>> $inputs The transition's published inputs.
	 *
	 * @return list<string> The field names.
	 */
	private function requiredFields(array $inputs): array {
		$required = array_filter(
			$inputs,
			static fn (array $input): bool => (string)($input['field'] ?? '') !== '' && ($input['required'] ?? false) === true
		);

		return array_values(array_map(static fn (array $input): string => (string)$input['field'], $required));
	}//end requiredFields()

	/**
	 * One transition input as a JSON Schema property: its label as title, its options as enum.
	 *
	 * @param array<string, mixed> $input The transition input.
	 *
	 * @return object The property.
	 */
	private function transitionProperty(array $input): object {
		$property = [];
		if (isset($input['label']) === true) {
			$property['title'] = (string)$input['label'];
		}

		if (is_array($input['options'] ?? null) === true) {
			$property['enum'] = array_values(array_column($input['options'], 'value'));
		}

		return (object)$property;
	}//end transitionProperty()

	/**
	 * Resolve one declared default.
	 *
	 * @param array<string, mixed> $property The input property.
	 * @param DefaultScope         $scope    The object and the acting user.
	 *
	 * @return mixed The value.
	 */
	private function resolveDefault(array $property, DefaultScope $scope): mixed {
		$default = $property['default'];
		if ($default === '@now') {
			return $this->now(format: ($property['format'] ?? null));
		}

		if ($default === '@user') {
			return $scope->userId;
		}

		if (is_string($default) === true && str_starts_with($default, '@object.') === true) {
			return $this->objectValue(path: substr($default, strlen('@object.')), object: $scope->object);
		}

		return $default;
	}//end resolveDefault()

	/**
	 * A value read from the object's data by dotted path, null without an object.
	 *
	 * @param string            $path   The dotted path.
	 * @param ObjectEntity|null $object The object.
	 *
	 * @return mixed The value.
	 */
	private function objectValue(string $path, ?ObjectEntity $object): mixed {
		return FlowExpression::evaluate(logic: ['var' => $path], data: ($object?->getObject() ?? []));
	}//end objectValue()

	/**
	 * The current time, as a date when the input's format is `date`.
	 *
	 * @param mixed $format The declared format.
	 *
	 * @return string The formatted time.
	 */
	private function now(mixed $format): string {
		if ($format === 'date') {
			return (new DateTimeImmutable())->format('Y-m-d');
		}

		return (new DateTimeImmutable())->format('c');
	}//end now()
}//end class
