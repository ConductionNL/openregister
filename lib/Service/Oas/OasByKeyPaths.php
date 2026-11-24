<?php

/**
 * Describes the by-key paths of a schema that declares an identity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;

/**
 * The `GET`, `PUT`, `PATCH` and `DELETE .../by-key` operations of a schema with
 * an identity: the item operations of `.../{id}`, addressed by the identity's
 * properties as query parameters instead of the id, with the 400 for a missing
 * key value and the 409 for a key that matches more than one object.
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */
final class OasByKeyPaths {

	/**
	 * The HTTP methods the by-key routes serve.
	 *
	 * @var list<string>
	 */
	private const METHODS = ['get', 'put', 'patch', 'delete'];

	/**
	 * The query parameter types a property type maps to.
	 *
	 * @var array<string, string>
	 */
	private const PARAMETER_TYPES = ['integer' => 'integer', 'number' => 'number'];

	/**
	 * The error body, as the document's other error responses name it.
	 *
	 * @var array<string, mixed>
	 */
	private const ERROR_CONTENT = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];

	/**
	 * The by-key path item, or null when the schema declares no identity.
	 *
	 * @param object               $schema   The schema.
	 * @param array<string, mixed> $itemPath The finished `.../{id}` path item.
	 *
	 * @return array<string, mixed>|null The path item.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public static function pathItem(object $schema, array $itemPath): ?array {
		$configuration = null;
		if (method_exists($schema, 'getConfiguration') === true && is_array($schema->getConfiguration()) === true) {
			$configuration = $schema->getConfiguration();
		}

		$identity = SchemaDeclarationValidators::uniqueConstraints()->identity(configuration: $configuration);
		if ($identity === null) {
			return null;
		}

		$keyParameters = self::keyParameters(schema: $schema, properties: $identity['properties']);
		$pathItem      = [];
		foreach (self::METHODS as $method) {
			if (isset($itemPath[$method]) === true) {
				$pathItem[$method] = self::byKey(operation: $itemPath[$method], keyParameters: $keyParameters, identityName: $identity['name']);
			}
		}

		return $pathItem;
	}//end pathItem()

	/**
	 * One item operation addressed by key: no id, the key parameters first,
	 * a distinct operation id, and the key's own error responses.
	 *
	 * @param array<string, mixed>       $operation     The `.../{id}` operation.
	 * @param list<array<string, mixed>> $keyParameters The key's query parameters.
	 * @param string                     $identityName  The identity constraint's name.
	 *
	 * @return array<string, mixed>
	 */
	private static function byKey(array $operation, array $keyParameters, string $identityName): array {
		$others = array_values(
			array_filter(
				(array)($operation['parameters'] ?? []),
				static fn (mixed $parameter): bool => (is_array($parameter) === false || ($parameter['in'] ?? null) !== 'path')
			)
		);

		$operation['operationId'] = (string)($operation['operationId'] ?? '') . 'ByKey';
		$operation['summary']     = (string)($operation['summary'] ?? '') . ' by its key';
		$addressing = ' Addressed by the identity "' . $identityName . '" instead of the id.';
		$operation['description'] = trim((string)($operation['description'] ?? '') . $addressing);
		$operation['parameters']  = array_merge($keyParameters, $others);
		$content = (array)($operation['responses']['404']['content'] ?? self::ERROR_CONTENT);
		$operation['responses']   = (array)($operation['responses'] ?? []) + [
			'400' => ['description' => 'A value of the key is missing.', 'content' => $content],
			'409' => ['description' => 'More than one object has this key.', 'content' => $content],
		];
		$operation['responses']['404'] = ['description' => 'No object has this key, or the schema declares no identity.', 'content' => $content];

		return $operation;
	}//end byKey()

	/**
	 * The required query parameter of each identity property, typed from the schema.
	 *
	 * @param object       $schema     The schema.
	 * @param list<string> $properties The identity's properties, in declared order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function keyParameters(object $schema, array $properties): array {
		$definitions = [];
		if (method_exists($schema, 'getProperties') === true && is_array($schema->getProperties()) === true) {
			$definitions = $schema->getProperties();
		}

		$parameters = [];
		foreach ($properties as $property) {
			$type = (string)($definitions[$property]['type'] ?? 'string');
			$parameters[] = [
				'name' => $property,
				'in' => 'query',
				'required' => true,
				'description' => 'Value of the key property ' . $property . '.',
				'schema' => ['type' => (self::PARAMETER_TYPES[$type] ?? 'string')],
			];
		}

		return $parameters;
	}//end keyParameters()
}//end class
