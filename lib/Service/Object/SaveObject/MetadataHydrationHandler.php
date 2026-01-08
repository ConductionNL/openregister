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
use Psr\Log\LoggerInterface;

/**
 * Metadata Hydration Handler
 *
 * Handles object metadata extraction and hydration including:
 * - Name extraction from configured field paths
 * - Description and summary extraction
 * - Slug generation from configured sources
 * - Twig-like template processing for metadata (regex-based, not full Twig)
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObject
 */
class MetadataHydrationHandler
{
    /**
     * Constructor for MetadataHydrationHandler.
     *
     * @param LoggerInterface $logger Logger interface for logging operations.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
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
     * - Twig templates: "{{ firstName }} {{ lastName }}"
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

        // Name field mapping - use configured field or fallback to common names.
        $nameField = $config['objectNameField'] ?? null;
        $name      = null;

        if ($nameField !== null) {
            $name = $this->extractMetadataValue(data: $objectData, fieldPath: $nameField);
        }

        // Fallback: try common name fields if not configured or configured field is empty.
        if ($name === null || trim($name) === '') {
            $name = $this->tryCommonFields(data: $objectData, fieldNames: ['naam', 'name', 'title', 'label', 'titel']);
        }

        if ($name !== null && trim($name) !== '') {
            $entity->setName(trim($name));
        }

        // Description field mapping - use configured field or fallback.
        $descField   = $config['objectDescriptionField'] ?? null;
        $description = null;

        if ($descField !== null) {
            $description = $this->extractMetadataValue(data: $objectData, fieldPath: $descField);
        }

        // Fallback: try common description fields.
        if ($description === null || trim($description) === '') {
            $description = $this->tryCommonFields(
                data: $objectData,
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
            $summary = $this->extractMetadataValue(data: $objectData, fieldPath: $summaryField);
        }

        // Fallback: try common summary fields.
        if ($summary === null || trim($summary) === '') {
            $summary = $this->tryCommonFields(
                data: $objectData,
                fieldNames: ['beschrijvingKort', 'summary', 'samenvatting', 'shortDescription']
            );
        }

        if ($summary !== null && trim($summary) !== '') {
            $entity->setSummary(trim($summary));
        }

        // Slug field mapping.
        if (($config['objectSlugField'] ?? null) !== null) {
            $slug = $this->extractMetadataValue(data: $objectData, fieldPath: $config['objectSlugField']);
            if ($slug !== null && trim($slug) !== '') {
                // Generate URL-friendly slug.
                $generatedSlug = $this->createSlugFromValue(trim($slug));
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

        // Convert to string if it's not null and not already a string.
        if ($current !== null && is_string($current) === false) {
            $current = (string) $current;
        }

        return $current;
    }//end getValueFromPath()

    /**
     * Extracts metadata value from object data with support for twig-like concatenation.
     *
     * This method supports two formats:
     * 1. Simple dot notation paths: "naam", "contact.email"
     * 2. Twig-like templates: "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}"
     *
     * For twig-like templates, it extracts field names from {{ }} syntax and concatenates
     * their values with spaces, handling empty/null values gracefully.
     *
     * @param array  $data      The object data.
     * @param string $fieldPath The field path or twig-like template.
     *
     * @return string|null The extracted/concatenated value, or null if not found.
     */
    public function extractMetadataValue(array $data, string $fieldPath): ?string
    {
        // Check if this is a twig-like template with {{ }} syntax.
        if (str_contains($fieldPath, '{{') === true && str_contains($fieldPath, '}}') === true) {
            return $this->processTwigLikeTemplate(data: $data, template: $fieldPath);
        }

        // Simple field path - use existing method.
        return $this->getValueFromPath(data: $data, path: $fieldPath);
    }//end extractMetadataValue()

    /**
     * Processes twig-like templates by extracting field values and concatenating them.
     *
     * This method parses templates like "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}"
     * and replaces each {{ fieldName }} with the corresponding value from the data.
     * Empty or null values are handled gracefully and excess whitespace is cleaned up.
     *
     * @param array  $data     The object data.
     * @param string $template The twig-like template string.
     *
     * @return null|string The processed result or null if no values found.
     */
    public function processTwigLikeTemplate(array $data, string $template): string|null
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
            $fieldName = trim($matches[1][$index]);
            $value     = $this->getValueFromPath(data: $data, path: $fieldName);

            if ($value !== null && trim((string) $value) !== '') {
                $result    = str_replace($fullMatch, trim((string) $value), $result);
                $hasValues = true;
                continue;
            }

            // Replace with empty string for missing/empty values.
            $result = str_replace($fullMatch, '', $result);
        }

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
        return $this->createSlug(trim($value));
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
            return $this->createSlug($slugSource);
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
