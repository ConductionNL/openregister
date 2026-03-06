<?php

/**
 * OpenRegister MetadataHydrationHandler
 *
 * Handler for extracting and hydrating object metadata.
 * Handles name, description, summary, image extraction, and slug generation.
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
use OCA\OpenRegister\Service\Object\CacheHandler;
use Psr\Log\LoggerInterface;

/**
 * Metadata Hydration Handler
 *
 * Handles object metadata extraction and hydration including:
 * - Name extraction from configured field paths
 * - Description and summary extraction
 * - Slug generation from configured sources
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
 */
class MetadataHydrationHandler
{
    /**
     * Constructor for MetadataHydrationHandler.
     *
     * @param LoggerInterface $logger       Logger interface for logging operations.
     * @param CacheHandler    $cacheHandler Cache handler for UUID-to-name resolution.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CacheHandler $cacheHandler,
    ) {
    }//end __construct()

    /**
     * Hydrates simple object metadata from schema configuration.
     *
     * This method extracts simple metadata fields (name, description, summary, slug)
     * from the object data based on schema configuration.
     *
     * NOTE: Image field handling is kept in SaveObject due to complex file operations.
     * NOTE: Published/Depublished field handling is kept in SaveObject due to DateTime complexity.
     *
     * Metadata can be configured in schema using:
     * - Direct field paths: "title", "description"
     * - Nested paths: "contact.name", "profile.bio"
     * - Fallback chains: "name | ggm_naam | identifier" (tries each until one has value)
     * - Twig templates: "{{ firstName }} {{ lastName }}"
     * - Twig with fallbacks: "{{ name | ggm_naam }} ({{ type }})"
     *
     * @param ObjectEntity $entity The object entity to hydrate.
     * @param Schema       $schema The schema containing metadata configuration.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function hydrateObjectMetadata(ObjectEntity $entity, Schema $schema): void
    {
        $config     = $schema->getConfiguration() ?? [];
        $objectData = $entity->getObject();

        // CRITICAL FIX: Extract business data from correct location.
        // If object data has 'object' key (structured format), use that for property access.
        // Otherwise use the objectData directly (flat format).
        $businessData = $objectData['object'] ?? $objectData;

        // Get schema properties for relation field detection.
        $schemaProperties = $schema->getProperties() ?? [];

        // Name field mapping - use configured field or fallback to common names.
        $nameField = $config['objectNameField'] ?? null;
        $name      = null;

        if ($nameField !== null) {
            $name = $this->extractMetadataValue(data: $businessData, fieldPath: $nameField, schemaProperties: $schemaProperties);
        }

        // Fallback: try common name fields if not configured or configured field is empty.
        if ($name === null || trim($name) === '') {
            $name = $this->tryCommonFields(data: $businessData, fieldNames: ['naam', 'name', 'title', 'label', 'titel']);
        }

        if ($name !== null && trim($name) !== '') {
            $entity->setName(trim($name));
        }

        // Description field mapping - use configured field or fallback.
        $descField   = $config['objectDescriptionField'] ?? null;
        $description = null;

        if ($descField !== null) {
            $description = $this->extractMetadataValue(data: $businessData, fieldPath: $descField);
        }

        // Fallback: try common description fields.
        if ($description === null || trim($description) === '') {
            $description = $this->tryCommonFields(
                data: $businessData,
                fieldNames: ['beschrijvingLang', 'description', 'beschrijving', 'omschrijving']
            );
        }

        if ($description !== null && trim($description) !== '') {
            $entity->setDescription(trim($description));
        }

        // Summary field mapping - use configured field or fallback.
        $summaryField = $config['objectSummaryField'] ?? null;
        $summary      = null;

        if ($summaryField !== null) {
            $summary = $this->extractMetadataValue(data: $businessData, fieldPath: $summaryField);
        }

        // Fallback: try common summary fields.
        if ($summary === null || trim($summary) === '') {
            $summary = $this->tryCommonFields(
                data: $businessData,
                fieldNames: ['beschrijvingKort', 'summary', 'samenvatting', 'shortDescription']
            );
        }

        if ($summary !== null && trim($summary) !== '') {
            $entity->setSummary(trim($summary));
        }

        // Slug field mapping.
        if (($config['objectSlugField'] ?? null) !== null) {
            $slug = $this->extractMetadataValue(data: $businessData, fieldPath: $config['objectSlugField']);
            if ($slug !== null && trim($slug) !== '') {
                // Generate URL-friendly slug.
                $generatedSlug = $this->createSlugFromValue(value: trim($slug));
                if ($generatedSlug !== null) {
                    $entity->setSlug($generatedSlug);
                }
            }
        }
    }//end hydrateObjectMetadata()

    /**
     * Try to extract a value from common field names.
     *
     * @param array $data       The object data.
     * @param array $fieldNames Array of field names to try in order of preference.
     *
     * @return string|null The first non-empty value found, or null.
     */
    private function tryCommonFields(array $data, array $fieldNames): ?string
    {
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
     * @param array  $data The object data.
     * @param string $path The dot notation path (e.g., 'name', 'contact.email', 'address.street').
     *
     * @return mixed The value at the path, or null if not found.
     */
    public function getValueFromPath(array $data, string $path)
    {
        $keys    = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_array($current) === false || array_key_exists($key, $current) === false) {
                return null;
            }

            $current = $current[$key];
        }

