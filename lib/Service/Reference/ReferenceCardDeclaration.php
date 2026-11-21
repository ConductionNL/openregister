<?php

/**
 * OpenRegister reference card declaration.
 *
 * What a schema says its reference card shows: the property holding the title,
 * up to three summary properties, an icon, and a property holding an image.
 * A schema that declares none renders the object's title and its schema name,
 * which is uninformative and never misleading (design D-3).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Reference;

use OCA\OpenRegister\Db\Schema;

/**
 * The card a schema declares, read from `x-openregister-card`.
 *
 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
 */
final class ReferenceCardDeclaration {

	/**
	 * The annotation key a schema declares its card under.
	 */
	public const ANNOTATION = 'x-openregister-card';

	/**
	 * At most this many summary properties are shown on a card.
	 */
	public const MAX_SUMMARY = 3;

	/**
	 * Constructor.
	 *
	 * @param string|null        $titleProperty The property holding the card's title, or null.
	 * @param array<int, string> $summary       The summary properties, at most three.
	 * @param string|null        $icon          The card's icon name, or null for the schema's own.
	 * @param string|null        $imageProperty The property holding the card's image, or null.
	 *
	 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
	 */
	private function __construct(
		public readonly ?string $titleProperty,
		public readonly array $summary,
		public readonly ?string $icon,
		public readonly ?string $imageProperty,
	) {
	}//end __construct()

	/**
	 * The card a schema declares, or null when it declares none.
	 *
	 * A block that is not an object, or that names nothing usable, reads as no
	 * declaration: the dull default is the right failure for something that
	 * renders in a chat window.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return self|null The declaration, or null.
	 *
	 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
	 */
	public static function of(Schema $schema): ?self {
		$block = (($schema->getConfiguration() ?? [])[self::ANNOTATION] ?? null);
		if (is_array($block) === false) {
			return null;
		}

		$declaration = new self(
			titleProperty: self::property(value: ($block['titleProperty'] ?? null)),
			summary: self::summaryProperties(value: ($block['summary'] ?? null)),
			icon: self::property(value: ($block['icon'] ?? null)),
			imageProperty: self::property(value: ($block['imageProperty'] ?? null))
		);
		if ($declaration->declaresNothing() === true) {
			return null;
		}

		return $declaration;
	}//end of()

	/**
	 * The faults of a declared card: what it names and the schema does not have.
	 *
	 * @param mixed                $block      The declared block.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, string> The faults, empty when the card is valid or absent.
	 *
	 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
	 */
	public static function faults(mixed $block, array $properties): array {
		if ($block === null) {
			return [];
		}

		if (is_array($block) === false) {
			return [self::ANNOTATION . ' must be an object.'];
		}

		$faults = [];
		foreach (['titleProperty', 'imageProperty'] as $key) {
			$named = ($block[$key] ?? null);
			if ($named !== null && self::names(property: $named, properties: $properties) === false) {
				$faults[] = self::ANNOTATION . '.' . $key . ' "' . (string)json_encode($named) . '" is not a property of this schema.';
			}
		}

		$summary = ($block['summary'] ?? null);
		if ($summary !== null && (is_array($summary) === false || array_is_list($summary) === false)) {
			return array_merge($faults, [self::ANNOTATION . '.summary must be a list of property names.']);
		}

		if (is_array($summary) === true && count($summary) > self::MAX_SUMMARY) {
			$faults[] = self::ANNOTATION . '.summary names more than ' . self::MAX_SUMMARY . ' properties.';
		}

		foreach ((array)$summary as $named) {
			if (self::names(property: $named, properties: $properties) === false) {
				$faults[] = self::ANNOTATION . '.summary "' . (string)json_encode($named) . '" is not a property of this schema.';
			}
		}

		return $faults;
	}//end faults()

	/**
	 * The card's title from an object, or null when the declaration names none or the value is empty.
	 *
	 * @param array<string, mixed> $objectData The rendered object.
	 *
	 * @return string|null The title.
	 *
	 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
	 */
	public function title(array $objectData): ?string {
		if ($this->titleProperty === null) {
			return null;
		}

		return self::text(value: ($objectData[$this->titleProperty] ?? null));
	}//end title()

	/**
	 * The card's summary rows: label and value per declared property that the object fills.
	 *
	 * @param array<string, mixed> $objectData The rendered object.
	 *
	 * @return array<int, array{label: string, value: string}> The rows, at most three.
	 *
	 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
	 */
	public function summaryRows(array $objectData): array {
		$rows = [];
		foreach ($this->summary as $property) {
			$value = self::text(value: ($objectData[$property] ?? null));
			if ($value !== null) {
				$rows[] = ['label' => ucfirst($property), 'value' => $value];
			}
		}

		return $rows;
	}//end summaryRows()

	/**
	 * The card's image from an object, or null when the declaration names none or the value is empty.
	 *
	 * @param array<string, mixed> $objectData The rendered object.
	 *
	 * @return string|null The image, as the object holds it.
	 *
	 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
	 */
	public function image(array $objectData): ?string {
		if ($this->imageProperty === null) {
			return null;
		}

		$value = ($objectData[$this->imageProperty] ?? null);
		if (is_array($value) === true) {
			$value = ($value['downloadUrl'] ?? $value['url'] ?? null);
		}

		return self::text(value: $value);
	}//end image()

	/**
	 * Whether the declaration names nothing at all.
	 *
	 * @return bool True when it names no title, summary, icon or image.
	 */
	private function declaresNothing(): bool {
		return $this->titleProperty === null && $this->summary === [] && $this->icon === null && $this->imageProperty === null;
	}//end declaresNothing()

	/**
	 * The declared summary properties, the first three that are non-empty strings.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array<int, string> The properties.
	 */
	private static function summaryProperties(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$properties = [];
		foreach ($value as $property) {
			$named = self::property(value: $property);
			if ($named !== null) {
				$properties[] = $named;
			}
		}

		return array_slice($properties, 0, self::MAX_SUMMARY);
	}//end summaryProperties()

	/**
	 * A declared property name, trimmed, or null when it is not a filled string.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string|null The name.
	 */
	private static function property(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end property()

	/**
	 * Whether a declared name is a property of the schema.
	 *
	 * @param mixed                $property   The declared name.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return bool True when the schema has it.
	 */
	private static function names(mixed $property, array $properties): bool {
		return is_string($property) === true && array_key_exists($property, $properties) === true;
	}//end names()

	/**
	 * A value as the card shows it, or null when it is empty or not scalar.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 */
	private static function text(mixed $value): ?string {
		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()
}//end class
