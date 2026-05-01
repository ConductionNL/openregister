<?php

/**
 * OpenAPI Specification (OAS) Service
 *
 * This service generates OpenAPI Specification (OAS) documentation for registers and schemas.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\OasValidationException;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use OCP\IURLGenerator;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * OasService generates OpenAPI Specification documentation
 *
 * Service for generating OpenAPI Specification (OAS) documentation for registers and schemas.
 * Creates comprehensive API documentation including endpoints, schemas, parameters,
 * and examples based on register and schema definitions.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     OAS generation requires many endpoint and schema methods
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex OpenAPI schema generation logic
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class OasService
{
    /**
     * Base path to OAS resources
     *
     * Path to base OpenAPI specification template file.
     *
     * @var string Base OAS resource file path
     */
    private const OAS_RESOURCE_PATH = __DIR__.'/Resources/BaseOas.json';

    /**
     * The OpenAPI specification being built
     *
     * Array containing the complete OpenAPI specification structure.
     *
     * @var array<string, mixed> OpenAPI specification array
     */
    private array $oas = [];

    /**
     * Register mapper
     *
     * Handles database operations for register entities.
     *
     * @var RegisterMapper Register mapper instance
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Schema mapper
     *
     * Handles database operations for schema entities.
     *
     * @var SchemaMapper Schema mapper instance
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * URL generator
     *
     * Generates absolute URLs for API endpoints in OAS documentation.
     *
     * @var IURLGenerator URL generator instance
     */
    private readonly IURLGenerator $urlGenerator;

    /**
     * Logger for validation messages (optional).
     *
     * @var LoggerInterface|null Logger or null
     */
    private readonly ?LoggerInterface $logger;

    /**
     * Validation report for the most recent createOas() invocation.
     *
     * @var OasValidationReport The current report
     */
    private OasValidationReport $report;

    /**
     * NLGov-permitted HTTP methods on documented operations (API-01).
     */
    private const ALLOWED_HTTP_METHODS = ['get', 'post', 'put', 'delete', 'parameters'];

    /**
     * NLGov-permitted HTTP response status codes (API-03).
     *
     * @var list<string>
     */
    private const ALLOWED_STATUS_CODES = ['200', '201', '204', '400', '401', '403', '404', '422', '500', 'default'];

    /**
     * Constructor for OasService
     *
     * @param RegisterMapper       $registerMapper Register mapper for database operations
     * @param SchemaMapper         $schemaMapper   Schema mapper for database operations
     * @param IURLGenerator        $urlGenerator   URL generator for absolute URLs
     * @param LoggerInterface|null $logger         PSR-3 logger for surfacing validation issues
     */
    public function __construct(
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        IURLGenerator $urlGenerator,
        ?LoggerInterface $logger=null
    ) {
        $this->registerMapper = $registerMapper;
        $this->schemaMapper   = $schemaMapper;
        $this->urlGenerator   = $urlGenerator;
        $this->logger         = $logger;
        $this->report         = new OasValidationReport();
    }//end __construct()

    /**
     * Returns the validation report from the most recent createOas() call.
     *
     * @return OasValidationReport The current report
     */
    public function getLastValidationReport(): OasValidationReport
    {
        return $this->report;
    }//end getLastValidationReport()

    /**
     * Create OpenAPI Specification for register(s)
     *
     * Generates complete OpenAPI Specification documentation for one or all registers.
     * Includes all schemas associated with the register(s), generates endpoint definitions,
     * and creates comprehensive API documentation.
     *
     * @param string|null $registerId Optional register ID to generate OAS for specific register.
     *                                If null, generates OAS for all registers.
     * @param bool        $strict     When true, throws OasValidationException if any validation
     *                                error is detected. When false (default), errors are auto-
     *                                corrected where possible and logged via the report.
     *
     * @return array<string, mixed> The complete OpenAPI specification array
     *
     * @throws \Exception                When base OAS file cannot be read or parsed
     * @throws OasValidationException    In strict mode, when the generated OAS has validation errors
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex OAS generation with multiple schema and path operations
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple conditional paths for register and schema processing
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) OAS generation requires multiple steps: setup, schema loading,
     *                                               CRUD paths, validation
     */
    public function createOas(?string $registerId=null, bool $strict=false): array
    {
        // Reset the validation report at the start of every generation pass.
        $this->report = new OasValidationReport();

        // Step 1: Reset OAS to base state from template file.
        $this->oas = $this->getBaseOas();

        // Step 2: Get registers to document.
        // If registerId provided, get only that register; otherwise get all registers.
        $registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
        if ($registerId !== null) {
            $registers = [$this->registerMapper->find($registerId, _rbac: false, _multitenancy: false)];
        }

        // Step 3: Extract unique schema IDs from all registers.
        // Multiple registers may share schemas, so we deduplicate.
        $schemaIds = [];
        foreach ($registers ?? [] as $register) {
            $schemaIds = array_merge($schemaIds, $register->getSchemas());
        }

        // Remove duplicates to avoid loading same schema multiple times.
        $uniqueSchemaIds = array_unique($schemaIds);

        // Step 4: Get all schemas using unique schema IDs and index by schema ID.
        // Indexing by ID allows fast lookup when processing registers.
        // Bypass RBAC and multi-tenancy since OAS generation needs all schemas
        // regardless of the current user's organization.
        $schemas = [];
        foreach ($this->schemaMapper->findMultiple($uniqueSchemaIds, _rbac: false, _multitenancy: false) as $schema) {
            $schemas[$schema->getId()] = $schema;
        }

        // Step 5: Update servers configuration with actual API base URL.
        $this->oas['servers'] = [
            [
                'url'         => $this->urlGenerator->getAbsoluteURL('/apps/openregister/api'),
                'description' => 'OpenRegister API Server',
            ],
        ];

        // Step 6: If specific register requested, update info section with register details.
        if ($registerId !== null) {
            $register = $registers[0];

            // Build enhanced description from register description or generate default.
            $description = $register->getDescription();
            if (empty($description) === true) {
                $description  = 'API for '.$register->getTitle().' register providing CRUD ';
                $description .= 'operations, filtering, and search capabilities.';
            }

            // Update info section while preserving base contact and license information.
            $this->oas['info'] = array_merge(
                $this->oas['info'],
                [
                    'title'       => $register->getTitle().' API',
                    'version'     => $register->getVersion(),
                    'description' => $description,
                ]
            );
        }

        // Step 7: Initialize tags array for API endpoint grouping.
        $this->oas['tags'] = [];

        // Step 8: Add schemas to components and create tags for each schema.
        foreach ($schemas as $schema) {
            // Step 8a: Ensure schema has valid title (skip if empty).
            $schemaTitle = $schema->getTitle();
            if (empty($schemaTitle) === true) {
                continue;
            }

            // Step 8b: Enrich schema definition with OpenAPI-specific properties.
            $schemaDefinition    = $this->enrichSchema(schema: $schema);
            $sanitizedSchemaName = $this->sanitizeSchemaName(title: $schemaTitle);

            // Step 8c: Validate schema definition before adding to components.
            if (empty($schemaDefinition) === false && is_array($schemaDefinition) === true) {
                $this->oas['components']['schemas'][$sanitizedSchemaName] = $schemaDefinition;

                // Add tag for the schema (keep original title for display).
                $this->oas['tags'][] = [
                    'name'        => $schemaTitle,
                    'description' => $schema->getDescription() ?? 'Operations for '.$schemaTitle,
                ];
            }
        }//end foreach

        // Step 9: Extract RBAC groups from all schemas and generate OAuth2 scopes.
        $schemaRbacMap = [];
        $allGroups     = [];
        foreach ($schemas as $schemaId => $schema) {
            $rbac = $this->extractSchemaGroups(schema: $schema);
            $schemaRbacMap[$schemaId] = $rbac;
            $allGroups = array_merge(
                $allGroups,
                $rbac['createGroups'],
                $rbac['readGroups'],
                $rbac['updateGroups'],
                $rbac['deleteGroups']
            );
        }

        // Always include admin since it has access to all endpoints.
        $allGroups[] = 'admin';
        $allGroups   = array_values(array_unique($allGroups));

        $scopes = [];
        foreach ($allGroups as $group) {
            $scopes[$group] = $this->getScopeDescription(group: $group);
        }

        $this->oas['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'] = $scopes;

        // Initialize paths array.
        $this->oas['paths'] = [];

        // Determine if we need operationId prefixes for uniqueness.
        // When generating for all registers, prefix with register slug to avoid collisions.
        $useRegisterPrefix = ($registerId === null && count($registers) > 1);

        // Add paths for each register.
        foreach ($registers ?? [] as $register) {
            // Get schema slugs for the current register.
            $schemaIds = $register->getSchemas();

            // Build operationId prefix from register title (PascalCase).
            $operationIdPrefix = '';
            if ($useRegisterPrefix === true) {
                $operationIdPrefix = $this->pascalCase(string: $register->getTitle());
            }

            // Loop through each schema slug to get the schema from the schemas array.
            foreach ($schemaIds ?? [] as $schemaId) {
                if (($schemas[$schemaId] ?? null) !== null) {
                    $schema = $schemas[$schemaId];
                    $rbac   = $schemaRbacMap[$schemaId] ?? [
                        'createGroups' => [],
                        'readGroups'   => [],
                        'updateGroups' => [],
                        'deleteGroups' => [],
                    ];
                    $this->addCrudPaths(
                        register: $register,
                        schema: $schema,
                        rbac: $rbac,
                        operationIdPrefix: $operationIdPrefix
                    );
                    $this->addExtendedPaths(register: $register, schema: $schema);
                }
            }
        }//end foreach

        // Validate the final OpenAPI specification before returning.
        $this->validateOasIntegrity();

        // Log validation issues so operators see them in the standard logs.
        $this->logValidationIssues();

        // Strict mode: refuse to return invalid output. The report is still
        // available via getLastValidationReport() so the caller can render it.
        if ($strict === true && $this->report->hasErrors() === true) {
            throw new OasValidationException(
                message: 'Generated OAS failed strict validation: '.count($this->report->getErrors()).' error(s)',
                report: $this->report,
            );
        }

        return $this->oas;
    }//end createOas()

    /**
     * Log every validation issue once at the appropriate severity level.
     *
     * @return void
     */
    private function logValidationIssues(): void
    {
        if ($this->logger === null || $this->report->isEmpty() === true) {
            return;
        }

        foreach ($this->report->getIssues() as $issue) {
            $context = ['path' => $issue['path'], 'code' => $issue['code']];
            if ($issue['severity'] === OasValidationReport::SEVERITY_ERROR) {
                $this->logger->error('OAS validation: '.$issue['message'], $context);
            } else {
                $this->logger->warning('OAS validation: '.$issue['message'], $context);
            }
        }
    }//end logValidationIssues()

    /**
     * Get the base OAS file as array
     *
     * @return array The base OAS array
     *
     * @throws \Exception When file cannot be read or parsed
     */
    private function getBaseOas(): array
    {
        $content = file_get_contents(self::OAS_RESOURCE_PATH);
        if ($content === false) {
            throw new Exception('Could not read base OAS file');
        }

        $oas = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Could not parse base OAS file: '.json_last_error_msg());
        }

        return $oas;
    }//end getBaseOas()

    /**
     * Extract unique RBAC groups from schema-level and property-level authorization rules
     *
     * Collects groups from the schema's authorization field (CRUD-level access control)
     * and from individual property authorization rules (field-level access control).
     *
     * @param object $schema The schema object
     *
     * @return array{createGroups: string[], readGroups: string[], updateGroups: string[], deleteGroups: string[]}
     *               Unique groups per CRUD action
     */
    private function extractSchemaGroups(object $schema): array
    {
        $createGroups = [];
        $readGroups   = [];
        $updateGroups = [];
        $deleteGroups = [];

        // Step 1: Extract groups from effective authorization (schema-level, or register cascade).
        $effectiveAuth = $this->resolveEffectiveAuthorization(schema: $schema);
        if (is_array($effectiveAuth) === true && empty($effectiveAuth) === false) {
            foreach (['create', 'read', 'update', 'delete'] as $action) {
                foreach ($effectiveAuth[$action] ?? [] as $rule) {
                    // Skip 'manage' action -- it is not a CRUD action.
                    $group = $this->extractGroupFromRule(rule: $rule);
                    if ($group !== null) {
                        ${$action.'Groups'}[] = $group;
                    }
                }
            }
        }

        // Step 2: Extract groups from property-level authorization.
        $properties = $schema->getProperties();
        foreach ($properties ?? [] as $propertyDefinition) {
            if (is_array($propertyDefinition) === false) {
                continue;
            }

            $auth = $propertyDefinition['authorization'] ?? null;
            if ($auth === null || is_array($auth) === false) {
                continue;
            }

            foreach (['create', 'read', 'update', 'delete'] as $action) {
                foreach ($auth[$action] ?? [] as $rule) {
                    $group = $this->extractGroupFromRule(rule: $rule);
                    if ($group !== null) {
                        ${$action.'Groups'}[] = $group;
                    }
                }
            }
        }//end foreach

        return [
            'createGroups' => array_values(array_unique($createGroups)),
            'readGroups'   => array_values(array_unique($readGroups)),
            'updateGroups' => array_values(array_unique($updateGroups)),
            'deleteGroups' => array_values(array_unique($deleteGroups)),
        ];
    }//end extractSchemaGroups()

    /**
     * Extract group name from an authorization rule
     *
     * Rules can be either a plain string (group name) or an object with a 'group' key.
     *
     * @param mixed $rule The authorization rule (string or array)
     *
     * @return string|null The group name, or null if not extractable
     */
    private function extractGroupFromRule($rule): ?string
    {
        if (is_string($rule) === true) {
            return $rule;
        }

        if (is_array($rule) === true && isset($rule['group']) === true) {
            return $rule['group'];
        }

        return null;
    }//end extractGroupFromRule()

    /**
     * Get a human-readable description for an OAuth2 scope based on group name
     *
     * @param string $group The Nextcloud group name
     *
     * @return string The scope description
     */
    private function getScopeDescription(string $group): string
    {
        if ($group === 'admin') {
            return 'Full administrative access';
        }

        if ($group === 'public') {
            return 'Public (unauthenticated) access';
        }

        return 'Access for '.$group.' group';
    }//end getScopeDescription()

    /**
     * Apply RBAC information to an operation
     *
     * Always includes `admin` since admin users have access to all endpoints.
     * Merges in any schema-specific groups for this CRUD action and:
     *  - appends a human-readable `**Required scopes:**` block to the operation
     *    description (Markdown rendered by Swagger UI / Redoc);
     *  - adds a 403 response definition pointing at the standard Error schema;
     *  - emits a per-operation OpenAPI 3.0 `security` requirement enumerating
     *    the groups as OAuth2 scopes alongside `basicAuth` as fallback. This
     *    makes the OAS a machine-readable access audit (see the Scope Audit
     *    requirement in the rbac-scopes spec) and lets generated client SDKs
     *    request the right scope set.
     *
     * The `security` block is OR-semantics across alternatives in the array
     * (per the OpenAPI 3.0 spec), so a caller can either present a Bearer token
     * with one of the listed oauth2 scopes OR fall back to Basic auth. The
     * registered Nextcloud OAuth2 scope vocabulary is populated globally from
     * the union of every schema's groups in createOas().
     *
     * @param array    $operation The operation array (passed by reference)
     * @param string[] $groups    The schema-specific groups that have access to this operation
     *
     * @return void
     */
    private function applyRbacToOperation(array &$operation, array $groups): void
    {
        // Admin always has access to every endpoint.
        if (in_array('admin', $groups, true) === false) {
            array_unshift($groups, 'admin');
        }

        // Deduplicate while preserving order — admin first, then schema groups.
        $groups = array_values(array_unique($groups));

        // Build scope list as inline code fragments.
        $scopeList = implode(
                ', ',
                array_map(
            static function (string $group): string {
                return '`'.$group.'`';
            },
                $groups
        )
                );

        $operation['description'] .= "\n\n**Required scopes:** ".$scopeList;

        // Add 403 response.
        $operation['responses']['403'] = [
            'description' => 'Forbidden — user does not have the required group membership for this action',
            'content'     => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Error'],
                ],
            ],
        ];

        // Emit per-operation security requirement: oauth2 with the resolved
        // scope set, OR basicAuth fallback. Two array entries = OR semantics
        // in OpenAPI 3.0.
        $operation['security'] = [
            ['oauth2' => $groups],
            ['basicAuth' => []],
        ];
    }//end applyRbacToOperation()

    /**
     * Extended endpoints that should be included in OAS generation
     * This whitelist ensures only stable, public-facing endpoints are documented
     *
     * @var array<string>
     */
    private const INCLUDED_EXTENDED_ENDPOINTS = [
        // Only include stable, public-facing endpoints.
        // 'audit-trails' - Internal audit functionality, not for public API.
        // 'files' - File management, may be too complex for basic API consumers.
        // 'lock' - Locking mechanism, typically used internally.
        // 'unlock' - Unlocking mechanism, typically used internally.
    ];

    /**
     * Enrich a schema with valid OpenAPI schema definitions
     *
     * This method includes legitimate API properties like @self but ensures
     * property definitions conform to OpenAPI schema standards.
     *
     * @param object $schema The schema object
     *
     * @return array Enriched schema with type, x-tags, and properties.
     */
    private function enrichSchema(object $schema): array
    {
        $schemaProperties = $schema->getProperties();

        // Start with core API properties.
        $cleanProperties = [
            '_self' => [
                '$ref'        => '#/components/schemas/_self',
                'readOnly'    => true,
                'description' => 'Object metadata including timestamps, ownership, and system information',
            ],
            'id'    => [
                'type'        => 'string',
                'format'      => 'uuid',
                'readOnly'    => true,
                'example'     => '123e4567-e89b-12d3-a456-426614174000',
                'description' => 'The unique identifier for the object.',
            ],
        ];

        // Process schema-defined properties and ensure they're valid OAS.
        foreach ($schemaProperties ?? [] as $propertyName => $propertyDefinition) {
            $cleanProperties[$propertyName] = $this->sanitizePropertyDefinition(propertyDefinition: $propertyDefinition);
        }

        return [
            'type'       => 'object',
            'x-tags'     => [$schema->getTitle()],
            'properties' => $cleanProperties,
        ];
    }//end enrichSchema()

    /**
     * Sanitize property definition to be valid OpenAPI schema
     *
     * This method ensures property definitions conform to OpenAPI 3.1 standards
     * by removing invalid properties and normalizing the structure.
     *
     * @param mixed $propertyDefinition The property definition to sanitize
     *
     * @return (array[]|mixed|string)[] Valid OpenAPI property definition
     *
     * @psalm-return array{
     *     title?: mixed,
     *     writeOnly?: mixed,
     *     readOnly?: mixed,
     *     nullable?: mixed,
     *     '$ref'?: mixed,
     *     not?: mixed,
     *     oneOf?: mixed,
     *     anyOf?: mixed,
     *     allOf?: mixed|non-empty-list<non-empty-array>,
     *     additionalProperties?: mixed,
     *     items?: mixed,
     *     properties?: mixed,
     *     required?: mixed,
     *     minProperties?: mixed,
     *     maxProperties?: mixed,
     *     uniqueItems?: mixed,
     *     minItems?: mixed,
     *     maxItems?: mixed,
     *     pattern?: mixed,
     *     minLength?: mixed,
     *     maxLength?: mixed,
     *     exclusiveMinimum?: mixed,
     *     minimum?: mixed,
     *     exclusiveMaximum?: mixed,
     *     maximum?: mixed,
     *     multipleOf?: mixed,
     *     const?: mixed,
     *     enum?: mixed,
     *     default?: mixed,
     *     examples?: mixed,
     *     example?: mixed,
     *     description?: 'Property value'|mixed,
     *     format?: mixed,
     *     type?: 'string'|mixed
     * }
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Many OpenAPI schema keywords require individual validation
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple conditional paths for schema keyword processing
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive OpenAPI schema validation logic
     */
    private function sanitizePropertyDefinition($propertyDefinition): array
    {
        // If it's not an array, convert to basic string type.
        if (is_array($propertyDefinition) === false) {
            return [
                'type'        => 'string',
                'description' => 'Property value',
            ];
        }

        // Start with a clean definition.
        $cleanDef = [];

        // Standard OpenAPI schema keywords that are allowed.
        $allowedKeywords = [
            'type',
            'format',
            'description',
            'example',
            'examples',
            'default',
            'enum',
            'const',
            'multipleOf',
            'maximum',
            'exclusiveMaximum',
            'minimum',
            'exclusiveMinimum',
            'maxLength',
            'minLength',
            'pattern',
            'maxItems',
            'minItems',
            'uniqueItems',
            'maxProperties',
            'minProperties',
            'required',
            'properties',
            'items',
            'additionalProperties',
            'allOf',
            'anyOf',
            'oneOf',
            'not',
            '$ref',
            'nullable',
            'readOnly',
            'writeOnly',
            'title',
        ];

        // Copy only valid OpenAPI schema keywords (strips internal fields like
        // objectConfiguration, inversedBy, authorization, defaultBehavior, etc.).
        foreach ($allowedKeywords as $keyword) {
            if (($propertyDefinition[$keyword] ?? null) !== null) {
                $cleanDef[$keyword] = $propertyDefinition[$keyword];
            }
        }

        // Recursively sanitize nested structures (items, properties, composition keywords).
        if (isset($cleanDef['items']) === true && is_array($cleanDef['items']) === true) {
            $cleanDef['items'] = $this->sanitizePropertyDefinition(propertyDefinition: $cleanDef['items']);
        }

        if (isset($cleanDef['properties']) === true && is_array($cleanDef['properties']) === true) {
            foreach ($cleanDef['properties'] as $subPropName => $subPropDef) {
                $cleanDef['properties'][$subPropName] = $this->sanitizePropertyDefinition(propertyDefinition: $subPropDef);
            }
        }

        foreach (['oneOf', 'anyOf', 'allOf'] as $compositionKey) {
            if (isset($cleanDef[$compositionKey]) === true && is_array($cleanDef[$compositionKey]) === true) {
                foreach ($cleanDef[$compositionKey] as $idx => $item) {
                    if (is_array($item) === true) {
                        $cleanDef[$compositionKey][$idx] = $this->sanitizePropertyDefinition(propertyDefinition: $item);
                    }
                }
            }
        }

        // Remove invalid/empty values that violate OpenAPI spec.
        // OneOf must have at least 1 item, remove if empty.
        $hasOneOf     = ($cleanDef['oneOf'] ?? null) !== null;
        $oneOfIsEmpty = empty($cleanDef['oneOf']) === true || is_array($cleanDef['oneOf']) === false;
        if ($hasOneOf === true && $oneOfIsEmpty === true) {
            unset($cleanDef['oneOf']);
        }//end if

        // AnyOf must have at least 1 item, remove if empty.
        $hasAnyOf     = ($cleanDef['anyOf'] ?? null) !== null;
        $anyOfIsEmpty = empty($cleanDef['anyOf']) === true || is_array($cleanDef['anyOf']) === false;
        if ($hasAnyOf === true && $anyOfIsEmpty === true) {
            unset($cleanDef['anyOf']);
        }//end if

        // AllOf must have at least 1 item, remove if empty or invalid.
        if (isset($cleanDef['allOf']) === true) {
            if (is_array($cleanDef['allOf']) === false || empty($cleanDef['allOf']) === true) {
                unset($cleanDef['allOf']);
            }

            if (isset($cleanDef['allOf']) === true && is_array($cleanDef['allOf']) === true) {
                // Validate each allOf element.
                $validAllOfItems = [];
                foreach ($cleanDef['allOf'] as $item) {
                    // Each allOf item must be an object/array.
                    if (is_array($item) === true && empty($item) === false) {
                        $validAllOfItems[] = $item;
                    }
                }

                // If no valid items remain, remove allOf.
                if (empty($validAllOfItems) === true) {
                    unset($cleanDef['allOf']);
                }

                if (empty($validAllOfItems) === false) {
                    $cleanDef['allOf'] = $validAllOfItems;
                }
            }//end if
        }//end if

        // $ref must be a non-empty string, remove if empty.
        $hasRef     = ($cleanDef['$ref'] ?? null) !== null;
        $refIsEmpty = empty($cleanDef['$ref']) === true || is_string($cleanDef['$ref']) === false;
        if ($hasRef === true && $refIsEmpty === true) {
            unset($cleanDef['$ref']);
        }//end if

        // Normalize bare $ref values (e.g., "vestiging") to proper component references.
        if (isset($cleanDef['$ref']) === true
            && is_string($cleanDef['$ref']) === true
            && strpos($cleanDef['$ref'], '#/') !== 0
        ) {
            $cleanDef['$ref'] = '#/components/schemas/'.$this->sanitizeSchemaName(title: $cleanDef['$ref']);
        }

        // Enum must have at least 1 item, remove if empty.
        $hasEnum     = ($cleanDef['enum'] ?? null) !== null;
        $enumIsEmpty = empty($cleanDef['enum']) === true || is_array($cleanDef['enum']) === false;
        if ($hasEnum === true && $enumIsEmpty === true) {
            unset($cleanDef['enum']);
        }//end if

        // Property-level `required` must be an array (list of required sub-properties),
        // not a boolean. Booleans leak from schema config and violate OpenAPI spec.
        if (isset($cleanDef['required']) === true && is_array($cleanDef['required']) === false) {
            unset($cleanDef['required']);
        }

        // Validate `type` is a recognized OpenAPI 3.1 type.
        $validTypes = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];
        if (isset($cleanDef['type']) === true) {
            if (is_string($cleanDef['type']) === true
                && in_array($cleanDef['type'], $validTypes, true) === false
            ) {
                $cleanDef['type'] = 'string';
            }
        }

        // `items` must be a JSON Schema object, not an array. Fix malformed items.
        if (isset($cleanDef['items']) === true) {
            if (is_array($cleanDef['items']) === true && array_is_list($cleanDef['items']) === true) {
                // Sequential array (list) — not valid. Use first element or default.
                $firstItem = $cleanDef['items'][0] ?? null;
                if (empty($firstItem) === false) {
                    $cleanDef['items'] = $firstItem;
                } else {
                    $cleanDef['items'] = ['type' => 'string'];
                }
            }

            if (is_array($cleanDef['items']) === false || empty($cleanDef['items']) === true) {
                $cleanDef['items'] = ['type' => 'string'];
            }
        }

        // Array types must have items definition.
        if (($cleanDef['type'] ?? null) === 'array' && isset($cleanDef['items']) === false) {
            $cleanDef['items'] = ['type' => 'string'];
        }

        // Ensure we have at least a type.
        if (isset($cleanDef['type']) === false && isset($cleanDef['$ref']) === false) {
            $cleanDef['type'] = 'string';
        }

        // Add basic description if missing.
        if (isset($cleanDef['description']) === false && isset($cleanDef['$ref']) === false) {
            $cleanDef['description'] = 'Property value';
        }

        return $cleanDef;
    }//end sanitizePropertyDefinition()

    /**
     * Add CRUD paths for a schema.
     *
     * @param object $register          The register object
     * @param object $schema            The schema object
     * @param array  $rbac              RBAC groups {createGroups, readGroups, updateGroups, deleteGroups}
     * @param string $operationIdPrefix Prefix for operationId to ensure uniqueness across registers
     *
     * @return void
     */
    private function addCrudPaths(object $register, object $schema, array $rbac=[], string $operationIdPrefix=''): void
    {
        $registerSlugValue = $register->getSlug();
        if ($registerSlugValue !== null && $registerSlugValue !== '') {
            $registerSlug = $registerSlugValue;
        } else {
            $registerSlug = $this->slugify(string: $register->getTitle());
        }

        $schemaSlugValue = $schema->getSlug();
        if ($schemaSlugValue !== null && $schemaSlugValue !== '') {
            $schemaSlug = $schemaSlugValue;
        } else {
            $schemaSlug = $this->slugify(string: $schema->getTitle());
        }

        $basePath = '/objects/'.$registerSlug.'/'.$schemaSlug;

        // Collection endpoints (tags are inside individual operations).
        $getCollection = $this->createGetCollectionOperation(schema: $schema);
        $postOp        = $this->createPostOperation(schema: $schema);

        // Apply operationId prefix for uniqueness across registers.
        if ($operationIdPrefix !== '') {
            $getCollection['operationId'] = $operationIdPrefix.$getCollection['operationId'];
            $postOp['operationId']        = $operationIdPrefix.$postOp['operationId'];
        }

        // Append RBAC group info to descriptions and add 403 responses.
        $this->applyRbacToOperation(operation: $getCollection, groups: $rbac['readGroups'] ?? []);
        $this->applyRbacToOperation(operation: $postOp, groups: $rbac['createGroups'] ?? []);

        $this->oas['paths'][$basePath] = [
            'get'  => $getCollection,
            'post' => $postOp,
        ];

        // Individual resource endpoints (tags are inside individual operations).
        $getOp    = $this->createGetOperation(schema: $schema);
        $putOp    = $this->createPutOperation(schema: $schema);
        $deleteOp = $this->createDeleteOperation(schema: $schema);

        // Apply operationId prefix for uniqueness across registers.
        if ($operationIdPrefix !== '') {
            $getOp['operationId']    = $operationIdPrefix.$getOp['operationId'];
            $putOp['operationId']    = $operationIdPrefix.$putOp['operationId'];
            $deleteOp['operationId'] = $operationIdPrefix.$deleteOp['operationId'];
        }

        // Append RBAC group info to descriptions and add 403 responses.
        $this->applyRbacToOperation(operation: $getOp, groups: $rbac['readGroups'] ?? []);
        $this->applyRbacToOperation(operation: $putOp, groups: $rbac['updateGroups'] ?? []);
        $this->applyRbacToOperation(operation: $deleteOp, groups: $rbac['deleteGroups'] ?? []);

        $this->oas['paths'][$basePath.'/{id}'] = [
            'get'    => $getOp,
            'put'    => $putOp,
            'delete' => $deleteOp,
        ];
    }//end addCrudPaths()

    /**
     * Add extended paths for a schema using whitelist approach
     *
     * Only adds endpoints that are explicitly whitelisted in INCLUDED_EXTENDED_ENDPOINTS.
     * This prevents internal/complex endpoints from being exposed in the public API spec.
     *
     * @param object $register The register object
     * @param object $schema   The schema object
     *
     * @return void
     */
    private function addExtendedPaths(object $register, object $schema): void
    {
        $registerSlugValue = $register->getSlug();
        if ($registerSlugValue !== null && $registerSlugValue !== '') {
            $registerSlug = $registerSlugValue;
        } else {
            $registerSlug = $this->slugify(string: $register->getTitle());
        }

        $schemaSlugValue = $schema->getSlug();
        if ($schemaSlugValue !== null && $schemaSlugValue !== '') {
            $schemaSlug = $schemaSlugValue;
        } else {
            $schemaSlug = $this->slugify(string: $schema->getTitle());
        }

        $basePath = '/objects/'.$registerSlug.'/'.$schemaSlug;

        // Only add whitelisted extended endpoints.
        foreach (self::INCLUDED_EXTENDED_ENDPOINTS ?? [] as $endpoint) {
            switch ($endpoint) {
                case 'audit-trails':
                    $this->oas['paths'][$basePath.'/{id}/audit-trails'] = [
                        'get' => $this->createLogsOperation(schema: $schema),
                    ];
                    break;

                case 'files':
                    $this->oas['paths'][$basePath.'/{id}/files'] = [
                        'get'  => $this->createGetFilesOperation(schema: $schema),
                        'post' => $this->createPostFileOperation(schema: $schema),
                    ];
                    break;

                case 'lock':
                    $this->oas['paths'][$basePath.'/{id}/lock'] = [
                        'post' => $this->createLockOperation(schema: $schema),
                    ];
                    break;

                case 'unlock':
                    $this->oas['paths'][$basePath.'/{id}/unlock'] = [
                        'post' => $this->createUnlockOperation(schema: $schema),
                    ];
                    break;
            }//end switch
        }//end foreach

        // Note: By default, NO extended endpoints are included.
        // To include them, add them to INCLUDED_EXTENDED_ENDPOINTS constant.
        // This ensures a clean, minimal API specification focused on core CRUD operations.
    }//end addExtendedPaths()

    /**
     * Create common query parameters for object operations
     *
     * @param bool   $isCollection Whether this is for a collection endpoint
     * @param object $schema       The schema object for generating dynamic filter parameters
     *                             (only used for collection endpoints)
     *
     * @return array List of query parameter definitions for OpenAPI spec.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Boolean flag controls collection vs single item parameters
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Dynamic parameter generation from schema properties
     */
    private function createCommonQueryParameters(bool $isCollection=false, ?object $schema=null): array
    {
        $parameters = [
            [
                'name'        => '_extend',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Comma-separated list of properties to extend.',
                'schema'      => [
                    'type' => 'string',
                ],
                'example'     => 'property1,property2,property3',
            ],
            [
                'name'        => '_filter',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Comma-separated list of properties to include in the response. ',
                'schema'      => [
                    'type' => 'string',
                ],
                'example'     => 'id,name,description',
            ],
            [
                'name'        => '_unset',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Comma-separated list of properties to remove from the response.',
                'schema'      => [
                    'type' => 'string',
                ],
                'example'     => 'internalField1,internalField2',
            ],
        ];

        // Add collection-specific parameters.
        if ($isCollection === true) {
            // Add _search parameter.
            $parameters[] = [
                'name'        => '_search',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Full-text search query to filter objects in the collection.',
                'schema'      => [
                    'type' => 'string',
                ],
                'example'     => 'search term',
            ];

            // Add dynamic filter parameters based on schema properties.
            if ($schema !== null) {
                $schemaProperties = $schema->getProperties();
                foreach ($schemaProperties ?? [] as $propertyName => $propertyDefinition) {
                    // Skip metadata properties and internal system properties.
                    if (str_starts_with($propertyName, '@') === true) {
                        continue;
                    }

                    // Skip the id property as it's already handled as a path parameter.
                    if ($propertyName === 'id') {
                        continue;
                    }

                    // Get property type from definition.
                    $propertyType = $this->getPropertyType(propertyDefinition: $propertyDefinition);

                    // Build schema for parameter.
                    $paramSchema = [
                        'type' => $propertyType,
                    ];

                    // Array types require an items field.
                    if ($propertyType === 'array') {
                        $paramSchema['items'] = ['type' => 'string'];
                    }

                    $parameters[] = [
                        'name'        => $propertyName,
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'Filter results by '.$propertyName,
                        'schema'      => $paramSchema,
                    ];
                }//end foreach
            }//end if
        }//end if

        return $parameters;
    }//end createCommonQueryParameters()

    /**
     * Get OpenAPI type for a property definition
     *
     * @param mixed $propertyDefinition The property definition from the schema
     *
     * @return string The OpenAPI type for the property
     */
    private function getPropertyType($propertyDefinition): string
    {
        $validTypes = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

        // If the property definition is an array, look for the type key.
        if (is_array($propertyDefinition) === true && (($propertyDefinition['type'] ?? null) !== null)) {
            $type = $propertyDefinition['type'];
            // Validate the type is a recognized OpenAPI type.
            if (in_array($type, $validTypes, true) === true) {
                return $type;
            }

            return 'string';
        }

        // If the property definition is a string, assume it's the type.
        if (is_string($propertyDefinition) === true) {
            // Map common types to OpenAPI types.
            $typeMap = [
                'int'    => 'integer',
                'float'  => 'number',
                'bool'   => 'boolean',
                'string' => 'string',
                'array'  => 'array',
                'object' => 'object',
            ];

            return $typeMap[$propertyDefinition] ?? 'string';
        }

        // Default to string if type cannot be determined.
        return 'string';
    }//end getPropertyType()

    /**
     * Create GET collection operation.
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for GET collection.
     */
    private function createGetCollectionOperation(object $schema): array
    {
        // Ensure schema has a valid title before proceeding.
        $schemaTitle = $schema->getTitle();
        if (empty($schemaTitle) === true) {
            $schemaTitle = 'UnknownSchema';
        }

        $sanitizedSchemaName = $this->sanitizeSchemaName(title: $schemaTitle);

        // Validate that we have a proper schema reference.
        if (empty($sanitizedSchemaName) === true) {
            $sanitizedSchemaName = 'UnknownSchema';
        }

        return [
            'summary'     => 'Get all '.$schemaTitle.' objects',
            'operationId' => 'getAll'.$this->pascalCase(string: $schemaTitle),
            'tags'        => [$schemaTitle],
            'description' => 'Retrieve a list of all '.$schemaTitle.' objects',
            'parameters'  => $this->createCommonQueryParameters(isCollection: true, schema: $schema),
            'responses'   => [
                '200' => [
                    'description' => 'List of '.$schemaTitle.' objects with pagination metadata',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'allOf' => [
                                    [
                                        '$ref' => '#/components/schemas/PaginatedResponse',
                                    ],
                                    [
                                        'type'       => 'object',
                                        'properties' => [
                                            'results' => [
                                                'type'  => 'array',
                                                'items' => [
                                                    '$ref' => '#/components/schemas/'.$sanitizedSchemaName,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '400' => [
                    'description' => 'Invalid query parameters',
                    'content'     => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Error'],
                        ],
                    ],
                ],
            ],
        ];
    }//end createGetCollectionOperation()

    /**
     * Create GET operation.
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for GET single item.
     */
    private function createGetOperation(object $schema): array
    {
        // Get schema name for components reference.
        $schemaName = 'UnknownSchema';
        if (($schema->getTitle() !== null) === true && ($schema->getTitle() !== '') === true) {
            $schemaName = $schema->getTitle();
        }

        return [
            'summary'     => 'Get a '.$schema->getTitle().' object by ID',
            'operationId' => 'get'.$this->pascalCase(string: $schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Retrieve a specific '.$schema->getTitle().' object by its unique identifier',
            'parameters'  => array_merge(
                [
                    [
                        'name'        => 'id',
                        'in'          => 'path',
                        'required'    => true,
                        'description' => 'Unique identifier of the '.$schema->getTitle().' object',
                        'schema'      => [
                            'type'   => 'string',
                            'format' => 'uuid',
                        ],
                    ],
                ],
                $this->createCommonQueryParameters()
            ),
            'responses'   => [
                '200' => [
                    'description' => $schema->getTitle().' found.',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName(title: $schemaName),
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }//end createGetOperation()

    /**
     * Create PUT operation
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for PUT.
     */
    private function createPutOperation(object $schema): array
    {
        // Determine schema name for use in schema references.
        $schemaName = 'UnknownSchema';
        if (($schema->getTitle() !== null && $schema->getTitle() !== '') === true) {
            $schemaName = $schema->getTitle();
        }

        return [
            'summary'     => 'Update a '.$schema->getTitle().' object',
            'operationId' => 'update'.$this->pascalCase(string: $schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Update an existing '.$schema->getTitle().' object with the provided data',
            'parameters'  => array_merge(
                [
                    [
                        'name'        => 'id',
                        'in'          => 'path',
                        'required'    => true,
                        'description' => 'Unique identifier of the '.$schema->getTitle().' object to update',
                        'schema'      => [
                            'type'   => 'string',
                            'format' => 'uuid',
                        ],
                    ],
                ],
                $this->createCommonQueryParameters()
            ),
            'requestBody' => [
                'required' => true,
                'content'  => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName(title: $schemaName),
                        ],
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => $schema->getTitle().' updated successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName(title: $schemaName),
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }//end createPutOperation()

    /**
     * Create POST operation.
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for POST.
     */
    private function createPostOperation(object $schema): array
    {
        // Determine schema name for use in schema references.
        $schemaName = 'UnknownSchema';
        if (($schema->getTitle() !== null && $schema->getTitle() !== '') === true) {
            $schemaName = $schema->getTitle();
        }

        return [
            'summary'     => 'Create a new '.$schema->getTitle().' object',
            'operationId' => 'create'.$this->pascalCase(string: $schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Create a new '.$schema->getTitle().' object with the provided data',
            'parameters'  => $this->createCommonQueryParameters(),
            'requestBody' => [
                'required' => true,
                'content'  => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName(title: $schemaName),
                        ],
                    ],
                ],
            ],
            'responses'   => [
                '201' => [
                    'description' => $schema->getTitle().' created successfully.',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName(title: $schemaName),
                            ],
                        ],
                    ],
                ],
                '400' => [
                    'description' => 'Invalid request body',
                    'content'     => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Error'],
                        ],
                    ],
                ],
            ],
        ];
    }//end createPostOperation()

    /**
     * Create DELETE operation
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for DELETE.
     */
    private function createDeleteOperation(object $schema): array
    {
        return [
            'summary'     => 'Delete a '.$schema->getTitle().' object',
            'operationId' => 'delete'.$this->pascalCase(string: $schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Delete a specific '.$schema->getTitle().' object by its unique identifier',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object to delete',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '204' => [
                    'description' => $schema->getTitle().' deleted successfully',
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }//end createDeleteOperation()

    /**
     * Create logs operation
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for logs endpoint.
     */
    private function createLogsOperation(object $schema): array
    {
        return [
            'summary'     => 'Get audit logs for a '.$schema->getTitle().' object',
            'operationId' => 'getLogs'.$this->pascalCase(string: $schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Retrieve the audit trail for a specific '.$schema->getTitle().' object',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => 'Audit logs retrieved successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'  => 'array',
                                'items' => [
                                    '$ref' => '#/components/schemas/AuditTrail',
                                ],
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }//end createLogsOperation()

    /**
     * Create get files operation
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for get files endpoint.
     */
    private function createGetFilesOperation(object $schema): array
    {
        return [
            'summary'     => 'Get files for a '.$schema->getTitle().' object',
            'operationId' => 'getFiles'.$this->pascalCase(string: $schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Retrieve all files associated with a specific '.$schema->getTitle().' object',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => 'Files retrieved successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'  => 'array',
                                'items' => [
                                    '$ref' => '#/components/schemas/File',
                                ],
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }//end createGetFilesOperation()

    /**
     * Create post file operation
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for post file endpoint.
     */
    private function createPostFileOperation(object $schema): array
    {
        return [
            'summary'     => 'Upload a file for a '.$schema->getTitle().' object',
            'operationId' => 'uploadFile'.$this->pascalCase(string: $schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Upload a new file and associate it with a specific '.$schema->getTitle().' object',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'requestBody' => [
                'required' => true,
                'content'  => [
                    'multipart/form-data' => [
                        'schema' => [
                            'type'       => 'object',
                            'properties' => [
                                'file' => [
                                    'type'        => 'string',
                                    'format'      => 'binary',
                                    'description' => 'The file to upload',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses'   => [
                '201' => [
                    'description' => 'File uploaded successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/File',
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }//end createPostFileOperation()

    /**
     * Create lock operation
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for lock endpoint.
     */
    private function createLockOperation(object $schema): array
    {
        return [
            'summary'     => 'Lock a '.$schema->getTitle().' object',
            'operationId' => 'lock'.$this->pascalCase(string: $schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Lock a specific '.$schema->getTitle().' object to prevent concurrent modifications',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object to lock',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => 'Object locked successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Lock',
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
                '409' => [
                    'description' => 'Object is already locked',
                ],
            ],
        ];
    }//end createLockOperation()

    /**
     * Create unlock operation
     *
     * @param object $schema The schema object
     *
     * @return array OpenAPI operation definition for unlock endpoint.
     */
    private function createUnlockOperation(object $schema): array
    {
        return [
            'summary'     => 'Unlock a '.$schema->getTitle().' object',
            'operationId' => 'unlock'.$this->pascalCase(string: $schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Remove the lock from a specific '.$schema->getTitle().' object',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object to unlock',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => 'Object unlocked successfully',
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
                '409' => [
                    'description' => 'Object is not locked or locked by another user',
                ],
            ],
        ];
    }//end createUnlockOperation()

    /**
     * Convert string to slug
     *
     * @param string $string The string to convert
     *
     * @return string The slugified string
     */
    private function slugify(string $string): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    }//end slugify()

    /**
     * Convert string to PascalCase
     *
     * @param string $string The string to convert
     *
     * @return string The PascalCase string
     */
    private function pascalCase(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $this->slugify(string: $string))));
    }//end pascalCase()

    /**
     * Sanitize schema names to be OpenAPI compliant
     *
     * OpenAPI schema names must match pattern ^[a-zA-Z0-9._-]+$
     * This method converts titles with spaces and special characters to valid schema names.
     *
     * @param string|null $title The schema title to sanitize
     *
     * @return string The sanitized schema name
     */
    private function sanitizeSchemaName(?string $title): string
    {
        // Handle null or empty titles.
        if (empty($title) === true) {
            return 'UnknownSchema';
        }

        // Replace spaces and invalid characters with underscores.
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $title);

        // Remove multiple consecutive underscores.
        $sanitized = preg_replace('/_+/', '_', $sanitized);

        // Remove leading/trailing underscores.
        $sanitized = trim($sanitized, '_');

        // Handle edge case where sanitization results in empty string.
        if (empty($sanitized) === true) {
            return 'UnknownSchema';
        }

        // Ensure it starts with a letter (prepend 'Schema_' if it starts with number).
        if (preg_match('/^[0-9]/', $sanitized) === true) {
            $sanitized = 'Schema_'.$sanitized;
        }

        return $sanitized;
    }//end sanitizeSchemaName()

    /**
     * Validate OpenAPI specification integrity
     *
     * This method checks for common issues that could cause ReDoc or other
     * OpenAPI tools to fail when parsing the specification.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple nested loops and conditional checks for validating
     *                                               paths, responses, and schemas
     */
    private function validateOasIntegrity(): void
    {
        // Pass 1: $ref / allOf integrity inside component schemas.
        if (isset($this->oas['components']['schemas']) === true) {
            $schemaNames = array_keys($this->oas['components']['schemas']);
            foreach ($schemaNames as $schemaName) {
                if (is_array($this->oas['components']['schemas'][$schemaName]) === true) {
                    $this->validateSchemaReferences(
                        schema: $this->oas['components']['schemas'][$schemaName],
                        context: 'components.schemas.'.$schemaName
                    );
                }
            }
        }

        // Pass 2: $ref integrity inside path response schemas.
        if (isset($this->oas['paths']) === true) {
            $pathNames = array_keys($this->oas['paths']);
            foreach ($pathNames as $pathName) {
                $methods = array_keys($this->oas['paths'][$pathName]);
                foreach ($methods as $method) {
                    $operation = &$this->oas['paths'][$pathName][$method];
                    if (is_array($operation) === true && isset($operation['responses']) === true) {
                        $statusCodes = array_keys($operation['responses']);
                        foreach ($statusCodes as $statusCode) {
                            $respContent = ($operation['responses'][$statusCode]['content'] ?? []);
                            $respSchema  = ($respContent['application/json']['schema'] ?? null);
                            if ($respSchema !== null) {
                                $this->validateSchemaReferences(
                                    schema: $respSchema,
                                    context: 'paths.'.$pathName.'.'.$method.'.responses.'.$statusCode
                                );
                            }
                        }
                    }

                    unset($operation);
                }
            }//end foreach
        }//end if

        // Pass 3: server URL must be absolute.
        $this->validateServerUrls();

        // Pass 4: operationId uniqueness with auto-suffix de-duplication.
        $this->validateOperationIdUniqueness();

        // Pass 5: tag consistency — referenced tags must be defined; defined tags must be used.
        $this->validateTagConsistency();

        // Pass 6: NLGov rules — HTTP method whitelist (API-01) and status code whitelist (API-03).
        $this->validateNlGovRules();
    }//end validateOasIntegrity()

    /**
     * Verify every entry in `servers` uses an absolute URL.
     *
     * @return void
     */
    private function validateServerUrls(): void
    {
        $servers = ($this->oas['servers'] ?? []);
        if (is_array($servers) === false) {
            return;
        }

        foreach ($servers as $idx => $server) {
            $url = (string) ($server['url'] ?? '');
            if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
                $this->report->addError(
                    path: 'servers.'.$idx.'.url',
                    message: 'Server URL must be absolute (http:// or https://). Got: '.$url,
                    code: OasValidationReport::CODE_RELATIVE_SERVER_URL,
                );
            }
        }
    }//end validateServerUrls()

    /**
     * Walk every operation and ensure operationIds are unique. Collisions are
     * deduplicated in place by appending a numeric suffix (`_2`, `_3`, ...).
     *
     * @return void
     */
    private function validateOperationIdUniqueness(): void
    {
        if (isset($this->oas['paths']) === false || is_array($this->oas['paths']) === false) {
            return;
        }

        $seen = [];
        foreach ($this->oas['paths'] as $pathName => &$pathItem) {
            if (is_array($pathItem) === false) {
                continue;
            }

            foreach ($pathItem as $method => &$operation) {
                if (is_array($operation) === false || isset($operation['operationId']) === false) {
                    continue;
                }

                $original = (string) $operation['operationId'];
                if ($original === '') {
                    continue;
                }

                if (isset($seen[$original]) === false) {
                    $seen[$original] = 1;
                    continue;
                }

                // Collision — auto-suffix until unique.
                $seen[$original]++;
                $candidate = $original.'_'.$seen[$original];
                while (isset($seen[$candidate]) === true) {
                    $seen[$original]++;
                    $candidate = $original.'_'.$seen[$original];
                }

                $seen[$candidate] = 1;

                $operation['operationId'] = $candidate;
                $this->report->addAutoCorrection(
                    path: 'paths.'.$pathName.'.'.$method.'.operationId',
                    message: 'Duplicate operationId "'.$original.'" auto-renamed to "'.$candidate.'".',
                    code: OasValidationReport::CODE_DUPLICATE_OPERATION_ID,
                );
            }//end foreach

            unset($operation);
        }//end foreach

        unset($pathItem);
    }//end validateOperationIdUniqueness()

    /**
     * Cross-check declared `tags` against tag references in path operations.
     *
     * - Every tag referenced by an operation MUST exist in the top-level tags array
     *   (orphan tag → auto-injected with a generated description, warning logged).
     * - Every tag defined at the top level SHOULD be referenced by at least one
     *   operation (unused tag → warning only, not auto-removed).
     *
     * @return void
     */
    private function validateTagConsistency(): void
    {
        $declaredTags = [];
        foreach (($this->oas['tags'] ?? []) as $idx => $tagDef) {
            if (is_array($tagDef) === true && isset($tagDef['name']) === true) {
                $declaredTags[(string) $tagDef['name']] = $idx;
            }
        }

        $usedTags = [];
        foreach (($this->oas['paths'] ?? []) as $pathName => $pathItem) {
            if (is_array($pathItem) === false) {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (is_array($operation) === false) {
                    continue;
                }

                foreach (($operation['tags'] ?? []) as $tagName) {
                    if (is_string($tagName) === false || $tagName === '') {
                        continue;
                    }

                    $usedTags[$tagName][] = 'paths.'.$pathName.'.'.$method;
                }
            }
        }

        // Orphan tags — used in operations but not declared.
        foreach ($usedTags as $tagName => $usages) {
            if (isset($declaredTags[$tagName]) === true) {
                continue;
            }

            $this->oas['tags'][] = [
                'name'        => $tagName,
                'description' => 'Operations for '.$tagName,
            ];
            $this->report->addAutoCorrection(
                path: $usages[0],
                message: 'Tag "'.$tagName.'" used by operations but not declared in top-level tags; auto-added.',
                code: OasValidationReport::CODE_ORPHAN_TAG,
            );
        }

        // Unused tags — declared but never referenced.
        foreach (array_keys($declaredTags) as $tagName) {
            if (isset($usedTags[$tagName]) === true) {
                continue;
            }

            $this->report->addWarning(
                path: 'tags['.$declaredTags[$tagName].'].name',
                message: 'Top-level tag "'.$tagName.'" is declared but not referenced by any operation.',
                code: OasValidationReport::CODE_UNUSED_TAG,
            );
        }
    }//end validateTagConsistency()

    /**
     * NLGov API Design Rules — narrow checks that are verifiable from the
     * OAS document alone:
     *
     * - API-01: only GET, POST, PUT, DELETE on documented operations.
     * - API-03: only standard HTTP status codes on responses.
     *
     * @return void
     */
    private function validateNlGovRules(): void
    {
        if (isset($this->oas['paths']) === false || is_array($this->oas['paths']) === false) {
            return;
        }

        $allowedMethods = array_flip(self::ALLOWED_HTTP_METHODS);
        $allowedCodes   = array_flip(self::ALLOWED_STATUS_CODES);

        foreach ($this->oas['paths'] as $pathName => $pathItem) {
            if (is_array($pathItem) === false) {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                $methodKey = strtolower((string) $method);
                if (isset($allowedMethods[$methodKey]) === false) {
                    $reason  = 'violates NLGov API-01 (only GET, POST, PUT, DELETE allowed).';
                    $message = sprintf('Non-standard HTTP method "%s" %s', (string) $method, $reason);
                    $this->report->addError(
                        path: 'paths.'.$pathName.'.'.$method,
                        message: $message,
                        code: OasValidationReport::CODE_INVALID_HTTP_METHOD,
                    );
                    continue;
                }

                if ($methodKey === 'parameters' || is_array($operation) === false) {
                    continue;
                }

                foreach (($operation['responses'] ?? []) as $statusCode => $_response) {
                    $statusKey = (string) $statusCode;
                    if (isset($allowedCodes[$statusKey]) === false) {
                        $this->report->addWarning(
                            path: 'paths.'.$pathName.'.'.$method.'.responses.'.$statusCode,
                            message: 'Non-standard HTTP status code "'.$statusCode.'" violates NLGov API-03 conventions.',
                            code: OasValidationReport::CODE_INVALID_STATUS_CODE,
                        );
                    }
                }
            }//end foreach
        }//end foreach
    }//end validateNlGovRules()

    /**
     * Validate schema references recursively
     *
     * @param array<string, mixed> $schema  The schema to validate (passed by reference for modifications)
     * @param string               $context Context information for debugging
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Recursive schema validation with multiple reference types
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple conditional paths for allOf, $ref, and nested validation
     */
    private function validateSchemaReferences(array &$schema, string $context): void
    {
        // Check allOf constructs.
        if (isset($schema['allOf']) === true) {
            if (is_array($schema['allOf']) === false || empty($schema['allOf']) === true) {
                unset($schema['allOf']);
            }

            if (isset($schema['allOf']) === true && is_array($schema['allOf']) === true) {
                $validAllOfItems = [];
                foreach ($schema['allOf'] as $index => $item) {
                    // Suppress unused variable warning for $index - only processing items.
                    unset($index);
                    if (is_array($item) === false || empty($item) === true) {
                        continue;
                    }

                    // Validate each allOf item has required structure.
                    $hasValidRef = ($item['$ref'] ?? null) !== null
                        && empty($item['$ref']) === false
                        && is_string($item['$ref']) === true;
                    if ($hasValidRef === true) {
                        $validAllOfItems[] = $item;
                        continue;
                    }

                    if (($item['type'] ?? null) !== null || (($item['properties'] ?? null) !== null) === true) {
                        $validAllOfItems[] = $item;
                    }
                }

                // If no valid items remain, remove allOf.
                if (empty($validAllOfItems) === true) {
                    unset($schema['allOf']);
                    $this->report->addAutoCorrection(
                        path: $context.'.allOf',
                        message: 'allOf with no valid items was removed.',
                        code: OasValidationReport::CODE_INVALID_ALLOF,
                    );
                }

                if (empty($validAllOfItems) === false) {
                    $schema['allOf'] = $validAllOfItems;
                }
            }//end if
        }//end if

        // Check $ref validity.
        if (($schema['$ref'] ?? null) !== null) {
            if (empty($schema['$ref']) === true || is_string($schema['$ref']) === false) {
                unset($schema['$ref']);
            }

            if (isset($schema['$ref']) === true && is_string($schema['$ref']) === true) {
                // Check if reference points to existing schema.
                $refPath = str_replace('#/components/schemas/', '', $schema['$ref']);
                if (strpos($schema['$ref'], '#/components/schemas/') === 0
                    && isset($this->oas['components']['schemas'][$refPath]) === false
                ) {
                    // Try case-insensitive match against existing component names.
                    $resolved = false;
                    foreach (array_keys($this->oas['components']['schemas'] ?? []) as $existingName) {
                        if (strtolower($existingName) === strtolower($refPath)) {
                            $schema['$ref'] = '#/components/schemas/'.$existingName;
                            $resolved       = true;
                            break;
                        }
                    }

                    // If no match found, remove the broken $ref and fall back to string type.
                    if ($resolved === false) {
                        unset($schema['$ref']);
                        if (isset($schema['type']) === false) {
                            $schema['type']        = 'string';
                            $schema['description'] = $schema['description'] ?? 'Reference to '.$refPath;
                        }

                        $this->report->addError(
                            path: $context.'.$ref',
                            message: 'Dangling $ref to "#/components/schemas/'.$refPath.'"; substituted with type=string.',
                            code: OasValidationReport::CODE_DANGLING_REF,
                        );
                    }
                }//end if
            }//end if
        }//end if

        // Recursively check nested schemas (by reference so fixes are applied).
        if (($schema['properties'] ?? null) !== null) {
            foreach ($schema['properties'] as $propName => &$property) {
                if (is_array($property) === true) {
                    $this->validateSchemaReferences(schema: $property, context: "{$context}.properties.{$propName}");
                }
            }

            unset($property);
        }

        if (($schema['items'] ?? null) !== null && is_array($schema['items']) === true) {
            $this->validateSchemaReferences(schema: $schema['items'], context: "{$context}.items");
        }

        // Recursively check oneOf/anyOf/allOf items for broken refs.
        foreach (['oneOf', 'anyOf', 'allOf'] as $compositionKey) {
            if (($schema[$compositionKey] ?? null) !== null && is_array($schema[$compositionKey]) === true) {
                foreach ($schema[$compositionKey] as $idx => &$compositionItem) {
                    if (is_array($compositionItem) === true) {
                        $this->validateSchemaReferences(
                            schema: $compositionItem,
                            context: "{$context}.{$compositionKey}[{$idx}]"
                        );
                    }
                }

                unset($compositionItem);
            }
        }
    }//end validateSchemaReferences()

    /**
     * Resolve the effective authorization for a schema in the OAS context.
     *
     * If the schema has its own authorization block, use it.
     * Otherwise, fall back to the parent register's authorization.
     * Also expands role references to action-level permissions.
     *
     * @param object $schema The schema object.
     *
     * @return array|null The effective authorization array.
     */
    private function resolveEffectiveAuthorization(object $schema): ?array
    {
        $authorization = $schema->getAuthorization();

        // If schema has its own authorization, expand roles and return.
        if (is_array($authorization) === true && empty($authorization) === false) {
            return $this->expandRolesForOas(authorization: $authorization, schema: $schema);
        }

        // Fall back to register authorization.
        try {
            $registerId = $this->registerMapper->getFirstRegisterWithSchema(schemaId: $schema->getId());
            if ($registerId !== null) {
                $register     = $this->registerMapper->find(id: $registerId);
                $registerAuth = $register->getAuthorization();
                if (is_array($registerAuth) === true && empty($registerAuth) === false) {
                    return $this->expandRolesForOas(authorization: $registerAuth, schema: $schema, register: $register);
                }
            }
        } catch (\Throwable $e) {
            // Fallback: no register authorization available.
        }

        return null;
    }//end resolveEffectiveAuthorization()

    /**
     * Expand role references in authorization for OAS scope generation.
     *
     * @param array       $authorization The authorization block.
     * @param object      $schema        The schema object.
     * @param object|null $register      The register object (optional, looked up if needed).
     *
     * @return array The authorization with roles expanded.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function expandRolesForOas(array $authorization, object $schema, ?object $register=null): array
    {
        if (isset($authorization['roles']) === false || is_array($authorization['roles']) === false) {
            return $authorization;
        }

        $roleAssignments = $authorization['roles'];
        unset($authorization['roles']);

        // Get register for role definitions.
        if ($register === null) {
            try {
                $registerId = $this->registerMapper->getFirstRegisterWithSchema($schema->getId());
                if ($registerId !== null) {
                    $register = $this->registerMapper->find($registerId);
                }
            } catch (\Throwable $e) {
                return $authorization;
            }
        }

        if ($register === null) {
            return $authorization;
        }

        $config = $register->getConfiguration();
        $roles  = $config['roles'] ?? [];
        if (empty($roles) === true) {
            return $authorization;
        }

        // Build role map.
        $roleMap = [];
        foreach ($roles as $roleDef) {
            if (isset($roleDef['name']) === true && isset($roleDef['actions']) === true) {
                $roleMap[$roleDef['name']] = $roleDef['actions'];
            }
        }

        // Expand roles to action-level entries.
        foreach ($roleAssignments as $roleName => $groups) {
            if (isset($roleMap[$roleName]) === false) {
                continue;
            }

            foreach ($roleMap[$roleName] as $action) {
                if (isset($authorization[$action]) === false) {
                    $authorization[$action] = [];
                }

                foreach ((array) $groups as $group) {
                    if (in_array($group, $authorization[$action], true) === false) {
                        $authorization[$action][] = $group;
                    }
                }
            }
        }

        return $authorization;
    }//end expandRolesForOas()
}//end class
