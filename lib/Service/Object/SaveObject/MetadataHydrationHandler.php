<?php

/**
 * OpenRegister MetadataHydrationHandler
 *
 * Handler for extracting and hydrating object metadata.
 * Handles name, description, summary and image extraction, and the slugify routine
 * ObjectSlugHandler uses (the slug itself is decided there, #4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Metadata Hydration Handler
 *
 * Handles object metadata extraction and hydration including:
 * - Name extraction from configured field paths
 * - Description and summary extraction
 * - The slugify routine (createSlug) for ObjectSlugHandler
 * - Twig-like template processing for metadata (regex-based, not full Twig)
 * - Pipe-based fallback syntax for field chains
 *
 * Supported objectNameField formats:
 * - Simple field: "naam"
 * - Nested path: "contact.email"
 * - Fallback chain: "name | ggm_naam | identifier" (tries each until one has value)
 * - Template: "{{ voornaam }} {{ achternaam }}"
 * - Template with fallbacks: "{{ name | ggm_naam }} ({{ type }})"
 * - Template with map filter: "{{ field | map: val1=result1, val2=result2 }}"
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObject
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * Reason: Metadata hydration handles multiple complex scenarios for template processing
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class MetadataHydrationHandler {
	/**
	 * The metadata columns, each with its schema configuration key and the
	 * common field names tried, in order, when the configured field is empty.
	 */
	private const METADATA_FIELDS = [
		'name' => ['objectNameField', ['naam', 'name', 'title', 'label', 'titel']],
		'description' => ['objectDescriptionField', ['beschrijvingLang', 'description', 'beschrijving', 'omschrijving']],
		'summary' => ['objectSummaryField', ['beschrijvingKort', 'summary', 'samenvatting', 'shortDescription']],
	];

	/**
	 * Constructor for MetadataHydrationHandler.
	 *
	 * @param LoggerInterface $logger Logger interface for logging operations.
	 * @param CacheHandlerInterface $cacheHandler Cache handler for UUID-to-name resolution.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly CacheHandlerInterface $cacheHandler,
	) {
	}//end __construct()

	/**
	 * Hydrates simple object metadata from schema configuration.
	 *
	 * This method extracts simple metadata fields (name, description, summary)
	 * from the object data based on schema configuration.
	 *
	 * NOTE: Image field handling is kept in SaveObject due to complex file operations.
	 *
	 * Metadata can be configured in schema using:
	 * - Direct field paths: "title", "description"
	 * - Nested paths: "contact.name", "profile.bio"
	 * - Fallback chains: "name | ggm_naam | identifier" (tries each until one has value)
	 * - Twig templates: "{{ firstName }} {{ lastName }}"
	 * - Twig with fallbacks: "{{ name | ggm_naam }} ({{ type }})"
	 *
	 * @param ObjectEntity $entity The object entity to hydrate.
	 * @param Schema $schema The schema containing metadata configuration.
	 *
	 * The slug is not hydrated here: this method also runs where no write
	 * follows (name pre-caching) and on bulk temp entities that do not know
	 * the stored slug. ObjectSlugHandler decides it (#4037).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function hydrateObjectMetadata(ObjectEntity $entity, Schema $schema): void {
		$config = $schema->getConfiguration() ?? [];
		$source = $this->businessSource(entity: $entity, schema: $schema);
		foreach (array_keys(self::METADATA_FIELDS) as $column) {
			$value = $this->metadataValue(source: $source, field: $this->metadataField(config: $config, column: $column));
			if ($value !== null && trim($value) !== '') {
				$entity->{'set' . ucfirst($column)}(trim($value));
			}
		}
	}//end hydrateObjectMetadata()

	/**
	 * The business data of an object with the schema properties.
	 *
	 * The business data is the nested 'object' array of the structured format,
	 * or the object data itself in the flat format. 'object' may also be a
	 * regular string property (e.g., a URL in ObjectInformatieObject); then the
	 * flat data is used.
	 *
	 * @param ObjectEntity $entity The object entity.
	 * @param Schema $schema The object's schema.
	 *
	 * @return TemplateSource The business data and the schema properties.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function businessSource(ObjectEntity $entity, Schema $schema): TemplateSource {
		$data = $entity->getObject();
		if (isset($data['object']) === true && is_array($data['object']) === true) {
			$data = $data['object'];
		}

		return new TemplateSource(data: $data, schemaProperties: $schema->getProperties() ?? []);
	}//end businessSource()

	/**
	 * The configured path and common fields of one metadata column.
	 *
	 * @param array $config The schema configuration.
	 * @param string $column The column: name, description or summary.
	 *
	 * @return MetadataField The column's field definition.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function metadataField(array $config, string $column): MetadataField {
		[$configKey, $commonFields] = self::METADATA_FIELDS[$column];
		return new MetadataField(column: $column, path: $config[$configKey] ?? null, commonFields: $commonFields);
	}//end metadataField()

	/**
	 * The value of one metadata column: the configured field, else the first
	 * filled common field.
	 *
	 * Only the name resolves relation fields to the related object's name.
	 *
	 * @param TemplateSource $source The business data and the schema properties.
	 * @param MetadataField $field The column, its configured path and its common field names.
	 *
	 * @return string|null The value, untrimmed, or null when none is found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function metadataValue(TemplateSource $source, MetadataField $field): ?string {
		$value = null;
		if ($field->path !== null) {
			$properties = $field->relationProperties(schemaProperties: $source->schemaProperties);
			$value = $this->extractMetadataValue(data: $source->data, fieldPath: $field->path, schemaProperties: $properties);
		}

		if ($value === null || trim($value) === '') {
			$value = $this->tryCommonFields(data: $source->data, fieldNames: $field->commonFields);
		}

		return $value;
	}//end metadataValue()

	/**
	 * Try to extract a value from common field names.
	 *
	 * @param array $data The object data.
	 * @param array $fieldNames Array of field names to try in order of preference.
	 *
	 * @return string|null The first non-empty value found, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function tryCommonFields(array $data, array $fieldNames): ?string {
		foreach ($fieldNames as $field) {
			$value = $this->extractMetadataValue(data: $data, fieldPath: $field);
			if ($value !== null && trim($value) !== '') {
				return $value;
			}
		}

		return null;
	}//end tryCommonFields()

	/**
	 * Gets a value from an object using dot notation path.
	 *
	 * Examples:
	 * - "name" returns $data['name']
	 * - "contact.email" returns $data['contact']['email']
	 * - "addresses.0.city" returns $data['addresses'][0]['city']
	 *
	 * @param array $data The object data.
	 * @param string $path The dot notation path (e.g., 'name', 'contact.email', 'address.street').
	 *
	 * @return mixed The value at the path, or null if not found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getValueFromPath(array $data, string $path) {
		$current = $this->rawValueAtPath(data: $data, path: $path);

		// Return raw value — callers handle type conversion.
		// Arrays/objects are kept as-is so relation fields can extract UUIDs.
		if ($current !== null && is_string($current) === false && is_array($current) === false) {
			$current = (string)$current;
		}

		return $current;
	}//end getValueFromPath()

	/**
	 * The value at a dot notation path, uncast.
	 *
	 * @param array $data The object data.
	 * @param string $path The dot notation path.
	 *
	 * @return mixed The value at the path, or null if not found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function rawValueAtPath(array $data, string $path): mixed {
		$current = $data;
		foreach (explode('.', $path) as $key) {
			if (is_array($current) === false || array_key_exists($key, $current) === false) {
				return null;
			}

			$current = $current[$key];
		}

		return $current;
	}//end rawValueAtPath()

	/**
	 * Extracts metadata value from object data with support for twig-like concatenation and fallbacks.
	 *
	 * This method supports multiple formats:
	 * 1. Simple dot notation paths: "naam", "contact.email"
	 * 2. Pipe-separated fallbacks: "name | ggm_naam | identifier" (tries each until one has a value)
	 * 3. Twig-like templates: "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}"
	 * 4. Twig templates with fallbacks: "{{ name | ggm_naam }} - {{ type }}"
	 *
	 * For twig-like templates, it extracts field names from {{ }} syntax and concatenates
	 * their values with spaces, handling empty/null values gracefully.
	 *
	 * @param array $data The object data.
	 * @param string $fieldPath The field path, fallback chain, or twig-like template.
	 * @param array $schemaProperties Optional schema properties for relation field detection.
	 *
	 * @return string|null The extracted/concatenated value, or null if not found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function extractMetadataValue(array $data, string $fieldPath, array $schemaProperties = []): ?string {
		// Check if this is a twig-like template with {{ }} syntax.
		if (str_contains($fieldPath, '{{') === true && str_contains($fieldPath, '}}') === true) {
			return $this->processTwigLikeTemplate(data: $data, template: $fieldPath, schemaProperties: $schemaProperties);
		}

		// Check if this is a pipe-separated fallback chain (without {{ }} syntax).
		if (str_contains($fieldPath, '|') === true) {
			return $this->processFieldWithFallbacks(data: $data, fieldChain: $fieldPath);
		}

		return $this->plainMetadataValue(value: $this->getValueFromPath(data: $data, path: $fieldPath));
	}//end extractMetadataValue()

	/**
	 * A simple field path's value as metadata text.
	 *
	 * A translatable property holds a LANGUAGE MAP, not a value: it is resolved
	 * to one string before json_encode turns it into markup a person then reads
	 * on the page. Any other array is converted to a JSON string to satisfy the
	 * ?string return type.
	 *
	 * @param string|array|null $value The value read from the path.
	 *
	 * @return string|null The text, or null when the field is missing.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function plainMetadataValue(string|array|null $value): ?string {
		if (is_array($value) === false) {
			return $value;
		}

		return $this->resolveLanguageMap(value: $value) ?? json_encode($value);
	}//end plainMetadataValue()

	/**
	 * Resolve a translatable property's language map to a single string.
	 *
	 * 🔴 WITHOUT THIS, A TRANSLATABLE NAME FIELD IS SHOWN TO USERS AS RAW JSON.
	 *
	 * A property marked `translatable: true` stores `{"nl": "…", "en": "…"}`.
	 * `extractMetadataValue()` must return a string, and it used to satisfy
	 * that by `json_encode`-ing whatever array it was handed. So a schema whose
	 * `objectNameField` names a translatable property stored the literal
	 * `{"nl":"Rijkswaterstaat - Onderhoudscontract software 2026"}` in
	 * `@self.name` — and every detail page renders `@self.name` as its
	 * headline. Observed on pipelinq's lead page; `lead.title` is
	 * `translatable: true` and is the `objectNameField`, and dossiq's
	 * `case.title` is not translatable, which is exactly why its header reads
	 * clean on the same component.
	 *
	 * WHICH LANGUAGE THIS PICKS, and why it is not a locale decision.
	 * `@self.name` is a DENORMALISED display and search string written once at
	 * save time, when there is no reader and so no locale to honour — under
	 * `occ` there is not even a request. The authoritative value stays in the
	 * property, and per-request resolution belongs to
	 * {@see \OCA\OpenRegister\Service\Object\TranslationHandler::resolveTranslationsForRender()},
	 * which reads the register's language list and the caller's Accept-Language.
	 * So this deliberately bakes in NO language: it takes the FIRST key the
	 * author declared, which is exact for the single-language maps that are
	 * the common case and a defensible source-language convention otherwise.
	 *
	 * Returns null for an array that is not a language map, so a genuinely
	 * structured value still falls through to `json_encode` as before.
	 *
	 * @param array $value The candidate value.
	 *
	 * @return string|null The resolved string, or null when this is not a language map.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveLanguageMap(array $value): ?string {
		if ($this->isLanguageKeyedObject(value: $value) === false) {
			return null;
		}

		return (new LanguageMapText(value: $value))->firstValue();
	}//end resolveLanguageMap()

	/**
	 * Whether every key of an array is a BCP 47 language tag.
	 *
	 * Shares LanguageKeyedMap (through LanguageMapText) with TranslationHandler,
	 * which cannot be injected here: it is request-scoped through
	 * LanguageService, and this runs on the save path, including from `occ`
	 * where there is no request at all.
	 *
	 * @param array $value The candidate value.
	 *
	 * @return bool True when the array is keyed by language tags.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isLanguageKeyedObject(array $value): bool {
		return (new LanguageMapText(value: $value))->isLanguageKeyed();
	}//end isLanguageKeyedObject()

	/**
	 * Processes a pipe-separated fallback chain and returns the first non-empty value.
	 *
	 * This method parses strings like "name | ggm_naam | identifier" and tries each
	 * field in order, returning the first one that has a non-empty value.
	 *
	 * @param array $data The object data.
	 * @param string $fieldChain The pipe-separated field chain (e.g., "name | ggm_naam | identifier").
	 * @param array $schemaProperties Optional schema properties for relation field detection.
	 *
	 * @return string|null The first non-empty value found, or null if none found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function processFieldWithFallbacks(array $data, string $fieldChain, array $schemaProperties = []): ?string {
		$source = new TemplateSource(data: $data, schemaProperties: $schemaProperties);
		foreach (array_map('trim', explode('|', $fieldChain)) as $field) {
			$value = $this->chainValue(field: $field, source: $source);
			if ($value !== null) {
				return $value;
			}
		}

		return null;
	}//end processFieldWithFallbacks()

	/**
	 * One link of a fallback chain: the trimmed value of a filled field, with a
	 * relation resolved to the related object's name.
	 *
	 * @param string $field The trimmed field path.
	 * @param TemplateSource $source The object data and schema properties.
	 *
	 * @return string|null The value, or null when the field is empty.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function chainValue(string $field, TemplateSource $source): ?string {
		$value = $this->filledPathValue(field: $field, data: $source->data);
		if ($value === null) {
			return null;
		}

		$resolved = $this->resolveRelationValue(
			field: new FieldValue(name: $field, value: $value),
			schemaProperties: $source->schemaProperties
		);
		if ($resolved !== null && trim($resolved) !== '') {
			return trim($resolved);
		}

		return trim((string)$value);
	}//end chainValue()

	/**
	 * The value at a field path when it is filled.
	 *
	 * @param string $field The field path; '' is skipped.
	 * @param array $data The object data.
	 *
	 * @return mixed The value, or null when the path is '' or the value is empty.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function filledPathValue(string $field, array $data): mixed {
		if ($field === '') {
			return null;
		}

		$value = $this->getValueFromPath(data: $data, path: $field);
		if ($value === null || trim((string)$value) === '') {
			return null;
		}

		return $value;
	}//end filledPathValue()

	/**
	 * Processes twig-like templates by extracting field values and concatenating them.
	 *
	 * This method parses templates like "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}"
	 * and replaces each {{ fieldName }} with the corresponding value from the data.
	 * Empty or null values are handled gracefully and excess whitespace is cleaned up.
	 *
	 * Supports fallback syntax within {{ }} blocks:
	 * - "{{ name | ggm_naam | identifier }}" tries each field until one has a value
	 * - "{{ name | ggm_naam }} - {{ type }}" combines fallbacks with concatenation
	 *
	 * Supports map filter syntax within {{ }} blocks:
	 * - "{{ field | map: key1=val1, key2=val2 }}" looks up the field value in the map
	 * - Falls back to the raw field value if no mapping matches
	 *
	 * @param array $data The object data.
	 * @param string $template The twig-like template string.
	 * @param array $schemaProperties Optional schema properties for relation field detection.
	 *
	 * @return null|string The processed result or null if no values found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function processTwigLikeTemplate(array $data, string $template, array $schemaProperties = []): ?string {
		preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $template, $matches);
		if (empty($matches[0]) === true) {
			return null;
		}

		$source = new TemplateSource(data: $data, schemaProperties: $schemaProperties);
		$result = $template;
		$hasValues = false;
		foreach ($matches[0] as $index => $fullMatch) {
			$value = $this->templateValue(expression: trim($matches[1][$index]), source: $source);
			$result = str_replace($fullMatch, $value, $result);
			$hasValues = ($hasValues === true || $value !== '');
		}

		return $this->finishTemplate(result: $result, hasValues: $hasValues);
	}//end processTwigLikeTemplate()

	/**
	 * A rendered template with excess whitespace collapsed, or null when no
	 * placeholder had a value or nothing is left.
	 *
	 * @param string $result The template with its placeholders replaced.
	 * @param bool $hasValues Whether any placeholder rendered a value.
	 *
	 * @return string|null The cleaned text.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function finishTemplate(string $result, bool $hasValues): ?string {
		$result = trim(preg_replace('/\s+/', ' ', $result));
		if ($hasValues === false || $result === '') {
			return null;
		}

		return $result;
	}//end finishTemplate()

	/**
	 * The trimmed text one placeholder renders to, or '' when it has no value.
	 *
	 * @param string $expression The placeholder expression, without braces
	 * @param TemplateSource $source The object data and schema properties
	 *
	 * @return string The text for the placeholder
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function templateValue(string $expression, TemplateSource $source): string {
		$value = $this->filteredTemplateValue(expression: $expression, source: $source);
		if ($value !== null && trim($value) !== '') {
			return trim($value);
		}

		return '';
	}//end templateValue()

	/**
	 * The value of one placeholder: "field | ifFilled: valIfFilled, valIfEmpty",
	 * "field | map: key1=val1, key2=val2", "a | b | c" (first filled field), or a
	 * plain field path, with a relation resolved to the related object's name.
	 *
	 * The plain path is always resolved first, also when a filter replaces it.
	 *
	 * @param string $expression The placeholder expression, without braces
	 * @param TemplateSource $source The object data and schema properties
	 *
	 * @return string|null The value
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function filteredTemplateValue(string $expression, TemplateSource $source): ?string {
		$value = $this->plainTemplateValue(expression: $expression, source: $source);
		if (preg_match('/^(.+?)\|\s*ifFilled\s*:\s*(.+)$/s', $expression, $match) === 1) {
			return $this->processIfFilledFilter(data: $source->data, fieldName: trim($match[1]), definition: trim($match[2]));
		}

		if (preg_match('/^(.+?)\|\s*map\s*:\s*(.+)$/s', $expression, $match) === 1) {
			return $this->processMapFilter(data: $source->data, fieldName: trim($match[1]), mapDefinition: trim($match[2]));
		}

		if (str_contains($expression, '|') === true) {
			return $this->processFieldWithFallbacks(data: $source->data, fieldChain: $expression, schemaProperties: $source->schemaProperties);
		}

		return $value;
	}//end filteredTemplateValue()

	/**
	 * The value at a placeholder's plain path, with a relation resolved to the
	 * related object's name.
	 *
	 * @param string $expression The placeholder expression, read as a field path
	 * @param TemplateSource $source The object data and schema properties
	 *
	 * @return string|null The value
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function plainTemplateValue(string $expression, TemplateSource $source): ?string {
		return $this->resolveRelationValue(
			field: new FieldValue(name: $expression, value: $this->getValueFromPath(data: $source->data, path: $expression)),
			schemaProperties: $source->schemaProperties
		);
	}//end plainTemplateValue()

	/**
	 * Processes a map filter expression by looking up a field value in a key-value map.
	 *
	 * Parses map definitions like "key1=val1, key2=val2" and returns the mapped value
	 * for the field's current value. Falls back to the raw field value if no mapping matches.
	 *
	 * Example: field "richting" has value "AnaarB", map is "AnaarB=→, BnaarA=←, bi-directioneel=↔"
	 * Result: "→"
	 *
	 * @param array $data The object data.
	 * @param string $fieldName The field name to look up.
	 * @param string $mapDefinition The map definition string (e.g., "key1=val1, key2=val2").
	 *
	 * @return string|null The mapped value, the raw field value as fallback, or null if field is empty.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function processMapFilter(array $data, string $fieldName, string $mapDefinition): ?string {
		$fieldValue = $this->getValueFromPath(data: $data, path: $fieldName);
		$map = new MapFilterDefinition(definition: $mapDefinition);

		// When the field is empty, default to the first mapped value.
		if ($fieldValue === null || trim((string)$fieldValue) === '') {
			return $map->firstValue();
		}

		// Return the mapped value or fall back to the raw field value.
		return $map->lookup(value: trim((string)$fieldValue));
	}//end processMapFilter()

	/**
	 * Processes an ifFilled filter expression that returns one value when a field is filled
	 * and another when it is empty.
	 *
	 * Example: field "buitengemeentelijkVoorziening" is filled, definition is "extern, intern"
	 * Result: "extern" (because field has a value)
	 *
	 * Example: field "buitengemeentelijkVoorziening" is null, definition is "extern, intern"
	 * Result: "intern" (because field is empty)
	 *
	 * @param array $data The object data.
	 * @param string $fieldName The field name to check.
	 * @param string $definition The comma-separated pair "valueIfFilled, valueIfEmpty".
	 *
	 * @return string|null The selected value based on whether the field is filled or empty.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function processIfFilledFilter(array $data, string $fieldName, string $definition): ?string {
		$parts = array_map('trim', explode(',', $definition, 2));
		if (count($parts) < 2) {
			return $parts[0] ?? null;
		}

		// $parts holds "valueIfFilled, valueIfEmpty".
		if ($this->isFilled(value: $this->getValueFromPath(data: $data, path: $fieldName)) === true) {
			return $parts[0];
		}

		return $parts[1];
	}//end processIfFilledFilter()

	/**
	 * Whether a field value is meaningful: not null, not a blank string and
	 * not an empty array.
	 *
	 * @param mixed $value The field value.
	 *
	 * @return bool True when the value is filled.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isFilled(mixed $value): bool {
		return $value !== null
			&& (is_string($value) === false || trim($value) !== '')
			&& (is_array($value) === false || empty($value) === false);
	}//end isFilled()

	/**
	 * Resolve a relation field value (UUID) to an object name.
	 *
	 * Checks if the field is a relation property (has $ref or format: uuid) in the schema,
	 * extracts the UUID from the value (which may be a string, array, or object), and
	 * resolves it to the referenced object's name via CacheHandler.
	 *
	 * @param FieldValue $field The field name in the schema and its raw value.
	 * @param array $schemaProperties The schema properties for relation detection.
	 *
	 * @return string|null The resolved name, or the original string value if not a relation.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveRelationValue(FieldValue $field, array $schemaProperties): ?string {
		$uuid = $this->relationUuid(field: $field, schemaProperties: $schemaProperties);
		if ($uuid === null) {
			return $this->stringOrNull(value: $field->value);
		}

		return $this->objectName(uuid: $uuid, fieldName: $field->name);
	}//end resolveRelationValue()

	/**
	 * The UUID a relation field refers to.
	 *
	 * @param FieldValue $field The field name in the schema and its raw value.
	 * @param array $schemaProperties The schema properties for relation detection.
	 *
	 * @return string|null The UUID, or null when the value is null, the field is
	 *                     not a relation property, or no UUID is found in the value.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function relationUuid(FieldValue $field, array $schemaProperties): ?string {
		if ($field->value === null) {
			return null;
		}

		$property = $schemaProperties[$field->name] ?? null;
		if ($property === null) {
			return null;
		}

		return (new RelationPropertyShape(property: $property))->uuidIn(value: $field->value);
	}//end relationUuid()

	/**
	 * The name of the object a UUID refers to, via CacheHandler.
	 *
	 * @param string $uuid The UUID.
	 * @param string $fieldName The relation field, for the log.
	 *
	 * @return string The name, or the UUID itself when name resolution fails.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function objectName(string $uuid, string $fieldName): string {
		try {
			$names = $this->cacheHandler->getMultipleObjectNames([$uuid]);
			if (empty($names[$uuid]) === false) {
				return $names[$uuid];
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MetadataHydrationHandler] Failed to resolve UUID to name',
				context: ['file' => __FILE__, 'line' => __LINE__, 'field' => $fieldName, 'uuid' => $uuid, 'error' => $e->getMessage()]
			);
		}

		return $uuid;
	}//end objectName()

	/**
	 * A value when it is a string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The value, or null when it is not a string.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true) {
			return $value;
		}

		return null;
	}//end stringOrNull()

	/**
	 * Creates a URL-friendly slug from text.
	 *
	 * Conversion steps:
	 * 1. Convert to lowercase
	 * 2. Replace spaces and underscores with hyphens
	 * 3. Remove special characters
	 * 4. Remove multiple consecutive hyphens
	 * 5. Trim hyphens from start and end
	 *
	 * @param string $text The text to convert to a slug.
	 *
	 * @return string The generated slug.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function createSlug(string $text): string {
		// Lowercase, with spaces and underscores as hyphens.
		$slug = str_replace([' ', '_'], '-', strtolower($text));

		// Remove all characters that are not a-z, 0-9, or hyphen.
		$slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

		// Collapse hyphen runs, then trim hyphens from start and end.
		return trim(preg_replace('/-+/', '-', $slug), '-');
	}//end createSlug()
}//end class