        // Return raw value — callers handle type conversion.
        // Arrays/objects are kept as-is so relation fields can extract UUIDs.
        if ($current !== null && is_string($current) === false && is_array($current) === false) {
            $current = (string) $current;
        }

        return $current;
    }//end getValueFromPath()

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
     * @param array  $data             The object data.
     * @param string $fieldPath        The field path, fallback chain, or twig-like template.
     * @param array  $schemaProperties Optional schema properties for relation field detection.
     *
     * @return string|null The extracted/concatenated value, or null if not found.
     */
    public function extractMetadataValue(array $data, string $fieldPath, array $schemaProperties=[]): ?string
    {
        // Check if this is a twig-like template with {{ }} syntax.
        if (str_contains($fieldPath, '{{') === true && str_contains($fieldPath, '}}') === true) {
            return $this->processTwigLikeTemplate(data: $data, template: $fieldPath, schemaProperties: $schemaProperties);
        }

        // Check if this is a pipe-separated fallback chain (without {{ }} syntax).
        if (str_contains($fieldPath, '|') === true) {
            return $this->processFieldWithFallbacks(data: $data, fieldChain: $fieldPath);
        }

        // Simple field path - use existing method.
        return $this->getValueFromPath(data: $data, path: $fieldPath);
    }//end extractMetadataValue()

    /**
     * Processes a pipe-separated fallback chain and returns the first non-empty value.
     *
     * This method parses strings like "name | ggm_naam | identifier" and tries each
     * field in order, returning the first one that has a non-empty value.
     *
     * @param array  $data             The object data.
     * @param string $fieldChain       The pipe-separated field chain (e.g., "name | ggm_naam | identifier").
     * @param array  $schemaProperties Optional schema properties for relation field detection.
     *
     * @return string|null The first non-empty value found, or null if none found.
     */
    public function processFieldWithFallbacks(array $data, string $fieldChain, array $schemaProperties=[]): ?string
    {
        // Split by pipe and trim each field name.
        $fields = array_map('trim', explode('|', $fieldChain));

        foreach ($fields as $field) {
            if ($field === '') {
                continue;
            }

            $value = $this->getValueFromPath(data: $data, path: $field);

            if ($value !== null && trim((string) $value) !== '') {
                // Resolve UUID to object name for relation fields.
                $resolved = $this->resolveRelationValue(
                    fieldName: $field,
                    value: $value,
                    schemaProperties: $schemaProperties
                );

                if ($resolved !== null && trim($resolved) !== '') {
                    return trim($resolved);
                }

                return trim((string) $value);
            }
        }//end foreach

        return null;
    }//end processFieldWithFallbacks()

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
     * @param array  $data             The object data.
     * @param string $template         The twig-like template string.
     * @param array  $schemaProperties Optional schema properties for relation field detection.
     *
     * @return null|string The processed result or null if no values found.
     */
    public function processTwigLikeTemplate(array $data, string $template, array $schemaProperties=[]): string|null
    {
        // Extract all {{ fieldName }} patterns.
        preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $template, $matches);

        if (empty($matches[0]) === true) {
            return null;
        }

        $result    = $template;
        $hasValues = false;

        // Replace each {{ fieldName }} with its value.
        foreach ($matches[0] as $index => $fullMatch) {
            $fieldExpression = trim($matches[1][$index]);

            // Check if this expression uses the ifFilled filter: "field | ifFilled: valIfFilled, valIfEmpty".
            if (preg_match('/^(.+?)\|\s*ifFilled\s*:\s*(.+)$/s', $fieldExpression, $ifFilledMatch) === 1) {
                $value = $this->processIfFilledFilter(data: $data, fieldName: trim($ifFilledMatch[1]), definition: trim($ifFilledMatch[2]));
            } else if (preg_match('/^(.+?)\|\s*map\s*:\s*(.+)$/s', $fieldExpression, $mapMatch) === 1) {
                // Check if this expression uses the map filter syntax: "field | map: key1=val1, key2=val2".
                $value = $this->processMapFilter(data: $data, fieldName: trim($mapMatch[1]), mapDefinition: trim($mapMatch[2]));
            } else if (str_contains($fieldExpression, '|') === true) {
                // Pipe without "map:" means fallback syntax.
                $value = $this->processFieldWithFallbacks(data: $data, fieldChain: $fieldExpression, schemaProperties: $schemaProperties);
            } else {
                $value = $this->getValueFromPath(data: $data, path: $fieldExpression);

                // Resolve UUID to object name for relation fields.
                $value = $this->resolveRelationValue(
                    fieldName: $fieldExpression,
                    value: $value,
                    schemaProperties: $schemaProperties
                );
            }

            // Convert arrays to string representation if still an array at this point.
            if (is_array($value) === true) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($value !== null && is_string($value) === true && trim($value) !== '') {
                $result    = str_replace($fullMatch, trim($value), $result);
                $hasValues = true;
                continue;
            }

            // Replace with empty string for missing/empty values.
            $result = str_replace($fullMatch, '', $result);
        }//end foreach

        if ($hasValues === false) {
            return null;
        }

        // Clean up excess whitespace and normalize spaces.
        $result = preg_replace('/\s+/', ' ', $result);
        $result = trim($result);

        if ($result !== '') {
            return $result;
        }

        return null;
    }//end processTwigLikeTemplate()

    /**
     * Processes a map filter expression by looking up a field value in a key-value map.
     *
     * Parses map definitions like "key1=val1, key2=val2" and returns the mapped value
     * for the field's current value. Falls back to the raw field value if no mapping matches.
     *
     * Example: field "richting" has value "AnaarB", map is "AnaarB=→, BnaarA=←, bi-directioneel=↔"
     * Result: "→"
     *
     * @param array  $data          The object data.
     * @param string $fieldName     The field name to look up.
     * @param string $mapDefinition The map definition string (e.g., "key1=val1, key2=val2").
     *
     * @return string|null The mapped value, the raw field value as fallback, or null if field is empty.
     */
    public function processMapFilter(array $data, string $fieldName, string $mapDefinition): ?string
    {
        $fieldValue = $this->getValueFromPath(data: $data, path: $fieldName);

        // Parse the map definition: "key1=val1, key2=val2".
        $mappings = array_map('trim', explode(',', $mapDefinition));
        $map      = [];

        foreach ($mappings as $mapping) {
            $parts = explode('=', $mapping, 2);
            if (count($parts) === 2) {
                $map[trim($parts[0])] = trim($parts[1]);
            }
        }

        // When the field is empty, default to the first mapped value.
        if ($fieldValue === null || trim((string) $fieldValue) === '') {
            $firstValue = reset($map);
            if ($firstValue !== false) {
                return $firstValue;
            }

            return null;
        }

        $fieldValue = trim((string) $fieldValue);

        // Return the mapped value or fall back to the raw field value.
        return $map[$fieldValue] ?? $fieldValue;
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
     * @param array  $data       The object data.
     * @param string $fieldName  The field name to check.
     * @param string $definition The comma-separated pair "valueIfFilled, valueIfEmpty".
     *
     * @return string|null The selected value based on whether the field is filled or empty.
     */
    public function processIfFilledFilter(array $data, string $fieldName, string $definition): ?string
    {
        $parts = array_map('trim', explode(',', $definition, 2));
        if (count($parts) < 2) {
            return $parts[0] ?? null;
        }

        $valueIfFilled = $parts[0];
        $valueIfEmpty  = $parts[1];

        $fieldValue = $this->getValueFromPath(data: $data, path: $fieldName);

        // Check if the field has a meaningful value.
        if ($fieldValue !== null
            && (is_string($fieldValue) === false || trim($fieldValue) !== '')
            && (is_array($fieldValue) === false || empty($fieldValue) === false)
        ) {
            return $valueIfFilled;
        }

        return $valueIfEmpty;
    }//end processIfFilledFilter()

    /**
     * Resolve a relation field value (UUID) to an object name.
     *
     * Checks if the field is a relation property (has $ref or format: uuid) in the schema,
     * extracts the UUID from the value (which may be a string, array, or object), and
     * resolves it to the referenced object's name via CacheHandler.
     *
     * @param string $fieldName        The field name in the schema.
     * @param mixed  $value            The raw field value (UUID string, array, or null).
     * @param array  $schemaProperties The schema properties for relation detection.
     *
     * @return string|null The resolved name, or the original value if not a relation.
     */
    private function resolveRelationValue(string $fieldName, mixed $value, array $schemaProperties): ?string
    {
        if ($value === null || empty($schemaProperties) === true) {
            if (is_string($value) === true) {
                return $value;
            }

            return null;
        }

        // Check if the field is a relation property in the schema.
        $property = $schemaProperties[$fieldName] ?? null;
        if ($property === null || $this->isRelationProperty(property: $property) === false) {
            if (is_string($value) === true) {
                return $value;
            }

            return null;
        }

        // Extract UUID from the value.
        $uuid = $this->extractUuidFromValue(value: $value);
        if ($uuid === null) {
            if (is_string($value) === true) {
                return $value;
            }

            return null;
        }

        // Resolve UUID to object name via CacheHandler.
        try {
            $names = $this->cacheHandler->getMultipleObjectNames([$uuid]);
            if (empty($names[$uuid]) === false) {
                return $names[$uuid];
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MetadataHydrationHandler] Failed to resolve UUID to name',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'field' => $fieldName,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
        }

        // Fall back to the extracted UUID when name resolution fails.
        return $uuid;
    }//end resolveRelationValue()

    /**
     * Check if a schema property is a relation (references another object).
     *
     * @param array $property The schema property definition.
     *
     * @return bool True if the property is a relation.
     */
    private function isRelationProperty(array $property): bool
    {
        // Has $ref pointing to another schema.
        if (empty($property['$ref']) === false) {
            return true;
        }

        // Has format: uuid.
        if (($property['format'] ?? '') === 'uuid') {
            return true;
        }

        // Array items with $ref or format: uuid.
        if (($property['type'] ?? '') === 'array' && isset($property['items']) === true) {
            $items = $property['items'];
            if (empty($items['$ref']) === false || ($items['format'] ?? '') === 'uuid') {
                return true;
            }
        }

        // Object type with $ref.
        if (($property['type'] ?? '') === 'object' && empty($property['$ref']) === false) {
            return true;
        }

        // Object type with items containing oneOf with $ref.
        if (($property['type'] ?? '') === 'object' && isset($property['items']['oneOf']) === true) {
            foreach ($property['items']['oneOf'] as $option) {
                if (empty($option['$ref']) === false) {
                    return true;
                }
            }
        }

        return false;
    }//end isRelationProperty()

    /**
     * Extract a UUID string from a value that may be a string, array, or object.
     *
     * Handles various formats:
     * - Plain UUID string: "debe49c0-e770-5cd7-9003-e8f34274cc2a"
     * - Object with value key: {"value": "uuid-here"}
     * - Object with id key: {"id": "uuid-here"}
     * - Object with uuid key: {"uuid": "uuid-here"}
     *
     * @param mixed $value The value to extract a UUID from.
     *
     * @return string|null The extracted UUID or null.
     */
    private function extractUuidFromValue(mixed $value): ?string
    {
        if (is_string($value) === true && empty($value) === false) {
            return $value;
        }

        if (is_array($value) === true) {
            // Try common keys that hold UUID references.
            foreach (['value', 'id', 'uuid', '@self.id'] as $key) {
                if (isset($value[$key]) === true && is_string($value[$key]) === true) {
                    return $value[$key];
                }
            }
        }

        return null;
    }//end extractUuidFromValue()

    /**
     * Creates a URL-friendly slug from a metadata value.
     *
     * This method is different from the generateSlug method as it works with
     * already extracted metadata values rather than generating defaults.
     * It creates a slug without adding timestamps to avoid conflicts with schema-based slugs.
     *
     * @param string $value The value to convert to a slug.
     *
     * @return string|null The generated slug or null if value is empty.
     */
    public function createSlugFromValue(string $value): ?string
    {
        if (empty($value) === true || trim($value) === '') {
            return null;
        }

        // Use the existing createSlug method for consistency.
        return $this->createSlug(name: trim($value));
    }//end createSlugFromValue()

    /**
     * Generates a slug for an object based on schema configuration.
     *
     * The slug generation follows this priority:
     * 1. Schema's slugFrom configuration (field path)
     * 2. Schema's titleField configuration
     * 3. Object's "name" field
     * 4. Object's "title" field
     * 5. Schema name as fallback
     *
     * @param array  $data   The object data.
     * @param Schema $schema The schema configuration.
     *
     * @return string|null The generated slug or null.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple fallback paths for slug source determination
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple nested conditional paths for evaluating different field options
     */
    public function generateSlug(array $data, Schema $schema): string|null
    {
        $properties = $schema->getProperties();
        $slugSource = null;

        // 1. Check for explicit slugFrom configuration.
        if (isset($properties['_slugFrom']) === true && is_string($properties['_slugFrom']) === true) {
            $slugSource = $this->getValueFromPath(data: $data, path: $properties['_slugFrom']);
        }

        // 2. Check for titleField configuration.
        if ($slugSource === null && isset($properties['_titleField']) === true) {
            $slugSource = $this->getValueFromPath(data: $data, path: $properties['_titleField']);
        }

        // 3. Try common name fields.
        if ($slugSource === null) {
            $commonFields = ['name', 'title', 'label', 'slug'];
            foreach ($commonFields as $field) {
                $value = $this->getValueFromPath(data: $data, path: $field);
                if ($value !== null && is_string($value) === true) {
                    $slugSource = $value;
                    break;
                }
            }
        }

        // 4. Fallback to schema name.
        if ($slugSource === null) {
            $slugSource = $schema->getTitle() ?? $schema->getName();
        }

        // Generate slug from source.
        if (is_string($slugSource) === true && empty($slugSource) === false) {
            return $this->createSlug(text: $slugSource);
        }

        return null;
    }//end generateSlug()

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
     */
    public function createSlug(string $text): string
    {
        // Convert to lowercase.
        $slug = strtolower($text);

        // Replace spaces and underscores with hyphens.
        $slug = str_replace([' ', '_'], '-', $slug);

        // Remove all characters that are not a-z, 0-9, or hyphen.
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

        // Replace multiple consecutive hyphens with single hyphen.
        $slug = preg_replace('/-+/', '-', $slug);

        // Trim hyphens from start and end.
        $slug = trim($slug, '-');

        return $slug;
    }//end createSlug()
}//end class
