<?php

/**
 * What an imported schema must look like, and how its properties are normalised.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Refuses a schema that cannot be imported and normalises the shape of its properties.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportedSchemaShape
{

	/**
	 * The minimal shape every imported schema must have.
	 *
	 * Deliberately permissive: only what would fail downstream is refused (not
	 * an object, no `properties`, `properties` of the wrong type). The full
	 * OpenAPI 3.1 meta-schema in `lib/Service/Resources/meta/` would over-reject,
	 * because register schemas are JSON Schema with extensions, not OAS documents.
	 *
	 * @var array<string, mixed>
	 */
	private const META_SCHEMA = [
		'type' => 'object',
		'required' => ['properties'],
		'properties' => [
			'title' => ['type' => 'string'],
			'description' => ['type' => 'string'],
			'slug' => ['type' => 'string'],
			'properties' => ['type' => 'object'],
			'required' => ['type' => 'array', 'items' => ['type' => 'string']],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param OasRequestValidator|null $validator The shape validator; null stands the shape check down.
	 * @param LoggerInterface          $logger    The logger.
	 */
	public function __construct(
		private readonly ?OasRequestValidator $validator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a schema whose shape fails the minimal meta-schema, before it is mutated.
	 *
	 * A structurally invalid import then fails with a clear error rather than
	 * deep in the mapper.
	 *
	 * @param array $data The schema data.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the shape is invalid.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function assertShape(array $data): void {
		if ($this->validator === null) {
			return;
		}

		$errors = $this->validator->validate(body: $data, schema: self::META_SCHEMA);
		if ($errors === []) {
			return;
		}

		$described = array_map(static fn ($e) => ($e['path'] . ' ' . $e['message']), $errors);
		throw new RuntimeException('imported schema fails OAS-shape validation: ' . implode('; ', $described));
	}//end assertShape()

	/**
	 * Refuse a schema fragment without a non-empty slug.
	 *
	 * The slug lookup would otherwise raise a \TypeError, an \Error the
	 * caller's `catch (Exception)` never sees, aborting the whole register
	 * import on one bad fragment. A \RuntimeException lets the caller skip
	 * this fragment and import the rest.
	 *
	 * @param array $data The schema data.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the slug is missing or blank.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function assertSlug(array $data): void {
		$slug = $data['slug'] ?? null;
		if (is_string($slug) === true && trim($slug) !== '') {
			return;
		}

		$message = $this->missingSlugMessage(data: $data);
		$this->logger->error(message: ImportLogTag::PREFIX.$message, context: ['file' => __FILE__, 'line' => __LINE__]);
		throw new RuntimeException($message);
	}//end assertSlug()

	/**
	 * The refusal for a fragment without a slug, naming its title when it has one.
	 *
	 * @param array $data The schema data.
	 *
	 * @return string The message.
	 */
	private function missingSlugMessage(array $data): string {
		$title = (string)($data['title'] ?? '');
		$hint = 'no title';
		if ($title !== '') {
			$hint = "title '{$title}'";
		}

		return "imported schema fragment is missing a 'slug' ({$hint}); skipping fragment";
	}//end missingSlugMessage()

	/**
	 * Give an imported property an array shape, a title, a type and object-typed empty configurations.
	 *
	 * Empty `objectConfiguration` / `fileConfiguration` maps decode from JSON
	 * as `[]` and are turned back into objects. The binary-ish formats
	 * `string`, `binary` and `byte` are dropped from the property and its items.
	 *
	 * @param int|string $key      The property name, the title when none is set.
	 * @param mixed      $property The property definition.
	 *
	 * @return array The normalised property.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function normalisedProperty(int|string $key, mixed $property): array {
		$property = $this->objectAsArray(value: $property);
		if (empty($property['title']) === true) {
			$property['title'] = $key;
		}

		$property['type'] ??= 'string';

		return $this->withCleanedItems(property: $this->cleanedDefinition(definition: $property));
	}//end normalisedProperty()

	/**
	 * A property with its `items`, when it has any, in array form and cleaned.
	 *
	 * @param array $property The property definition.
	 *
	 * @return array The property.
	 */
	private function withCleanedItems(array $property): array {
		if (($property['items'] ?? null) === null) {
			return $property;
		}

		$property['items'] = $this->objectAsArray(value: $property['items']);
		if (is_array($property['items']) === true) {
			$property['items'] = $this->cleanedDefinition(definition: $property['items']);
		}

		return $property;
	}//end withCleanedItems()

	/**
	 * An object as an array; anything else as it is.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value.
	 */
	private function objectAsArray(mixed $value): mixed {
		if (is_object($value) === true) {
			return (array)$value;
		}

		return $value;
	}//end objectAsArray()

	/**
	 * A property or items definition with object-typed empty configurations and no binary-ish format.
	 *
	 * @param array $definition A property or items definition.
	 *
	 * @return array The definition.
	 */
	private function cleanedDefinition(array $definition): array {
		foreach (['objectConfiguration', 'fileConfiguration'] as $configKey) {
			if (($definition[$configKey] ?? null) === []) {
				$definition[$configKey] = new stdClass();
			}
		}

		if (in_array(($definition['format'] ?? null), ['string', 'binary', 'byte'], true) === true) {
			unset($definition['format']);
		}

		return $definition;
	}//end cleanedDefinition()
}//end class
