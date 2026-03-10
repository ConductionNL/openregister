<?php

/**
 * OpenRegister ReferentialIntegrityService
 *
 * Service responsible for enforcing referential integrity when objects are deleted.
 * Builds a relation index from schema definitions, walks the deletion graph to detect
 * blockers (RESTRICT) and cascade targets, and applies mutations (CASCADE, SET_NULL,
 * SET_DEFAULT) when deletion proceeds.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Referential integrity requires coordination with schema, object, and mapper services
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service for referential integrity enforcement on object deletion.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Core referential integrity algorithm handles 5 action types
 */
class ReferentialIntegrityService
{
    /**
     * Maximum depth for graph walking to prevent infinite recursion in pathological configs.
     *
     * @var int
     */
    private const MAX_DEPTH = 10;

    /**
     * Valid onDelete action values.
     *
     * @var string[]
     */
    public const VALID_ON_DELETE_ACTIONS = [
        'CASCADE',
        'RESTRICT',
        'SET_NULL',
        'SET_DEFAULT',
        'NO_ACTION',
    ];

    /**
     * Cached relation index: target schema ID => array of dependent relations.
     * Built once per request and reused for batch operations.
     *
     * @var array|null
     */
    private ?array $relationIndex = null;

    /**
     * Cached schema map: schema ID => Schema entity.
     *
     * @var array|null
     */
    private ?array $schemaCache = null;

    /**
     * Cached schema-to-register mapping: schema ID => Register entity.
     *
     * @var array|null
     */
    private ?array $schemaRegisterMap = null;

    /**
     * Constructor for ReferentialIntegrityService.
     *
     * @param SchemaMapper       $schemaMapper       Schema data mapper.
     * @param ObjectEntityMapper $objectEntityMapper Object entity data mapper.
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper for integrity action logging.
     * @param LoggerInterface    $logger             Logger for debugging.
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Analyze whether an object can be deleted without violating referential integrity.
     *
     * Walks the full deletion graph without performing any mutations.
     *
     * @param ObjectEntity $object The object to analyze for deletion.
     *
     * @return DeletionAnalysis The analysis result with targets and blockers.
     */
    public function canDelete(ObjectEntity $object): DeletionAnalysis
    {
        $this->ensureRelationIndex();

        $schemaId = $object->getSchema();
        if ($schemaId === null) {
            return DeletionAnalysis::empty();
        }

        // Quick check: if no schemas reference this object's schema with onDelete config, skip.
        if (isset($this->relationIndex[$schemaId]) === false) {
            return DeletionAnalysis::empty();
        }

        $visited = [];
        return $this->walkDeletionGraph(object: $object, visited: $visited);
    }//end canDelete()

    /**
     * Apply the deletion actions from a pre-computed analysis.
     *
     * Execution order: SET_NULL → SET_DEFAULT → CASCADE (deepest first).
     *
     * @param DeletionAnalysis $analysis          The pre-computed deletion analysis.
     * @param string           $userId            The user performing the deletion.
     * @param string           $cascadeSource     The UUID of the root object being deleted.
     * @param string|null      $organisationId    The active organisation ID.
     * @param string|null      $triggerSchemaSlug Slug of the schema of the deleted object (for audit trail).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple action types require distinct handling paths
     */
    public function applyDeletionActions(
        DeletionAnalysis $analysis,
        string $userId,
        string $cascadeSource,
        ?string $organisationId = null,
        ?string $triggerSchemaSlug = null
    ): void {
        // 1. Apply SET_NULL targets first (objects survive with cleared reference).
        foreach ($analysis->nullifyTargets as $target) {
            $this->applySetNull(target: $target);
            $this->logIntegrityAction(
                action: 'referential_integrity.set_null',
                objectUuid: $target['objectUuid'],
                schemaId: $target['schema'] ?? null,
                registerId: null,
                changed: [
                    'property'      => $target['property'],
                    'previousValue' => $target['sourceUuid'] ?? null,
                    'newValue'      => null,
                    'triggerObject' => $cascadeSource,
                    'triggerSchema' => $triggerSchemaSlug,
                ],
                userId: $userId
            );
        }

        // 2. Apply SET_DEFAULT targets (objects survive with default reference).
        foreach ($analysis->defaultTargets as $target) {
            $this->applySetDefault(target: $target);
            $this->logIntegrityAction(
                action: 'referential_integrity.set_default',
                objectUuid: $target['objectUuid'],
                schemaId: $target['schema'] ?? null,
                registerId: null,
                changed: [
                    'property'      => $target['property'],
                    'previousValue' => $cascadeSource,
                    'defaultValue'  => $target['defaultValue'] ?? null,
                    'triggerObject' => $cascadeSource,
                    'triggerSchema' => $triggerSchemaSlug,
                ],
                userId: $userId
            );
        }

        // 3. Apply CASCADE targets in batch (deepest first = reverse order).
        $cascadeTargets = array_reverse($analysis->cascadeTargets);

        if (empty($cascadeTargets) === false) {
            $this->applyBatchCascadeDelete(
                cascadeTargets: $cascadeTargets,
                userId: $userId,
                cascadeSource: $cascadeSource,
                triggerSchemaSlug: $triggerSchemaSlug
            );
        }
    }//end applyDeletionActions()

