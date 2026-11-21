<?php

/**
 * What a visitor without an account sees of a form: its title, description, fields and steps.
 *
 * A form is open to such a visitor when it declares `access: "anonymous"`. A
 * form that declares any other `access` stays closed to them. A form without
 * `access` keeps the older `audience` reading: open when it is `public`, the
 * default. The form must be published and must write somewhere, as for a
 * submit ({@see FormDefinitionResolver}).
 *
 * The fields are the form's own `fields` when it declares them, else they
 * are derived from the properties of the schema its single write targets.
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
 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Reads a stored form for the public form page.
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
 */
class PublicFormDefinition {

	/**
	 * The `access` that opens a form to visitors without an account.
	 *
	 * @var string
	 */
	public const ACCESS_ANONYMOUS = 'anonymous';

	/**
	 * Schema property types and the form field type each renders as.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'string'  => 'string',
		'integer' => 'number',
		'number'  => 'number',
		'boolean' => 'boolean',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects The object operations, read without RBAC: the form decides who may open it.
	 * @param SchemaMapperInterface $schemas The schema mapper, for fields derived from the destination.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly SchemaMapperInterface $schemas,
	) {
	}//end __construct()

	/**
	 * The form as a visitor without an account may see it, or null when it is not open to them.
	 *
	 * @param string $formId The form id.
	 *
	 * @return array{id: string, title: string, description: string, fields: array<int, array<string, mixed>>, steps: array<int, mixed>}|null The form.
	 *
	 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
	 */
	public function forVisitor(string $formId): ?array {
		$body = $this->bodyOf(formId: $formId);
		if (self::isPublished(body: $body) === false || self::opensToVisitors(body: $body) === false) {
			return null;
		}

		$writes = self::writesOf(body: $body);
		if ($writes === []) {
			return null;
		}

		return [
			'id'          => $formId,
			'title'       => (string)($body['title'] ?? $body['name'] ?? ''),
			'description' => (string)($body['description'] ?? ''),
			'fields'      => $this->fieldsOf(body: $body, writes: $writes),
			'steps'       => self::listOf(value: ($body['steps'] ?? [])),
		];
	}//end forVisitor()

	/**
	 * Whether a form opens to a visitor without an account.
	 *
	 * @param array<string, mixed> $body The form body.
	 *
	 * @return bool True for `access: anonymous`, or no `access` and a public audience.
	 *
	 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
	 */
	public static function opensToVisitors(array $body): bool {
		if (array_key_exists('access', $body) === true) {
			return $body['access'] === self::ACCESS_ANONYMOUS;
		}

		$audience = (string)($body['audience'] ?? 'public');
		return $audience === '' || $audience === 'public';
	}//end opensToVisitors()

	/**
	 * The stored form body; empty when it cannot be read.
	 *
	 * @param string $formId The form id.
	 *
	 * @return array<string, mixed> The body.
	 */
	private function bodyOf(string $formId): array {
		try {
			$entity = $this->objects->find(id: $formId, options: new ObjectFindOptions(audit: false), _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return [];
		}

		if ($entity === null) {
			return [];
		}

		return $entity->getObject();
	}//end bodyOf()

	/**
	 * Whether the form is published.
	 *
	 * @param array<string, mixed> $body The form body.
	 *
	 * @return bool True when published.
	 */
	private static function isPublished(array $body): bool {
		return ($body['status'] ?? null) === 'published' || ($body['published'] ?? null) === true;
	}//end isPublished()

	/**
	 * The form's writes: its `writes`, or its single `destination`.
	 *
	 * @param array<string, mixed> $body The form body.
	 *
	 * @return array<int, array<string, mixed>> The writes.
	 */
	private static function writesOf(array $body): array {
		$writes = self::listOf(value: ($body['writes'] ?? []));
		if ($writes !== []) {
			return array_values(array_filter($writes, 'is_array'));
		}

		$destination = ($body['destination'] ?? null);
		if (is_array($destination) === false || trim((string)($destination['schema'] ?? '')) === '') {
			return [];
		}

		return [['register' => ($destination['register'] ?? null), 'schema' => $destination['schema']]];
	}//end writesOf()

	/**
	 * The fields to render: the form's own, else derived from the single write's schema.
	 *
	 * @param array<string, mixed>             $body   The form body.
	 * @param array<int, array<string, mixed>> $writes The form's writes.
	 *
	 * @return array<int, array<string, mixed>> The fields.
	 */
	private function fieldsOf(array $body, array $writes): array {
		$declared = array_values(
			array_filter(
				self::listOf(value: ($body['fields'] ?? [])),
				static fn (mixed $field): bool => is_array($field) === true && is_string($field['key'] ?? null) === true
			)
		);
		if ($declared !== [] || count($writes) !== 1) {
			return $declared;
		}

		return self::fieldsFromSchema(schema: $this->schemaOf(write: $writes[0]));
	}//end fieldsOf()

	/**
	 * Form fields for a schema's plain properties, in declaration order.
	 *
	 * Objects, arrays, read-only and underscore-prefixed properties are left out:
	 * a visitor fills in values, not structures or system fields.
	 *
	 * @param array{properties: array<string, mixed>, required: array<int, string>} $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The fields.
	 *
	 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
	 */
	public static function fieldsFromSchema(array $schema): array {
		$fields = [];
		foreach ($schema['properties'] as $key => $property) {
			$key = (string)$key;
			if (is_array($property) === false || str_starts_with($key, '_') === true || ($property['readOnly'] ?? false) === true) {
				continue;
			}

			$field = self::fieldFor(key: $key, property: $property);
			if ($field !== null) {
				$field['required'] = in_array($key, $schema['required'], true);
				$fields[] = $field;
			}
		}

		return $fields;
	}//end fieldsFromSchema()

	/**
	 * The field for one property, or null for a type a visitor does not fill in.
	 *
	 * @param string               $key      The property name.
	 * @param array<string, mixed> $property The property definition.
	 *
	 * @return array<string, mixed>|null The field.
	 */
	private static function fieldFor(string $key, array $property): ?array {
		$type = self::FIELD_TYPES[(string)($property['type'] ?? 'string')] ?? null;
		if ($type === null) {
			return null;
		}

		$field = ['key' => $key, 'label' => (string)($property['title'] ?? $key), 'type' => $type];
		if (is_string($property['description'] ?? null) === true && $property['description'] !== '') {
			$field['description'] = $property['description'];
		}

		if ($type === 'string' && is_array($property['enum'] ?? null) === true && $property['enum'] !== []) {
			$field['type'] = 'enum';
			$field['enum'] = array_values($property['enum']);
		}

		return $field;
	}//end fieldFor()

	/**
	 * The properties and required list of a write's schema; empty when it cannot be read.
	 *
	 * @param array<string, mixed> $write The write.
	 *
	 * @return array{properties: array<string, mixed>, required: array<int, string>} The schema.
	 */
	private function schemaOf(array $write): array {
		try {
			$schema = $this->schemas->find((string)($write['schema'] ?? ''), _multitenancy: false);
		} catch (Throwable) {
			return ['properties' => [], 'required' => []];
		}

		return [
			'properties' => self::listOf(value: $schema->getProperties()),
			'required'   => array_values(array_filter(self::listOf(value: $schema->getRequired()), 'is_string')),
		];
	}//end schemaOf()

	/**
	 * The value when it is an array, else an empty one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed> The array.
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listOf()
}//end class
