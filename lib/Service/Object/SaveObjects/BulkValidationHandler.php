<?php
/**
 * OpenRegister BulkValidationHandler
 *
 * Handler for bulk validation operations and schema analysis.
 * Optimizes validation for bulk object operations.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObjects
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

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Bulk Validation Handler
 *
 * Handles validation optimization for bulk operations including:
 * - Comprehensive schema analysis for optimization.
 * - Boolean property detection and casting.
 * - Pre-validation cascading for bulk objects.
 * - Field type analysis for bulk processing.
 *
 * OPTIMIZATION FEATURES:
 * - Schema analysis caching.
 * - Property type detection.
 * - Relation detection for bulk handling.
 * - Default value detection.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObjects
 */
class BulkValidationHandler
{
    /**
     * Constructor for BulkValidationHandler.
     *
     * @param LoggerInterface $logger Logger interface for logging operations.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Performs comprehensive schema analysis for bulk optimization.
     *
     * Analyzes schema to detect:
     * - Metadata field mappings (name, description, summary, image, slug).
     * - Inverse relation properties (inversedBy, writeBack).
     * - Validation requirements (hardValidation setting).
     *
     * This analysis is cached and reused for all objects in a bulk operation,
     * providing significant performance optimization.
     *
     * @param Schema $schema The schema to analyze.
     *
     * @return (((bool|mixed)[]|mixed)[]|bool|null)[] Analysis results with: - metadataFields: Metadata field mappings. - inverseProperties: Inverse relation configurations. - validationRequired: Whether hard validation is enabled. - properties: Schema properties array. - configuration: Schema configuration array.
     *
     * @psalm-return array{metadataFields: array<string, mixed>, inverseProperties: array<array{inversedBy: mixed, writeBack: bool, isArray: bool}>, validationRequired: bool, properties: array|null, configuration: array|null}
     */
    public function performComprehensiveSchemaAnalysis(Schema $schema): array
    {
        $config     = $schema->getConfiguration();
        $properties = $schema->getProperties();

        $analysis = [
            'metadataFields'     => [],
            'inverseProperties'  => [],
            'validationRequired' => $schema->getHardValidation(),
            'properties'         => $properties,
            'configuration'      => $config,
        ];

        // PERFORMANCE OPTIMIZATION: Analyze metadata field mappings once.
        // COMPREHENSIVE METADATA FIELD SUPPORT: Include all supported metadata fields.
        $metadataFieldMap = [
            'name'        => $config['objectNameField'] ?? null,
            'description' => $config['objectDescriptionField'] ?? null,
            'summary'     => $config['objectSummaryField'] ?? null,
            'image'       => $config['objectImageField'] ?? null,
            'slug'        => $config['objectSlugField'] ?? null,
        ];

        $analysis['metadataFields'] = array_filter(
            $metadataFieldMap,
            function ($field) {
                return empty($field) === false;
            }
        );

        // PERFORMANCE OPTIMIZATION: Analyze inverse relation properties once.
        foreach ($properties ?? [] as $propertyName => $propertyConfig) {
            $items = $propertyConfig['items'] ?? [];

            // Check for inversedBy at property level (single object relations).
            $inversedBy   = $propertyConfig['inversedBy'] ?? null;
            $rawWriteBack = $propertyConfig['writeBack'] ?? false;
            $writeBack    = $this->castToBoolean($rawWriteBack);

            // Schema analysis: process writeBack boolean casting.
            // Check for inversedBy in array items (array of object relations).
            // CRITICAL FIX: Preserve property-level writeBack if it's true.
            if (($inversedBy === false || $inversedBy === null) === true && (($items['inversedBy'] ?? null) !== null) === true) {
                $inversedBy        = $items['inversedBy'];
                $rawItemsWriteBack = $items['writeBack'] ?? false;
                $itemsWriteBack    = $this->castToBoolean($rawItemsWriteBack);

                // Use the higher value: if property writeBack is true, keep it.
                $finalWriteBack = $writeBack || $itemsWriteBack;

                // Items logic: combine property and items writeBack values.
                $writeBack = $finalWriteBack;
            }

            if ($inversedBy !== null && $inversedBy !== '') {
                $analysis['inverseProperties'][$propertyName] = [
                    'inversedBy' => $inversedBy,
                    'writeBack'  => $writeBack,
                    'isArray'    => $propertyConfig['type'] === 'array',
                ];
            }
        }//end foreach

        return $analysis;

    }//end performComprehensiveSchemaAnalysis()

    /**
     * Cast mixed values to proper boolean.
     *
     * Handles various truthy/falsy representations:
     * - String "true"/"false" (case-insensitive).
     * - Integers 1/0.
     * - Actual booleans.
     * - Other values cast to bool.
     *
     * @param mixed $value The value to cast to boolean.
     *
     * @return bool The boolean value.
     */
    public function castToBoolean($value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_string($value) === true) {
            return strtolower(trim($value)) === 'true';
        }

        if (is_numeric($value) === true) {
            return (bool) $value;
        }

        return (bool) $value;

    }//end castToBoolean()

    /**
     * Handles pre-validation cascading for a single object in bulk context.
     *
     * SIMPLIFIED: For bulk operations, we skip complex cascading for now
     * and handle it later in individual object processing if needed.
     *
     * @param array       $object The object data.
     * @param null|string $uuid   The object UUID (for updates).
     *
     * @return (array|string)[] Array with [object, uuid].
     *
     * @psalm-return list{array, string}
     */
    public function handlePreValidationCascading(array $object, ?string $uuid): array
    {
        // SIMPLIFIED: For bulk operations, we skip complex cascading for now.
        // and handle it later in individual object processing if needed.
        if ($uuid === null) {
            $uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        }

        return [$object, $uuid];

    }//end handlePreValidationCascading()
}//end class
