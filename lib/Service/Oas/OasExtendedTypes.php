<?php

/**
 * The shared OpenAPI components of the structured extended field types.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * A `calendar-range` or `image-url` property is described by reference to one
 * named component, `CalendarRange` or `ImageUrl`, which the document defines
 * once however many properties use it.
 *
 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
 */
final class OasExtendedTypes {

	/**
	 * The component name per property type.
	 *
	 * @var array<string, string>
	 */
	public const COMPONENT_NAMES = [
		'calendar-range' => 'CalendarRange',
		'image-url' => 'ImageUrl',
	];

	/**
	 * The component definitions, by component name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const COMPONENTS = [
		'CalendarRange' => [
			'type' => 'object',
			'description' => 'A period: a start and an end kept together. The end is not before the start.',
			'required' => ['start', 'end'],
			'properties' => [
				'start' => ['type' => 'string', 'description' => 'The start, a date or a date-time as the property declares.'],
				'end' => ['type' => 'string', 'description' => 'The end, a date or a date-time as the property declares.'],
			],
		],
		'ImageUrl' => [
			'type' => 'object',
			'description' => 'An image address with its size, alt text and file format. A bare URL is stored as { url }.',
			'required' => ['url'],
			'properties' => [
				'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'The image address.'],
				'width' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The width in pixels.'],
				'height' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The height in pixels.'],
				'altText' => ['type' => 'string', 'description' => 'The text a screen reader reads for the image.'],
				'format' => ['type' => 'string', 'enum' => ['jpeg', 'png', 'webp', 'avif', 'svg'], 'description' => 'The image file format.'],
			],
		],
	];

	/**
	 * The reference a property of one of these types is described by, or null for any other property.
	 *
	 * @param mixed $definition The property definition.
	 *
	 * @return array<string, string>|null The `$ref`, with the property's own description when it has one.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public static function referenceFor(mixed $definition): ?array {
		if (is_array($definition) === false || is_string($definition['type'] ?? null) === false) {
			return null;
		}

		$component = (self::COMPONENT_NAMES[$definition['type']] ?? null);
		if ($component === null) {
			return null;
		}

		$reference = ['$ref' => '#/components/schemas/' . $component];
		if (is_string($definition['description'] ?? null) === true && $definition['description'] !== '') {
			$reference['description'] = $definition['description'];
		}

		return $reference;
	}//end referenceFor()

	/**
	 * The document with each component that something references defined once.
	 *
	 * A component the document already holds, such as a schema of the same
	 * title, is left as it is.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return array<string, mixed> The document.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public static function withUsedComponents(array $oas): array {
		$used = self::referencedNames(node: $oas);
		foreach (self::COMPONENTS as $name => $component) {
			if (in_array($name, $used, true) === true && isset($oas['components']['schemas'][$name]) === false) {
				$oas['components']['schemas'][$name] = $component;
			}
		}

		return $oas;
	}//end withUsedComponents()

	/**
	 * The names of this class's components a part of the document references.
	 *
	 * @param mixed $node A part of the document.
	 *
	 * @return array<int, string> The names, possibly repeated.
	 */
	private static function referencedNames(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$names = [];
		$ref = ($node['$ref'] ?? null);
		if (is_string($ref) === true && str_starts_with($ref, '#/components/schemas/') === true) {
			$name = substr($ref, strlen('#/components/schemas/'));
			if (isset(self::COMPONENTS[$name]) === true) {
				$names[] = $name;
			}
		}

		foreach ($node as $child) {
			$names = array_merge($names, self::referencedNames(node: $child));
		}

		return $names;
	}//end referencedNames()
}//end class