    /**
     * Log a RESTRICT block event to the audit trail.
     *
     * Called when a deletion is prevented by RESTRICT constraints. Records the
     * blocked object and the blockers that prevented deletion.
     *
     * @param string           $objectUuid The UUID of the object that could not be deleted.
     * @param string|null      $schemaId   Schema ID of the blocked object.
     * @param DeletionAnalysis $analysis   The analysis containing blocker information.
     * @param string           $userId     The user who attempted the deletion.
     *
     * @return void
     */
    public function logRestrictBlock(
        string $objectUuid,
        ?string $schemaId,
        DeletionAnalysis $analysis,
        string $userId
    ): void {
        $blockerSchemas = [];
        $blockerProps   = [];
        foreach ($analysis->blockers as $blocker) {
            $blockerSchemas[] = $blocker['schema'] ?? 'unknown';
            $blockerProps[]   = $blocker['property'] ?? 'unknown';
        }

        $uniqueSchemas = array_values(array_unique($blockerSchemas));
        $uniqueProps   = array_values(array_unique($blockerProps));

        $this->logIntegrityAction(
            action: 'referential_integrity.restrict_blocked',
            objectUuid: $objectUuid,
            schemaId: $schemaId,
            registerId: null,
            changed: [
                'blockerCount'    => count($analysis->blockers),
                'blockerSchema'   => $uniqueSchemas[0] ?? 'unknown',
                'blockerProperty' => $uniqueProps[0] ?? 'unknown',
                'reason'          => 'RESTRICT constraint prevents deletion',
            ],
            userId: $userId
        );
    }//end logRestrictBlock()

    /**
     * Check if a schema has any incoming onDelete references from other schemas.
     *
     * Used as a fast check to skip referential integrity analysis entirely
     * for schemas that no other schema cares about.
     *
     * @param string $schemaId The schema ID to check.
     *
     * @return bool True if any schema has onDelete config referencing this schema.
     */
    public function hasIncomingOnDeleteReferences(string $schemaId): bool
    {
        $this->ensureRelationIndex();
        return isset($this->relationIndex[$schemaId]);
    }//end hasIncomingOnDeleteReferences()

    /**
     * Validate an onDelete value on a schema property.
     *
     * @param string $value The onDelete value to validate.
     *
     * @return bool True if the value is valid.
     */
    public static function isValidOnDeleteAction(string $value): bool
    {
        return in_array(strtoupper($value), self::VALID_ON_DELETE_ACTIONS, true);
    }//end isValidOnDeleteAction()

