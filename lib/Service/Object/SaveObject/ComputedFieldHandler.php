<?php

/**
 * OpenRegister ComputedFieldHandler
 *
 * Handler for evaluating computed field expressions using Twig.
 * Supports save-time and read-time evaluation of Twig expressions
 * defined in schema property `computed` attributes.
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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Twig\MappingExtension;
use OCA\OpenRegister\Twig\MappingRuntimeLoader;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Computed Field Handler
 *
 * Evaluates Twig expressions defined in schema property `computed` attributes.
 * Supports two evaluation modes:
 * - `save`: Computed at save time, value stored in database
 * - `read`: Computed at read time, value NOT stored in database
 *
 * Cross-reference lookups are supported via `_ref.propertyName.field` syntax,
 * which resolves referenced objects and extracts their properties.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObject
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Handler requires Twig and mapper dependencies
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class ComputedFieldHandler
{
    /**
     * Maximum depth for cross-reference resolution to prevent circular lookups.
     *
     * @var int
     */
    private const MAX_REF_DEPTH = 3;

    /**
     * Twig environment instance for expression evaluation.
     *
     * @var Environment|null
     */
    private ?Environment $twig = null;

    /**
     * Constructor for ComputedFieldHandler.
     *
     * @param MagicMapper          $objectMapper         Mapper for fetching referenced objects.
     * @param MappingExtension     $mappingExtension     Twig extension with custom filters and functions.
     * @param MappingRuntimeLoader $mappingRuntimeLoader Twig runtime loader for mapping functions.
     * @param LoggerInterface      $logger               Logger for error and debug messages.
     */
    public function __construct(
        private readonly MagicMapper $objectMapper,
        private readonly MappingExtension $mappingExtension,
        private readonly MappingRuntimeLoader $mappingRuntimeLoader,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get or create the sandboxed Twig environment.
     *
     * Creates a Twig environment with:
     * - ArrayLoader for dynamic template strings
     * - MappingExtension for custom filters/functions
     * - SandboxExtension for security (restricts available tags, filters, functions)
     *
     * @return Environment The configured Twig environment
     */
    private function getTwig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }

        $loader     = new ArrayLoader();
        // autoescape:false — Twig's default autoescaping injects the
        // `escape` filter into every `{{ var }}` output, but our
        // sandbox SecurityPolicy doesn't allow that filter (and HTML
        // escape isn't meaningful for computed property values that
        // return strings/numbers/dates back into the object data).
        // Without this flag, every Twig expression silently fails
        // with `Filter "escape" is not allowed`.
        $this->twig = new Environment($loader, ['autoescape' => false]);

        // Add the mapping extension for custom filters and functions.
        $this->twig->addExtension($this->mappingExtension);
        $this->twig->addRuntimeLoader($this->mappingRuntimeLoader);

        // Configure sandbox for security.
        $policy = new SecurityPolicy(
            allowedTags: [],
            allowedFilters: [
                'date',
                'date_modify',
                'upper',
                'lower',
                'trim',
                'length',
                'default',
                'number_format',
                'round',
                'abs',
                'split',
                'join',
                'slice',
                'first',
                'last',
                'replace',
                'format',
                'b64enc',
                'b64dec',
                'json_decode',
                'zgw_enum',
                'zgw_enum_reverse',
                'zgw_extract_uuid',
            ],
            allowedMethods: [],
            allowedProperties: [],
            allowedFunctions: [
                'max',
                'min',
                'range',
                'generateUuid',
            ]
        );

        $sandbox = new SandboxExtension($policy, sandboxed: true);
        $this->twig->addExtension($sandbox);

        return $this->twig;
    }//end getTwig()

    /**
     * Evaluate computed fields for the given evaluation mode.
     *
     * Iterates through schema properties, finds those with a `computed` attribute
     * matching the specified evaluateOn mode, and evaluates their Twig expressions.
     *
     * @param array  $data       The object data (used as Twig context).
     * @param Schema $schema     The schema containing property definitions.
     * @param string $evaluateOn The evaluation mode: 'save' or 'read'.
     *
     * @return array The object data with computed field values added/updated.
     */
    public function evaluateComputedFields(array $data, Schema $schema, string $evaluateOn='save'): array
    {
        $properties = $schema->getProperties() ?? [];

        foreach ($properties as $propertyName => $property) {
            // Skip properties without a computed attribute.
            if (isset($property['computed']) === false || is_array($property['computed']) === false) {
                continue;
            }

            $computed = $property['computed'];

            // Skip if no expression defined.
            if (isset($computed['expression']) === false || empty($computed['expression']) === true) {
                continue;
            }

            // Check evaluateOn mode (default: 'save').
            $propertyEvaluateOn = $computed['evaluateOn'] ?? 'save';
            if ($propertyEvaluateOn !== $evaluateOn) {
                continue;
            }

            // Evaluate the expression.
            $data[$propertyName] = $this->evaluateExpression(
                expression: $computed['expression'],
                data: $data,
                schema: $schema,
                propertyName: $propertyName
            );
        }//end foreach

        return $data;
    }//end evaluateComputedFields()

    /**
     * Evaluate a single Twig expression with the given data context.
     *
     * Handles cross-reference resolution (via _ref prefix) and graceful error handling.
     * On error, returns null and logs a warning.
     *
     * @param string $expression   The Twig expression to evaluate.
     * @param array  $data         The object data as Twig context.
     * @param Schema $schema       The schema for cross-reference resolution.
     * @param string $propertyName The property name (for logging).
     *
     * @return mixed The computed value, or null on error.
     */
    private function evaluateExpression(
        string $expression,
        array $data,
        Schema $schema,
        string $propertyName
    ): mixed {
        try {
            // Build the Twig context: object data + resolved cross-references.
            $context = $this->buildTwigContext(data: $data, schema: $schema);

            // Create a template from the expression.
            $twig         = $this->getTwig();
            $templateName = 'computed_'.$propertyName.'_'.md5($expression);

            /*
             * @var \Twig\Loader\ArrayLoader $loader
             */

            $loader = $twig->getLoader();
            $loader->setTemplate($templateName, $expression);

            // Render the expression.
            $result = trim($twig->render($templateName, $context));

            // Attempt to cast numeric results to their appropriate types.
            return $this->castResult(result: $result);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'Computed field evaluation error: '.$e->getMessage(),
                context: [
                    'app'          => 'openregister',
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'propertyName' => $propertyName,
                    'expression'   => $expression,
                    'error'        => $e->getMessage(),
                ]
            );

            return null;
        }//end try
    }//end evaluateExpression()

    /**
     * Build the Twig context from object data, including cross-reference lookups.
     *
     * Resolves properties referenced via `_ref` by fetching related objects
     * and making their data available in the Twig context under the `_ref` key.
     *
     * @param array  $data   The object data.
     * @param Schema $schema The schema for identifying reference properties.
     * @param int    $depth  Current resolution depth (for circular reference prevention).
     *
     * @return array The Twig context with data and resolved references.
     */
    private function buildTwigContext(array $data, Schema $schema, int $depth=0): array
    {
        $context = $data;

        // Resolve cross-references if within depth limit.
        if ($depth < self::MAX_REF_DEPTH) {
            $refs = $this->resolveReferences(data: $data, schema: $schema, depth: $depth);
            if (empty($refs) === false) {
                $context['_ref'] = $refs;
            }
        }

        return $context;
    }//end buildTwigContext()

    /**
     * Resolve cross-references for properties that contain object UUIDs.
     *
     * Looks at schema properties with `$ref` definitions and resolves the
     * referenced objects, returning their data indexed by property name.
     *
     * @param array  $data   The object data containing reference UUIDs.
     * @param Schema $schema The schema with property definitions.
     * @param int    $depth  Current resolution depth.
     *
     * @return array Resolved reference data indexed by property name.
     */
    private function resolveReferences(array $data, Schema $schema, int $depth): array
    {
        // Guard against infinite recursion in nested reference resolution.
        $maxDepth = 10;
        if ($depth > $maxDepth) {
            $this->logger->warning(
                message: '[ComputedFieldHandler] Max reference resolution depth exceeded',
                context: [
                    'app'      => 'openregister',
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'depth'    => $depth,
                    'maxDepth' => $maxDepth,
                    'schemaId' => $schema->getId(),
                ]
            );

            return [];
        }

        $refs       = [];
        $properties = $schema->getProperties() ?? [];

        foreach ($properties as $propertyName => $property) {
            // Check if this property is a reference (has $ref or type 'string' with format 'uuid').
            $isRef = isset($property['$ref'])
                || (($property['type'] ?? '') === 'string' && ($property['format'] ?? '') === 'uuid');

            if ($isRef === false) {
                continue;
            }

            // Get the reference value (UUID) from the data.
            $refValue = $data[$propertyName] ?? null;
            if ($refValue === null || is_string($refValue) === false || trim($refValue) === '') {
                $refs[$propertyName] = [];
                continue;
            }

            // Try to fetch the referenced object.
            try {
                $referencedObject = $this->objectMapper->find(
                    identifier: $refValue,
                    _rbac: false,
                    _multitenancy: false
                );

                $refs[$propertyName] = $referencedObject->getObject() ?? [];
            } catch (\Exception $e) {
                $this->logger->debug(
                    message: '[ComputedFieldHandler] Failed to resolve reference',
                    context: [
                        'app'          => 'openregister',
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'propertyName' => $propertyName,
                        'refValue'     => $refValue,
                        'error'        => $e->getMessage(),
                    ]
                );
                $refs[$propertyName] = [];
            }//end try
        }//end foreach

        return $refs;
    }//end resolveReferences()

    /**
     * Cast a string result to its appropriate PHP type.
     *
     * Converts numeric strings to int/float as appropriate.
     * Returns the original string for non-numeric values.
     *
     * @param string $result The rendered template result.
     *
     * @return mixed The cast result.
     */
    private function castResult(string $result): mixed
    {
        // Empty result stays as empty string.
        if ($result === '') {
            return '';
        }

        // Try numeric casting.
        if (is_numeric($result) === true) {
            // Check if it's an integer (no decimal point).
            if (str_contains($result, '.') === false) {
                return (int) $result;
            }

            return (float) $result;
        }

        return $result;
    }//end castResult()

    /**
     * Check if a schema has any computed properties.
     *
     * Utility method to quickly determine if computed field evaluation
     * is needed for a given schema.
     *
     * @param Schema $schema The schema to check.
     *
     * @return bool True if the schema has at least one computed property.
     */
    public function hasComputedProperties(Schema $schema): bool
    {
        $properties = $schema->getProperties() ?? [];

        foreach ($properties as $property) {
            if (isset($property['computed']) === true && is_array($property['computed']) === true) {
                return true;
            }
        }

        return false;
    }//end hasComputedProperties()

    /**
     * Get the names of computed properties for a given evaluation mode.
     *
     * @param Schema $schema     The schema to inspect.
     * @param string $evaluateOn The evaluation mode: 'save' or 'read'.
     *
     * @return array List of property names that are computed for the given mode.
     */
    public function getComputedPropertyNames(Schema $schema, string $evaluateOn='save'): array
    {
        $names      = [];
        $properties = $schema->getProperties() ?? [];

        foreach ($properties as $propertyName => $property) {
            if (isset($property['computed']) === false || is_array($property['computed']) === false) {
                continue;
            }

            $propertyEvaluateOn = $property['computed']['evaluateOn'] ?? 'save';
            if ($propertyEvaluateOn === $evaluateOn) {
                $names[] = $propertyName;
            }
        }

        return $names;
    }//end getComputedPropertyNames()
}//end class
