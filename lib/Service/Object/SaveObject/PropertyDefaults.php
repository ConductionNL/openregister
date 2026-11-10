<?php

/**
 * OpenRegister PropertyDefaults
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCP\IURLGenerator;

/**
 * Applies the default and constant values a schema declares for its properties.
 *
 * A property's `defaultBehavior` says when its default applies: 'false' (the default)
 * when the value is missing or null, 'falsy' also when it is an empty string or an
 * empty array, 'always' on every save.
 *
 * A default holding `{{ property }}` copies that value, keeping arrays and other types.
 * Other templates go through MetadataHydrationHandler, which supports pipe filters
 * (`| map:`) and fallbacks (`| field2`).
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class PropertyDefaults {

	/**
	 * A default that only names a property.
	 */
	private const PROPERTY_REFERENCE = '/^\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}$/';

	/**
	 * @param MetadataHydrationHandler $hydration Renders templates.
	 * @param IURLGenerator            $urls      Builds the schema object.
	 */
	public function __construct(
		private readonly MetadataHydrationHandler $hydration,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The data of a save with its defaults and constants.
	 *
	 * Constants always win. A template that fails to render keeps the raw default, and
	 * a reference to a missing property gives null. No slug is added here: the slug is
	 * entity metadata that ObjectSlugHandler decides (a `slug` key in the data made the
	 * magic mapper warn on every save, #4037).
	 *
	 * @param Schema              $schema  The schema of the object.
	 * @param DefaultValueContext $context The incoming data and what templates see.
	 *
	 * @return array The data.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function forSave(Schema $schema, DefaultValueContext $context): array {
		$declared = $this->declaredProperties(schema: $schema);
		if ($declared === null) {
			return $context->data;
		}

		$context->schemaProperties = $declared;
		$properties = self::titled(declared: $declared);

		return array_merge(
			$context->data,
			$this->renderedDefaults(context: $context, properties: $properties),
			self::constants(properties: $properties)
		);
	}//end forSave()

	/**
	 * The data with its defaultBehavior "always" defaults, for use before validation.
	 *
	 * @param Schema $schema The schema of the object.
	 * @param array  $data   The data, which is also what templates see.
	 *
	 * @return array The data. A default that resolves to nothing leaves it as it was.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function always(Schema $schema, array $data): array {
		$declared = $this->declaredProperties(schema: $schema);
		if ($declared === null) {
			return $data;
		}

		$due = array_filter($declared, static fn ($property): bool => ($property['defaultBehavior'] ?? null) === 'always');

		return $this->fill(context: new DefaultValueContext(data: $data, twigContext: $data, schemaProperties: $declared), due: $due);
	}//end always()

	/**
	 * The data with the defaults that apply to it, without a stored object (bulk paths).
	 *
	 * @param Schema $schema The schema of the object.
	 * @param array  $data   The data, which is also what templates see.
	 *
	 * @return array The data. A default that resolves to nothing leaves it as it was.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function applying(Schema $schema, array $data): array {
		$declared = $this->declaredProperties(schema: $schema);
		if ($declared === null) {
			return $data;
		}

		return $this->fill(context: new DefaultValueContext(data: $data, twigContext: $data, schemaProperties: $declared), due: $declared);
	}//end applying()

	/**
	 * The property definitions of a schema, or null when it has none to read.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array|null The definitions by property name.
	 */
	private function declaredProperties(Schema $schema): ?array {
		try {
			$schemaObject = json_decode(json_encode($schema->getSchemaObject($this->urls)), associative: true);
		} catch (Exception $e) {
			return null;
		}

		$properties = ($schemaObject['properties'] ?? null);
		if (is_array($properties) === false) {
			return null;
		}

		return $properties;
	}//end declaredProperties()

	/**
	 * The definitions as a list, each with its name as title and a default key.
	 *
	 * @param array $declared The definitions by property name.
	 *
	 * @return array The list.
	 */
	private static function titled(array $declared): array {
		return array_map(
			static function (string $key, array $property): array {
				$property['default'] = ($property['default'] ?? null);
				$property['title'] = $key;
				return $property;
			},
			array_keys($declared),
			$declared
		);
	}//end titled()

	/**
	 * The constants of these properties, by property name.
	 *
	 * @param array $properties The titled definitions.
	 *
	 * @return array The constants.
	 */
	private static function constants(array $properties): array {
		$constants = [];
		foreach ($properties as $property) {
			if (($property['const'] ?? null) !== null) {
				$constants[$property['title']] = $property['const'];
			}
		}

		return $constants;
	}//end constants()

	/**
	 * The rendered defaults that apply to a save, by property name.
	 *
	 * @param DefaultValueContext $context    The incoming data and what templates see.
	 * @param array               $properties The titled definitions.
	 *
	 * @return array The values.
	 */
	private function renderedDefaults(DefaultValueContext $context, array $properties): array {
		$rendered = [];
		foreach ($properties as $property) {
			if ($property['default'] !== null && self::applies(property: $property, data: $context->data) === true) {
				$rendered[$property['title']] = $this->renderOrRaw(context: $context, default: $property['default']);
			}
		}

		return $rendered;
	}//end renderedDefaults()

	/**
	 * Fill in the due defaults one by one, each seeing the values set before it.
	 *
	 * @param DefaultValueContext $context The data, as what templates see.
	 * @param array               $due     The definitions to consider, by property name.
	 *
	 * @return array The data.
	 */
	private function fill(DefaultValueContext $context, array $due): array {
		foreach ($due as $key => $property) {
			$default = ($property['default'] ?? null);
			$rule = ['title' => $key, 'defaultBehavior' => ($property['defaultBehavior'] ?? 'false')];
			if ($default === null || self::applies(property: $rule, data: $context->twigContext) === false) {
				continue;
			}

			$value = $this->renderOrNull(context: $context, default: $default);
			if ($value !== null) {
				$context->twigContext[$key] = $value;
			}
		}

		return $context->twigContext;
	}//end fill()

	/**
	 * Whether a property's default applies to the data, by its defaultBehavior.
	 *
	 * @param array $property The definition, with its name as title.
	 * @param array $data     The data.
	 *
	 * @return bool True when the default is applied.
	 */
	private static function applies(array $property, array $data): bool {
		$behavior = ($property['defaultBehavior'] ?? 'false');
		if ($behavior === 'always') {
			return true;
		}

		$value = ($data[$property['title']] ?? null);
		if ($behavior === 'falsy') {
			return $value === null || $value === '' || $value === [];
		}

		return $value === null;
	}//end applies()

	/**
	 * A default rendered, or the raw default when the template fails.
	 *
	 * @param DefaultValueContext $context What templates see.
	 * @param mixed               $default The declared default.
	 *
	 * @return mixed The value.
	 */
	private function renderOrRaw(DefaultValueContext $context, mixed $default): mixed {
		try {
			return $this->render(context: $context, default: $default);
		} catch (Exception $e) {
			return $default;
		}
	}//end renderOrRaw()

	/**
	 * A default rendered, or null when the template fails.
	 *
	 * @param DefaultValueContext $context What templates see.
	 * @param mixed               $default The declared default.
	 *
	 * @return mixed The value.
	 */
	private function renderOrNull(DefaultValueContext $context, mixed $default): mixed {
		try {
			return $this->render(context: $context, default: $default);
		} catch (Exception $e) {
			return null;
		}
	}//end renderOrNull()

	/**
	 * Render a default: a reference copies a value, other templates render, the rest stays.
	 *
	 * @param DefaultValueContext $context What templates see.
	 * @param mixed               $default The declared default.
	 *
	 * @return mixed The value, null for a reference to a missing property.
	 */
	private function render(DefaultValueContext $context, mixed $default): mixed {
		if (self::isTemplate(default: $default) === false) {
			return $default;
		}

		if (preg_match(self::PROPERTY_REFERENCE, $default, $matches) === 1) {
			return ($context->twigContext[$matches[1]] ?? null);
		}

		$source = new TemplateSource(data: $context->twigContext, schemaProperties: $context->schemaProperties);
		return $this->hydration->renderTemplate(template: $default, source: $source);
	}//end render()

	/**
	 * Whether a default holds a template.
	 *
	 * @param mixed $default The declared default.
	 *
	 * @return bool True for a string with `{{` and `}}`.
	 */
	private static function isTemplate(mixed $default): bool {
		return is_string($default) === true
			&& str_contains(haystack: $default, needle: '{{') === true
			&& str_contains(haystack: $default, needle: '}}') === true;
	}//end isTemplate()
}//end class