    /**
     * Build the relation index from all schemas if not already cached.
     *
     * The index maps: target schema ID → [{sourceSchemaId, property, onDelete, isArray, sourceSchemaSlug}]
     *
     * @return void
     */
    private function ensureRelationIndex(): void
    {
        if ($this->relationIndex !== null) {
            return;
        }

        $this->relationIndex   = [];
        $this->schemaCache     = [];
        $this->schemaRegisterMap = [];

        try {
            $allSchemas = $this->schemaMapper->findAll(
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[ReferentialIntegrity] Failed to load schemas for relation index',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return;
        }

        // Build schema-to-register map by scanning magic table names.
        // Tables follow convention: openregister_table_{registerId}_{schemaId}.
        try {
            $allRegisters = $this->registerMapper->findAll(
                _rbac: false,
                _multitenancy: false
            );
            $registerCache = [];
            foreach ($allRegisters as $register) {
                $registerCache[(string) $register->getId()] = $register;
            }

            $db     = \OC::$server->getDatabaseConnection();
            $stmt   = $db->prepare(
                "SELECT table_name FROM information_schema.tables "
                . "WHERE table_name LIKE 'oc_openregister_table_%' AND table_schema = current_schema()"
            );
            $stmt->execute();
            $tables = [];
            while ($row = $stmt->fetch()) {
                // Strip oc_ prefix to match naming convention.
                $tables[] = substr($row['table_name'], 3);
            }
            foreach ($tables as $tableName) {
                // Parse: openregister_table_{registerId}_{schemaId}.
                if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $tableName, $m) === 1) {
                    $regId    = $m[1];
                    $schemaId = $m[2];
                    if (isset($registerCache[$regId]) === true
                        && isset($this->schemaRegisterMap[$schemaId]) === false
                    ) {
                        $this->schemaRegisterMap[$schemaId] = $registerCache[$regId];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                message: '[ReferentialIntegrity] Failed to build schema-register map',
                context: ['error' => $e->getMessage()]
            );
        }

        foreach ($allSchemas as $schema) {
            $schemaId = (string) $schema->getId();
            $this->schemaCache[$schemaId] = $schema;

            $properties = $schema->getProperties();
            if ($properties === null) {
                continue;
            }

            foreach ($properties as $propertyName => $property) {
                $onDelete = $this->extractOnDelete(property: $property);
                if ($onDelete === null || $onDelete === 'NO_ACTION') {
                    continue;
                }

                $targetRef = $this->extractTargetRef(property: $property);
                if ($targetRef === null) {
                    continue;
                }

                // Resolve target $ref to a schema ID.
                $targetSchemaId = $this->resolveSchemaRef(ref: $targetRef, allSchemas: $allSchemas);
                if ($targetSchemaId === null) {
                    continue;
                }

                $isArray = isset($property['type']) && $property['type'] === 'array';

                if (isset($this->relationIndex[$targetSchemaId]) === false) {
                    $this->relationIndex[$targetSchemaId] = [];
                }

                $this->relationIndex[$targetSchemaId][] = [
                    'sourceSchemaId' => $schemaId,
                    'property'       => $propertyName,
                    'onDelete'       => $onDelete,
                    'isArray'        => $isArray,
                ];
            }//end foreach
        }//end foreach
    }//end ensureRelationIndex()

    /**
     * Extract the onDelete action from a property definition.
     *
     * @param array $property The property configuration array.
     *
     * @return string|null The uppercase onDelete action, or null if not set.
     */
    private function extractOnDelete(array $property): ?string
    {
        if (isset($property['onDelete']) === false) {
            return null;
        }

        return strtoupper((string) $property['onDelete']);
    }//end extractOnDelete()

    /**
     * Extract the target schema reference from a property definition.
     *
     * Handles both direct $ref and array items.$ref.
     *
     * @param array $property The property configuration array.
     *
     * @return string|null The $ref value, or null if not a relation property.
     */
    private function extractTargetRef(array $property): ?string
    {
        // Direct $ref on the property.
        if (isset($property['$ref']) === true) {
            return (string) $property['$ref'];
        }

        // Array items with $ref.
        if (isset($property['items']['$ref']) === true) {
            return (string) $property['items']['$ref'];
        }

        return null;
    }//end extractTargetRef()

    /**
     * Resolve a schema $ref string to a schema ID.
     *
     * References can be slugs, UUIDs, URLs, or numeric IDs.
     *
     * @param string $ref        The $ref value to resolve.
     * @param array  $allSchemas All loaded schemas for matching.
     *
     * @return string|null The resolved schema ID, or null if not found.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple resolution strategies needed
     */
    private function resolveSchemaRef(string $ref, array $allSchemas): ?string
    {
        // Strip leading path components (e.g., "/schemas/my-slug" → "my-slug").
        $refClean = basename($ref);

        foreach ($allSchemas as $schema) {
            $id   = (string) $schema->getId();
            $slug = $schema->getSlug();
            $uuid = $schema->getUuid();

            // Match by ID, slug, or UUID.
            if ($id === $ref || $id === $refClean) {
                return $id;
            }

            if ($slug !== null && ($slug === $ref || $slug === $refClean)) {
                return $id;
            }

            if ($uuid !== null && ($uuid === $ref || $uuid === $refClean)) {
                return $id;
            }
        }

        return null;
    }//end resolveSchemaRef()

    /**
     * Walk the deletion graph recursively to build a DeletionAnalysis.
     *
     * @param ObjectEntity $object  The object being analyzed for deletion.
     * @param array        $visited Array of visited UUIDs for cycle detection (passed by reference).
     * @param array        $chain   The current chain path for debugging.
     * @param int          $depth   Current recursion depth.
     *
     * @return DeletionAnalysis The analysis for this object and its dependents.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Graph walking requires many conditional paths per action type
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple action types and fallback chains create many paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Core algorithm that handles all 5 action types inline
     */
    private function walkDeletionGraph(
        ObjectEntity $object,
        array &$visited,
        array $chain = [],
        int $depth = 0
    ): DeletionAnalysis {
        // Cycle detection.
        $uuid = $object->getUuid();
        if (in_array($uuid, $visited, true) === true) {
            return DeletionAnalysis::empty();
        }

        // Depth limit.
        if ($depth >= self::MAX_DEPTH) {
            $this->logger->warning(
                message: '[ReferentialIntegrity] Max depth reached during graph walk',
                context: ['uuid' => $uuid, 'depth' => $depth]
            );
            return DeletionAnalysis::empty();
        }

        $visited[] = $uuid;

        $schemaId = $object->getSchema();
        if ($schemaId === null || isset($this->relationIndex[$schemaId]) === false) {
            return DeletionAnalysis::empty();
        }

        $cascadeTargets = [];
        $nullifyTargets = [];
        $defaultTargets = [];
        $blockers       = [];

        $dependents = $this->relationIndex[$schemaId];

        foreach ($dependents as $dep) {
            // Find actual objects of the dependent schema that reference this object's UUID.
            $referencingObjects = $this->findReferencingObjects(
                sourceSchemaId: $dep['sourceSchemaId'],
                propertyName: $dep['property'],
                targetUuid: $uuid,
                isArray: $dep['isArray']
            );

            foreach ($referencingObjects as $refObj) {
                // Skip already soft-deleted objects.
                if ($refObj->getDeleted() !== null && empty($refObj->getDeleted()) === false) {
                    continue;
                }

                $stepDesc     = "{$uuid} → {$refObj->getUuid()} ({$dep['onDelete']})";
                $currentChain = array_merge($chain, [$stepDesc]);

                switch ($dep['onDelete']) {
                    case 'RESTRICT':
                        $blockers[] = [
                            'objectUuid' => $refObj->getUuid(),
                            'schema'     => $dep['sourceSchemaId'],
                            'property'   => $dep['property'],
                            'action'     => 'RESTRICT',
                            'chain'      => $currentChain,
                        ];
                        break;

                    case 'CASCADE':
                        $cascadeTargets[] = [
                            'objectUuid' => $refObj->getUuid(),
                            'register'   => $refObj->getRegister(),
                            'schema'     => $dep['sourceSchemaId'],
                            'property'   => $dep['property'],
                            'chain'      => $currentChain,
                        ];

                        // Recurse: what happens if we cascade-delete this object?
                        $subAnalysis    = $this->walkDeletionGraph(
                            object: $refObj,
                            visited: $visited,
                            chain: $currentChain,
                            depth: $depth + 1
                        );
                        $blockers       = array_merge($blockers, $subAnalysis->blockers);
                        $cascadeTargets = array_merge($cascadeTargets, $subAnalysis->cascadeTargets);
                        $nullifyTargets = array_merge($nullifyTargets, $subAnalysis->nullifyTargets);
                        $defaultTargets = array_merge($defaultTargets, $subAnalysis->defaultTargets);
                        break;

                    case 'SET_NULL':
                        if (
                            $this->isRequiredProperty(
                                schemaId: $dep['sourceSchemaId'],
                                propertyName: $dep['property']
                            ) === true
                        ) {
                            // Falls back to RESTRICT.
                            $blockers[] = [
                                'objectUuid' => $refObj->getUuid(),
                                'schema'     => $dep['sourceSchemaId'],
                                'property'   => $dep['property'],
                                'action'     => 'RESTRICT',
                                'chain'      => array_merge($currentChain, ['(SET_NULL on required → RESTRICT)']),
                            ];
                        } else {
                            $nullifyTargets[] = [
                                'objectUuid' => $refObj->getUuid(),
                                'schema'     => $dep['sourceSchemaId'],
                                'property'   => $dep['property'],
                                'isArray'    => $dep['isArray'],
                                'sourceUuid' => $uuid,
                            ];
                        }
                        break;

                    case 'SET_DEFAULT':
                        $defaultValue = $this->getDefaultValue(
                            schemaId: $dep['sourceSchemaId'],
                            propertyName: $dep['property']
                        );
                        if ($defaultValue === null) {
                            // Falls back to SET_NULL → RESTRICT chain.
                            if (
                                $this->isRequiredProperty(
                                    schemaId: $dep['sourceSchemaId'],
                                    propertyName: $dep['property']
                                ) === true
                            ) {
                                $blockers[] = [
                                    'objectUuid' => $refObj->getUuid(),
                                    'schema'     => $dep['sourceSchemaId'],
                                    'property'   => $dep['property'],
                                    'action'     => 'RESTRICT',
                                    'chain'      => array_merge(
                                        $currentChain,
                                        ['(SET_DEFAULT no default + required → RESTRICT)']
                                    ),
                                ];
                            } else {
                                $nullifyTargets[] = [
                                    'objectUuid' => $refObj->getUuid(),
                                    'schema'     => $dep['sourceSchemaId'],
                                    'property'   => $dep['property'],
                                    'isArray'    => $dep['isArray'],
                                    'sourceUuid' => $uuid,
                                ];
                            }
                        } else {
                            $defaultTargets[] = [
                                'objectUuid'   => $refObj->getUuid(),
                                'schema'       => $dep['sourceSchemaId'],
                                'property'     => $dep['property'],
                                'defaultValue' => $defaultValue,
                            ];
                        }//end if
                        break;

                    default:
                        // NO_ACTION: do nothing.
                        break;
                }//end switch
            }//end foreach
        }//end foreach

        return new DeletionAnalysis(
            deletable: empty($blockers),
            cascadeTargets: $cascadeTargets,
            nullifyTargets: $nullifyTargets,
            defaultTargets: $defaultTargets,
            blockers: $blockers,
            chainPaths: $chain
        );
    }//end walkDeletionGraph()

    /**
     * Find objects of a specific schema that reference a given UUID in a specific property.
     *
     * @param string $sourceSchemaId The schema ID of the dependent objects.
     * @param string $propertyName   The property name that holds the reference.
     * @param string $targetUuid     The UUID being referenced.
     * @param bool   $isArray        Whether the property is an array type.
     *
     * @return ObjectEntity[] Matching objects.
     */
    private function findReferencingObjects(
        string $sourceSchemaId,
        string $propertyName,
        string $targetUuid,
        bool $isArray
    ): array {
        $candidates = [];

        // Optimized path: search directly in the specific magic table using the property column.
        $register = $this->schemaRegisterMap[$sourceSchemaId] ?? null;
        $schema   = $this->schemaCache[$sourceSchemaId] ?? null;

        if ($register !== null && $schema !== null) {
            try {
                $candidates = $this->findReferencingInMagicTable(
                    register: $register,
                    schema: $schema,
                    propertyName: $propertyName,
                    targetUuid: $targetUuid,
                    isArray: $isArray
                );
                // Direct match: no further filtering needed.
                return $candidates;
            } catch (\Exception $e) {
                // Table doesn't exist or column missing — no referencing objects possible.
                $this->logger->debug(
                    message: '[ReferentialIntegrity] Targeted magic table search failed',
                    context: ['schemaId' => $sourceSchemaId, 'error' => $e->getMessage()]
                );
                return [];
            }
        }

        // Fallback: broad search across all sources (only for schemas without register mapping).
        try {
            $candidates = $this->objectEntityMapper->findByRelation(
                search: $targetUuid,
                partialMatch: false,
                includeMagicTables: true
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[ReferentialIntegrity] findByRelation failed',
                context: ['uuid' => $targetUuid, 'error' => $e->getMessage()]
            );
            return [];
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            // Filter by schema.
            if ($candidate->getSchema() !== $sourceSchemaId) {
                continue;
            }

            // Filter by property: check the object data has the UUID in the specified property.
            $objectData = $candidate->getObject();
            if ($objectData === null) {
                continue;
            }

            $propertyValue = $objectData[$propertyName] ?? null;
            if ($propertyValue === null) {
                continue;
            }

            if ($isArray === true) {
                if (is_array($propertyValue) === true && in_array($targetUuid, $propertyValue, true) === true) {
                    $matches[] = $candidate;
                }
            } else {
                if ($propertyValue === $targetUuid) {
                    $matches[] = $candidate;
                }
            }
        }//end foreach

        return $matches;
    }//end findReferencingObjects()

    /**
     * Check if a property is required on a schema.
     *
     * @param string $schemaId     The schema ID.
     * @param string $propertyName The property name.
     *
     * @return bool True if the property is required.
     */
    /**
     * Search a specific magic table for objects whose property column contains the target UUID.
     *
     * Queries the property column directly (not _relations JSONB), avoiding full-table scans.
     * For scalar properties, uses an exact match; for array properties, uses JSON containment.
     *
     * @param Register $register    The register entity.
     * @param Schema   $schema      The schema entity.
     * @param string   $propertyName The property holding the reference.
     * @param string   $targetUuid  The UUID being referenced.
     * @param bool     $isArray     Whether the property is an array type.
     *
     * @return ObjectEntity[] Matching objects.
     */
    /**
     * Search a specific magic table for objects whose property column contains the target UUID.
     *
     * Queries the property column directly (not _relations JSONB), avoiding full-table scans.
     * Constructs minimal ObjectEntity objects from the results for use in cascade analysis.
     *
     * @param Register $register    The register entity.
     * @param Schema   $schema      The schema entity.
     * @param string   $propertyName The property holding the reference.
     * @param string   $targetUuid  The UUID being referenced.
     * @param bool     $isArray     Whether the property is an array type.
     *
     * @return ObjectEntity[] Matching objects.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Handles PostgreSQL/MySQL and array/scalar variants
     */
    private function findReferencingInMagicTable(
        Register $register,
        Schema $schema,
        string $propertyName,
        string $targetUuid,
        bool $isArray
    ): array {
        $fullTableName = 'oc_openregister_table_' . $register->getId() . '_' . $schema->getId();

        // Convert camelCase property name to snake_case column name and quote it.
        $columnName  = strtolower(preg_replace('/[A-Z]/', '_$0', $propertyName));
        $quotedCol   = '"' . str_replace('"', '""', $columnName) . '"';

        $db         = \OC::$server->getDatabaseConnection();
        $platform   = $db->getDatabasePlatform();
        $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

        if ($isPostgres === true) {
            $deletedCheck = "(_deleted IS NULL OR _deleted = 'null'::jsonb)";
        } else {
            $deletedCheck = '_deleted IS NULL';
        }

        if ($isArray === true) {
            if ($isPostgres === true) {
                $sql = "SELECT _uuid, _register, _schema, _deleted, {$quotedCol} AS _prop
                        FROM {$fullTableName}
                        WHERE {$deletedCheck} AND {$quotedCol}::jsonb @> to_jsonb(?::text)
                        LIMIT 100";
            } else {
                $sql = "SELECT _uuid, _register, _schema, _deleted, {$quotedCol} AS _prop
                        FROM {$fullTableName}
                        WHERE {$deletedCheck} AND JSON_CONTAINS({$quotedCol}, JSON_QUOTE(?))
                        LIMIT 100";
            }
        } else {
            $sql = "SELECT _uuid, _register, _schema, _deleted, {$quotedCol} AS _prop
                    FROM {$fullTableName}
                    WHERE {$deletedCheck} AND {$quotedCol} = ?
                    LIMIT 100";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute([$targetUuid]);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $row) {
            $entity = new ObjectEntity();
            $entity->setUuid($row['_uuid']);
            $entity->setRegister($row['_register'] ?? (string) $register->getId());
            $entity->setSchema($row['_schema'] ?? (string) $schema->getId());

            $deleted = $row['_deleted'] ?? null;
            if ($deleted !== null && $deleted !== 'null') {
                $decoded = is_string($deleted) ? json_decode($deleted, true) : $deleted;
                $entity->setDeleted(is_array($decoded) ? $decoded : []);
            }

            // Set object with at least the property that matched.
            $entity->setObject([$propertyName => $row['_prop'] ?? $targetUuid]);
            $results[] = $entity;
        }

        return $results;
    }//end findReferencingInMagicTable()

    private function isRequiredProperty(string $schemaId, string $propertyName): bool
    {
        $schema = $this->schemaCache[$schemaId] ?? null;
        if ($schema === null) {
            return false;
        }

        return in_array($propertyName, $schema->getRequired(), true);
    }//end isRequiredProperty()

    /**
     * Get the default value for a property on a schema.
     *
     * @param string $schemaId     The schema ID.
     * @param string $propertyName The property name.
     *
     * @return mixed The default value, or null if not set.
     */
    private function getDefaultValue(string $schemaId, string $propertyName): mixed
    {
        $schema = $this->schemaCache[$schemaId] ?? null;
        if ($schema === null) {
            return null;
        }

        $properties = $schema->getProperties();
        if ($properties === null || isset($properties[$propertyName]) === false) {
            return null;
        }

        return $properties[$propertyName]['default'] ?? null;
    }//end getDefaultValue()

    /**
     * Log a referential integrity action to the audit trail.
     *
     * Creates an AuditTrail entry recording what integrity action was taken,
     * on which object, triggered by what, and by whom. Failures are caught
     * and logged as warnings to avoid blocking the integrity action.
     *
     * @param string      $action     The audit action (e.g., 'referential_integrity.cascade_delete').
     * @param string      $objectUuid UUID of the affected object.
     * @param string|null $schemaId   Schema ID of the affected object.
     * @param string|null $registerId Register ID of the affected object.
     * @param array       $changed    Details of what changed.
     * @param string      $userId     The user who initiated the original deletion.
     *
     * @return void
     */
    private function logIntegrityAction(
        string $action,
        string $objectUuid,
        ?string $schemaId,
        ?string $registerId,
        array $changed,
        string $userId
    ): void {
        try {
            $auditTrail = new AuditTrail();
            $auditTrail->setUuid((string) Uuid::v4());
            $auditTrail->setObjectUuid($objectUuid);
            $auditTrail->setAction($action);
            $auditTrail->setChanged($changed);
            $auditTrail->setUser($userId);
            $auditTrail->setCreated(new DateTime());

            if ($schemaId !== null) {
                $auditTrail->setSchema((int) $schemaId);
            }

            if ($registerId !== null) {
                $auditTrail->setRegister((int) $registerId);
            }

            $auditTrail->setExpires(new DateTime('+30 days'));

            $this->auditTrailMapper->insert($auditTrail);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[ReferentialIntegrity] Failed to create audit trail entry for integrity action',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'action'     => $action,
                    'objectUuid' => $objectUuid,
                    'error'      => $e->getMessage(),
                ]
            );
        }//end try
    }//end logIntegrityAction()

    /**
     * Apply SET_NULL action: clear the reference in the dependent object.
     *
     * For array properties, removes the UUID from the array.
     * For single properties, sets the value to null.
     *
     * @param array $target The nullify target from the DeletionAnalysis.
     *
     * @return void
     */
    private function applySetNull(array $target): void
    {
        try {
            $context        = $this->objectEntityMapper->findAcrossAllSources(
                identifier: $target['objectUuid'],
                includeDeleted: false,
                _rbac: false,
                _multitenancy: false
            );
            $object         = $context['object'];
            $registerEntity = $context['register'];
            $schemaEntity   = $context['schema'];

            $objectData = $object->getObject();
            $isArray    = $target['isArray'] ?? false;

            if ($isArray === true && is_array($objectData[$target['property']] ?? null) === true) {
                // Remove the specific UUID from the array.
                $objectData[$target['property']] = array_values(
                    array_filter(
                        $objectData[$target['property']],
                        function ($val) use ($target) {
                            return $val !== $target['sourceUuid'];
                        }
                    )
                );
            } else {
                $objectData[$target['property']] = null;
            }

            $object->setObject($objectData);
            $this->objectEntityMapper->update(
                entity: $object,
                register: $registerEntity,
                schema: $schemaEntity
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[ReferentialIntegrity] Failed to apply SET_NULL',
                context: [
                    'objectUuid' => $target['objectUuid'],
                    'property'   => $target['property'],
                    'error'      => $e->getMessage(),
                ]
            );
        }//end try
    }//end applySetNull()

    /**
     * Apply SET_DEFAULT action: set the reference to the configured default value.
     *
     * @param array $target The default target from the DeletionAnalysis.
     *
     * @return void
     */
    private function applySetDefault(array $target): void
    {
        try {
            $context        = $this->objectEntityMapper->findAcrossAllSources(
                identifier: $target['objectUuid'],
                includeDeleted: false,
                _rbac: false,
                _multitenancy: false
            );
            $object         = $context['object'];
            $registerEntity = $context['register'];
            $schemaEntity   = $context['schema'];

            $objectData = $object->getObject();
            $objectData[$target['property']] = $target['defaultValue'];
            $object->setObject($objectData);

            $this->objectEntityMapper->update(
                entity: $object,
                register: $registerEntity,
                schema: $schemaEntity
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[ReferentialIntegrity] Failed to apply SET_DEFAULT',
                context: [
                    'objectUuid' => $target['objectUuid'],
                    'property'   => $target['property'],
                    'error'      => $e->getMessage(),
                ]
            );
        }//end try
    }//end applySetDefault()

    /**
     * Apply CASCADE deletes in batch, grouped by register+schema.
     *
     * Groups cascade targets by their register+schema pair, resolves entities
     * once per group, and calls ObjectEntityMapper::deleteObjects() in bulk.
     * Falls back to individual deletion for targets without register info.
     *
     * @param array       $cascadeTargets   The cascade targets from DeletionAnalysis (already reversed).
     * @param string      $userId           The user performing the deletion.
     * @param string      $cascadeSource    The UUID of the root object triggering the cascade.
     * @param string|null $triggerSchemaSlug Schema slug of the trigger object for logging.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Groups targets and handles entity resolution per group
     */
    private function applyBatchCascadeDelete(
        array $cascadeTargets,
        string $userId,
        string $cascadeSource,
        ?string $triggerSchemaSlug
    ): void {
        // Group targets by register+schema for batch deletion.
        $groups = [];
        foreach ($cascadeTargets as $target) {
            $registerId = $target['register'] ?? null;
            $schemaId   = $target['schema'] ?? null;

            if ($registerId !== null && $schemaId !== null) {
                $groupKey = $registerId . '::' . $schemaId;
                $groups[$groupKey]['registerId'] = $registerId;
                $groups[$groupKey]['schemaId']   = $schemaId;
                $groups[$groupKey]['targets'][]  = $target;
            } else {
                // Fallback: targets without register info get their own single-item group.
                $groups['fallback_' . $target['objectUuid']] = [
                    'registerId' => $registerId,
                    'schemaId'   => $schemaId,
                    'targets'    => [$target],
                ];
            }
        }

        // Process each group with batch delete.
        foreach ($groups as $group) {
            $uuids = array_map(
                static fn(array $t): string => $t['objectUuid'],
                $group['targets']
            );

            try {
                $register = null;
                $schema   = null;

                if ($group['registerId'] !== null) {
                    $register = $this->registerMapper->find($group['registerId']);
                }

                if ($group['schemaId'] !== null) {
                    $schema = $this->schemaMapper->find($group['schemaId']);
                }

                $this->objectEntityMapper->deleteObjects(
                    uuids: $uuids,
                    hardDelete: false,
                    register: $register,
                    schema: $schema
                );
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[ReferentialIntegrity] Batch CASCADE delete failed',
                    context: [
                        'uuids'         => $uuids,
                        'cascadeSource' => $cascadeSource,
                        'error'         => $e->getMessage(),
                    ]
                );
            }//end try

            // Log each target individually for audit trail.
            foreach ($group['targets'] as $target) {
                $this->logIntegrityAction(
                    action: 'referential_integrity.cascade_delete',
                    objectUuid: $target['objectUuid'],
                    schemaId: $target['schema'] ?? null,
                    registerId: $target['register'] ?? null,
                    changed: [
                        'deletedBecause' => 'cascade',
                        'triggerObject'  => $cascadeSource,
                        'triggerSchema'  => $triggerSchemaSlug,
                        'property'       => $target['property'],
                    ],
                    userId: $userId
                );
            }
        }//end foreach
    }//end applyBatchCascadeDelete()
}//end class
