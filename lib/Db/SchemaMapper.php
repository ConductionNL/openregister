<?php

/**
 * OpenReg  ister Audit Trail
 *
 * This file contains the class for handling audit trail related operations
 * in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\OrganisationService;
use Exception;
use RuntimeException;
use ReflectionClass;
use stdClass;
use OCA\OpenRegister\Exception\ValidationException;
use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IAppConfig;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Db\ObjectEntityMapper;

/**
 * SchemaMapper handles database operations for Schema entities
 *
 * Mapper for Schema entities with multi-tenancy and RBAC support.
 * Provides CRUD operations with automatic organisation filtering, RBAC checks,
 * schema extension resolution, and event dispatching.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @method Schema insert(Entity $entity)
 * @method Schema update(Entity $entity)
 * @method Schema insertOrUpdate(Entity $entity)
 * @method Schema delete(Entity $entity)
 * @method Schema find(int|string $id)
 * @method Schema findEntity(IQueryBuilder $query)
 * @method Schema[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<Schema> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Schema>
 */
class SchemaMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Event dispatcher instance
     *
     * Dispatches events when schemas are created, updated, or deleted.
     *
     * @var IEventDispatcher Event dispatcher instance
     */
    private readonly IEventDispatcher $eventDispatcher;

    /**
     * Schema property validator instance
     *
     * Validates schema property definitions and types.
     *
     * @var PropertyValidatorHandler Schema property validator instance
     */
    private readonly PropertyValidatorHandler $validator;

    /**
     * Organisation mapper for multi-tenancy
     *
     * Used to get active organisation and apply organisation filters.
     *
     * @var OrganisationMapper Organisation mapper instance
     */
    protected readonly OrganisationMapper $organisationMapper;

    /**
     * App configuration for multi-tenancy settings
     *
     * Used by MultiTenancyTrait to check multi-tenancy status.
     *
     * @var IAppConfig App configuration instance
     */
    private IAppConfig $appConfig;

    /**
     * User session for current user
     *
     * Used to get current user context for RBAC and multi-tenancy.
     *
     * @var IUserSession User session instance
     */
    private readonly IUserSession $userSession;

    /**
     * Group manager for RBAC
     *
     * Used to check user group memberships for permission verification.
     *
     * @var IGroupManager Group manager instance
     */
    private readonly IGroupManager $groupManager;

    // Note: $appConfig is provided by MultiTenancyTrait (protected ?IAppConfig $appConfig=null)
    // We assign it in the constructor to make it available to the trait methods.

    /**
     * Constructor
     *
     * Initializes mapper with database connection and required dependencies
     * for multi-tenancy, RBAC, validation, and event dispatching.
     *
     * @param IDBConnection            $db                  Database connection for queries
     * @param IEventDispatcher         $eventDispatcher     Event dispatcher for schema events
     * @param PropertyValidatorHandler $validator           Schema property validator for validation
     * @param OrganisationService      $organisationService Organisation service for multi-tenancy
     * @param IUserSession             $userSession         User session for current user context
     * @param IGroupManager            $groupManager        Group manager for RBAC checks
     * @param IAppConfig               $appConfig           App configuration for multitenancy settings
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        IEventDispatcher $eventDispatcher,
        PropertyValidatorHandler $validator,
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IAppConfig $appConfig
    ) {
        // Initialize parent mapper with table name and entity class.
        parent::__construct($db, 'openregister_schemas', Schema::class);

        // Store dependencies for use in mapper methods.
        $this->eventDispatcher    = $eventDispatcher;
        $this->validator          = $validator;
        $this->organisationMapper = $organisationMapper;
        $this->userSession        = $userSession;
        $this->groupManager       = $groupManager;
        // Assign appConfig to trait's protected property.
        $this->appConfig = $appConfig;
    }//end __construct()

    /**
     * Finds a schema by id, with optional extension for statistics
     *
     * This method automatically resolves schema extensions. If the schema has
     * an 'extend' property set, it will load the parent schema and merge its
     * properties with the current schema, providing the complete resolved schema.
     *
     * @param int|string $id            The id of the schema
     * @param array      $_extend       Optional array of extensions (e.g., ['@self.stats'])
     * @param bool|null  $published     Whether to enable published bypass (default: null = check config)
     * @param bool       $_rbac         Whether to apply RBAC permission checks (default: true)
     * @param bool       $_multitenancy Whether to apply multi-tenancy filtering (default: true)
     *                                  Set to false to bypass organization filter (e.g., when expanding schemas for registers)
     *
     * @return Schema The schema, possibly with stats and resolved extensions
     * @throws \Exception If user doesn't have read permission
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function find(string | int $id, ?array $_extend=[], ?bool $published=null, bool $_rbac=true, bool $_multitenancy=true): Schema
    {
        // Verify RBAC permission to read if RBAC is enabled.
        if ($_rbac === true) {
            // @todo: remove this hotfix for solr - uncomment when ready
            // $this->verifyRbacPermission('read', 'schema');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_schemas')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter(value: $id, type: IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter(value: $id, type: IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter(value: $id, type: IQueryBuilder::PARAM_STR))
                )
            );

        // Apply organisation filter with published entity bypass support
        // Published schemas can bypass multi-tenancy restrictions if configured
        // Set $_multitenancy=false to bypass organization filter (e.g., when expanding schemas for registers).
        // applyOrganisationFilter handles $multiTenancyEnabled=false internally.
        // Use $published parameter if provided, otherwise check config.
        $enablePublished = $this->shouldPublishedObjectsBypassMultiTenancy();
        if ($published !== null) {
            $enablePublished = $published;
        }

        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true,
            tableAlias: '',
            enablePublished: $enablePublished,
            multiTenancyEnabled: $_multitenancy
        );

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new ValidationException('Schema not found');
        }

        $schema = Schema::fromRow($row);

        // Resolve schema composition if present (allOf, oneOf, anyOf).
        $schema = $this->resolveSchemaExtension($schema);

        return $schema;
    }//end find()

    /**
     * Find schema by slug
     *
     * @param string $slug The slug of the schema
     *
     * @return Schema|null The schema if found, null otherwise
     */
    public function findBySlug(string $slug): ?Schema
    {
        try {
            return $this->find($slug);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        } catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
            return null;
        }
    }//end findBySlug()

    /**
     * Finds multiple schemas by id
     *
     * @param array     $ids           The ids of the schemas
     * @param bool|null $published     Whether to enable published bypass (default: null = check config)
     * @param bool      $_rbac         Whether to apply RBAC permission checks (default: true)
     * @param bool      $_multitenancy Whether to apply multi-tenancy filtering (default: true)
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If a schema does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple schemas are found
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @todo: refactor this into find all
     *
     * @return Schema[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Schema>
     */
    public function findMultiple(array $ids, ?bool $published=null, bool $_rbac=true, bool $_multitenancy=true): array
    {
        $result = [];
        foreach ($ids as $id) {
            try {
                $result[] = $this->find(id: $id, _extend: [], published: $published, _rbac: $_rbac, _multitenancy: $_multitenancy);
            } catch (\OCP\AppFramework\Db\DoesNotExistException | \OCP\AppFramework\Db\MultipleObjectsReturnedException | \OCP\DB\Exception) {
                // Catch all exceptions but do nothing.
            }
        }

        return $result;
    }//end findMultiple()

    /**
     * Find multiple schemas by IDs using a single optimized query
     *
     * This method performs a single database query to fetch multiple schemas, register: * significantly improving performance compared to individual queries.
     *
     * @param array $ids Array of schema IDs to find
     *
     * @return Entity&Schema[]
     *
     * @psalm-return array<Entity&Schema>
     */
    public function findMultipleOptimized(array $ids): array
    {
        if (empty($ids) === true) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_schemas')
            ->where(
                $qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY))
            );

        $result  = $qb->executeQuery();
        $schemas = [];

        while (($row = $result->fetch()) !== false) {
            $schema = new Schema();
            $schema = $schema->fromRow($row);
            $schemas[$row['id']] = $schema;
        }

        return $schemas;
    }//end findMultipleOptimized()

    /**
     * Finds all schemas, files: with optional extension for statistics
     *
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions The search conditions to apply
     * @param array|null $searchParams     The search parameters to apply
     * @param array      $_extend          Optional array of extensions (e.g., ['@self.stats'])
     * @param bool|null  $published        Whether to enable published bypass (default: null = check config)
     * @param bool       $_rbac            Whether to apply RBAC permission checks (default: true)
     * @param bool       $_multitenancy    Whether to apply multi-tenancy filtering (default: true)
     *
     * @return Schema[]
     *
     * @throws \Exception If user doesn't have read permission
     *
     * @psalm-return     list<OCA\OpenRegister\Db\Schema>
     * @SuppressWarnings (PHPMD.UnusedFormalParameter)
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        ?array $_extend=[],
        ?bool $published=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        // Verify RBAC permission to read if RBAC is enabled.
        if ($_rbac === true) {
            // @todo: remove this hotfix for solr - uncomment when ready
            // $this->verifyRbacPermission('read', 'schema');
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_schemas')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach ($filters ?? [] as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
                continue;
            }

            if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
                continue;
            }

            $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
        }

        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams ?? [] as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Apply organisation filter with published entity bypass support
        // Published schemas can bypass multi-tenancy restrictions if configured.
        // applyOrganisationFilter handles $multiTenancyEnabled=false internally.
        // Use $published parameter if provided, otherwise check config.
        $enablePublished = $this->shouldPublishedObjectsBypassMultiTenancy();
        if ($published !== null) {
            $enablePublished = $published;
        }

        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true,
            tableAlias: '',
            enablePublished: $enablePublished,
            multiTenancyEnabled: $_multitenancy
        );

        // Just return the entities; do not attach stats here.
        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Inserts a schema entity into the database
     *
     * @param Entity $entity The entity to insert
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \Exception If user doesn't have create permission
     *
     * @return Entity The inserted entity
     *
     * @psalm-suppress LessSpecificImplementedReturnType - Schema is more specific than Entity
     */
    public function insert(Entity $entity): Entity
    {
        // Verify RBAC permission to create
        // $this->verifyRbacPermission('create', 'schema');
        // Auto-set organisation from active session.
        $this->setOrganisationOnCreate($entity);

        // Auto-set owner from current user session.
        $this->setOwnerOnCreate($entity);

        $entity = parent::insert($entity);

        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new SchemaCreatedEvent($entity));

        return $entity;
    }//end insert()

    /**
     * Ensures that a schema object has a UUID and a slug.
     *
     * @param Schema $schema The schema object to clean
     *
     * @return void
     */
    private function cleanObject(Schema $schema): void
    {
        // Enforce $ref is always a string in all properties and array items.
        $properties = $schema->getProperties() ?? [];
        $this->enforceRefIsStringRecursive($properties);
        $schema->setProperties($properties);

        // Check if UUID is set, if not, generate a new one.
        if ($schema->getUuid() === null) {
            $schema->setUuid((string) Uuid::v4());
        }

        // Ensure the object has a slug.
        if (empty($schema->getSlug()) === true) {
            // Convert to lowercase and replace spaces with dashes.
            $slug = strtolower(trim($schema->getTitle() ?? 'schema'));
            // Assuming title is used for slug.
            // Remove special characters.
            $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
            // Remove multiple dashes.
            $slug = preg_replace('/-+/', '-', $slug);
            // Remove leading/trailing dashes.
            $slug = trim($slug, '-');

            $schema->setSlug($slug);
        }

        // Ensure the object has a version.
        if ($schema->getVersion() === null) {
            $schema->setVersion('0.0.1');
        }

        // Ensure the object has a source set to 'internal' by default.
        if ($schema->getSource() === null || $schema->getSource() === '') {
            $schema->setSource('internal');
        }

        $properties      = ($schema->getProperties() ?? []);
        $propertyKeys    = array_keys($properties);
        $configuration   = $schema->getConfiguration() ?? [];
        $objectNameField = $configuration['objectNameField'] ?? '';
        $objectDescriptionField = $configuration['objectDescriptionField'] ?? '';

        // If an object name field is provided, it must exist in the properties.
        if (empty($objectNameField) === false && in_array($objectNameField, $propertyKeys) === false) {
            throw new Exception("The value for objectNameField ('$objectNameField') does not exist as a property in the schema.");
        }

        // If an object description field is provided, it must exist in the properties.
        if (empty($objectDescriptionField) === false && in_array($objectDescriptionField, $propertyKeys) === false) {
            throw new Exception("The value for objectDescriptionField ('$objectDescriptionField') does not exist as a property in the schema.");
        }

        // Establish the required fields based on the properties.
        // If schema already has a required array (JSON Schema standard), preserve it.
        // Otherwise, build from property-level 'required' flags (legacy support).
        $existingRequired = $schema->getRequired();
        $requiredFields   = [];

        // PRIORITY 1: Use existing schema-level required array if present (JSON Schema standard).
        if (empty($existingRequired) === false) {
            $requiredFields = $existingRequired;
        } else {
            // PRIORITY 2: Build from property-level 'required' flags (legacy/fallback).
            foreach ($properties as $propertyKey => $property) {
                // Check if the property has a 'required' field set to true or the string 'true'.
                if (($property['required'] ?? null) !== null) {
                    $requiredValue = $property['required'];
                    if ($requiredValue === true
                        || $requiredValue === 'true'
                        || (is_string($requiredValue) === true && strtolower(trim($requiredValue)) === 'true')
                    ) {
                        $requiredFields[] = $propertyKey;
                    }
                }
            }
        }

        // Set the required fields on the schema.
        $schema->setRequired($requiredFields);

        // If the object name field is empty, try to find a logical key.
        if (empty($objectNameField) === true) {
            $nameKeys = [
                'name',
                'naam',
                'title',
                'titel',
            ];
            foreach ($nameKeys as $key) {
                if (in_array($key, $propertyKeys) === true) {
                    // Update the configuration array.
                    $configuration['objectNameField'] = $key;
                    $schema->setConfiguration($configuration);
                    break;
                }
            }
        }

        // If the object description field is empty, try to find a logical key.
        if (empty($objectDescriptionField) === true) {
            $descriptionKeys = [
                'description',
                'beschrijving',
                'omschrijving',
                'summary',
            ];
            foreach ($descriptionKeys as $key) {
                if (in_array($key, $propertyKeys) === true) {
                    // Update the configuration array.
                    $configuration['objectDescriptionField'] = $key;
                    $schema->setConfiguration($configuration);
                    break;
                }
            }
        }
    }//end cleanObject()

    /**
     * Recursively enforce that $ref is always a string in all properties and array items
     *
     * @param array $properties The properties array to check (passed by reference)
     *
     * @return void
     *
     * @throws \Exception If $ref is not a string or cannot be converted
     */
    private function enforceRefIsStringRecursive(array &$properties): void
    {
        foreach ($properties as $key => &$property) {
            // If property is not an array, skip.
            if (is_array($property) === false) {
                continue;
            }

            // Check $ref at this level.
            if (($property['$ref'] ?? null) !== null) {
                if (is_array($property['$ref']) === true && (($property['$ref']['id'] ?? null) !== null)) {
                    $property['$ref'] = $property['$ref']['id'];
                } else if (is_object($property['$ref']) === true && (($property['$ref']->id ?? null) !== null)) {
                    $property['$ref'] = $property['$ref']->id;
                } else if (is_int($property['$ref']) === true) {
                } else if (is_string($property['$ref']) === false && $property['$ref'] !== '') {
                    throw new Exception("Schema property '$key' has a \$ref that is not a string or empty: ".print_r($property['$ref'], true));
                }
            }

            // Check array items recursively.
            if (($property['items'] ?? null) !== null && is_array($property['items']) === true) {
                $this->enforceRefIsStringRecursive($property['items']);
            }

            // Check nested properties recursively.
            if (($property['properties'] ?? null) !== null && is_array($property['properties']) === true) {
                $this->enforceRefIsStringRecursive($property['properties']);
            }
        }//end foreach
    }//end enforceRefIsStringRecursive()

    /**
     * Creates a schema from an array
     *
     * This method handles schema extension by extracting only the delta
     * (differences from parent schema) before saving when the schema extends another.
     *
     * @param array $object The object to create
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \Exception If property validation fails
     *
     * @return Schema The created schema
     */
    public function createFromArray(array $object): Schema
    {
        $schema = new Schema();
        $schema->hydrate(object: $object, validator: $this->validator);

        // Clean the schema object to ensure UUID, slug, and version are set.
        $this->cleanObject($schema);

        // **SCHEMA COMPOSITION**: Extract delta if schema uses composition (allOf).
        // This ensures we only store the differences, not the full resolved schema.
        // NOTE: Circular reference validation is done during resolveSchemaExtension().
        $schema = $this->extractSchemaDelta($schema);

        // **PERFORMANCE OPTIMIZATION**: Generate facet configuration from schema properties.
        $this->generateFacetConfiguration($schema);

        $schema = $this->insert($schema);

        return $schema;
    }//end createFromArray()

    /**
     * Updates a schema entity in the database
     *
     * This method handles schema extension by extracting only the delta
     * (differences from parent schema) before saving when the schema extends another.
     *
     * @param Entity $entity The entity to update
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple entities are found
     * @throws \Exception If user doesn't have update permission or access to this organisation
     *
     * @return Entity The updated entity
     *
     * @psalm-suppress LessSpecificImplementedReturnType - Schema is more specific than Entity
     */
    public function update(Entity $entity): Entity
    {
        // Verify RBAC permission to update
        // $this->verifyRbacPermission('update', 'schema');
        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        // Fetch old entity directly without organisation filter for event comparison.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_schemas')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));
        $oldSchema = $this->findEntity(query: $qb);

        // Clean the schema object to ensure UUID, slug, and version are set.
        $this->cleanObject($entity);

        // **SCHEMA COMPOSITION**: Extract delta if schema uses composition (allOf).
        // This ensures we only store the differences, not the full resolved schema.
        // NOTE: Circular reference validation is done during resolveSchemaExtension().
        $entity = $this->extractSchemaDelta($entity);

        // **PERFORMANCE OPTIMIZATION**: Generate facet configuration from schema properties.
        $this->generateFacetConfiguration($entity);

        $entity = parent::update($entity);

        // Dispatch update event.
        $this->eventDispatcher->dispatchTyped(new SchemaUpdatedEvent($entity, $oldSchema));

        return $entity;
    }//end update()

    /**
     * Updates a schema from an array
     *
     * @param int   $id     The id of the schema to update
     * @param array $object The object to update
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the schema does not exist
     * @throws \Exception If property validation fails
     *
     * @return Schema The updated schema
     */
    public function updateFromArray(int $id, array $object): Schema
    {
        // Note: find() applies multitenancy filter, which is correct for data isolation.
        // Access verification happens in update() method via verifyOrganisationAccess().
        $schema = $this->find(id: $id);

        // Set or update the version.
        if (isset($object['version']) === false) {
            $currentVersion = $schema->getVersion() ?? '0.0.0';
            $version        = explode('.', $currentVersion);
            $version[2]     = ((int) $version[2] + 1);
            $schema->setVersion(implode('.', $version));
        }

        $schema->hydrate(object: $object, validator: $this->validator);

        // Update the schema in the database.
        $schema = $this->update($schema);

        return $schema;
    }//end updateFromArray()

    /**
     * Delete a schema
     *
     * @param Entity $entity The schema entity to delete
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     *
     * @return Schema The deleted schema
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function delete(Entity $entity): Schema
    {
        // Verify RBAC permission to delete
        // $this->verifyRbacPermission('delete', 'schema');
        // Verify user has access to this organisation.
        $this->verifyOrganisationAccess($entity);

        // Check for attached objects before deleting (using direct database query to avoid circular dependency).
        $schemaId = $entity->id;
        if (method_exists($entity, 'getId') === true) {
            $schemaId = $entity->getId();
        }

        // Count objects that reference this schema (excluding soft-deleted objects).
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from('openregister_objects')
            ->where($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('deleted'));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        if ($count > 0) {
            throw new ValidationException('Cannot delete schema: objects are still attached.');
        }

        // Proceed with deletion if no objects are attached.
        $result = parent::delete($entity);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(
            new SchemaDeletedEvent($entity)
        );

        return $result;
    }//end delete()

    /**
     * Get the number of registers associated with each schema
     *
     * This method returns an associative array where the key is the schema ID and the value is the number of registers that reference that schema.
     *
     * @phpstan-return array<int,int>  Associative array of schema ID => register count
     *
     * @psalm-return array<int, int>
     * @return       int[]
     */
    public function getRegisterCountPerSchema(): array
    {
        // TODO: Optimize for large datasets (current approach loads all registers into memory).
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'schemas')
            ->from('openregister_registers');
        $result = $qb->executeQuery()->fetchAll();

        $counts = [];
        foreach ($result as $row) {
            // Decode the schemas JSON array for each register.
            $decoded = json_decode($row['schemas'], true);
            $schemas = [];
            if ($decoded !== null && $decoded !== false) {
                $schemas = $decoded;
            }

            foreach ($schemas as $schemaId) {
                $counts[(int) $schemaId] = ($counts[(int) $schemaId] ?? 0) + 1;
            }
        }

        return $counts;
    }//end getRegisterCountPerSchema()

    /**
     * Get all schema ID to slug mappings
     *
     * @return array<string,string> Array mapping schema IDs to their slugs
     */
    public function getIdToSlugMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $result   = $qb->executeQuery();
        $mappings = [];
        while (($row = $result->fetch()) !== false) {
            $mappings[$row['id']] = $row['slug'];
        }

        return $mappings;
    }//end getIdToSlugMap()

    /**
     * Get all schema slug to ID mappings
     *
     * @return array<string,string> Array mapping schema slugs to their IDs
     */
    public function getSlugToIdMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $result   = $qb->executeQuery();
        $mappings = [];
        while (($row = $result->fetch()) !== false) {
            $mappings[$row['slug']] = $row['id'];
        }

        return $mappings;
    }//end getSlugToIdMap()

    /**
     * Find schemas that have properties referencing the given schema
     *
     * This method searches through all schemas to find ones that have properties
     * with $ref pointing to the target schema, indicating a relationship.
     *
     * @param Schema|int|string $schema The target schema to find references to
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the target schema does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple target schemas are found
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return Schema[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Schema>
     */
    public function getRelated(Schema|int|string $schema): array
    {
        // If we received a Schema entity, get its ID, otherwise find the schema.
        if ($schema instanceof Schema === false) {
            // Find the target schema to get all its identifiers.
            $targetSchema     = $this->find(id: $schema);
            $targetSchemaId   = (string) $targetSchema->getId();
            $targetSchemaUuid = $targetSchema->getUuid();
            $targetSchemaSlug = $targetSchema->getSlug();
        } else if ($schema instanceof Schema) {
            $targetSchemaId   = (string) $schema->getId();
            $targetSchemaUuid = $schema->getUuid();
            $targetSchemaSlug = $schema->getSlug();
        }

        // Get all schemas to search through their properties.
        $allSchemas     = $this->findAll();
        $relatedSchemas = [];

        foreach ($allSchemas as $currentSchema) {
            // Skip the target schema itself.
            if ($currentSchema->getId() === (int) $targetSchemaId) {
                continue;
            }

            // Get the properties of the current schema.
            $properties = $currentSchema->getProperties() ?? [];

            // Search for references to the target schema.
            if ($this->hasReferenceToSchema(properties: $properties, targetSchemaId: $targetSchemaId, targetSchemaUuid: $targetSchemaUuid, targetSchemaSlug: $targetSchemaSlug) === true) {
                $relatedSchemas[] = $currentSchema;
            }
        }

        return $relatedSchemas;
    }//end getRelated()

    /**
     * Recursively check if properties contain a reference to the target schema
     *
     * This method searches through properties recursively to find $ref values
     * that match the target schema's ID, files: UUID, rbac: or slug.
     *
     * @param array  $properties       The properties array to search through
     * @param string $targetSchemaId   The target schema ID to look for
     * @param string $targetSchemaUuid The target schema UUID to look for
     * @param string $targetSchemaSlug The target schema slug to look for
     *
     * @return bool True if a reference to the target schema is found
     */
    public function hasReferenceToSchema(array $properties, string $targetSchemaId, string $targetSchemaUuid, string $targetSchemaSlug): bool
    {
        foreach ($properties as $property) {
            // Skip non-array properties.
            if (is_array($property) === false) {
                continue;
            }

            // Check if this property has a $ref that matches our target schema.
            if (($property['$ref'] ?? null) !== null) {
                $ref = $property['$ref'];

                // Check exact matches first.
                if ($ref === $targetSchemaId
                    || $ref === $targetSchemaUuid
                    || $ref === $targetSchemaSlug
                    || $ref === (int) $targetSchemaId
                ) {
                    return true;
                }

                // Check if the ref contains the target schema slug in JSON Schema format.
                // Format: "#/components/schemas/slug" or "components/schemas/slug" etc.
                if (is_string($ref) === true && empty($targetSchemaSlug) === false) {
                    if (str_contains($ref, '/schemas/'.$targetSchemaSlug) === true
                        || str_contains($ref, 'schemas/'.$targetSchemaSlug) === true
                        || str_ends_with($ref, '/'.$targetSchemaSlug) === true
                    ) {
                        return true;
                    }
                }

                // Check if the entity contains the target schema UUID.
                if (is_string($ref) === true && empty($targetSchemaUuid) === false) {
                    if (str_contains($ref, $targetSchemaUuid) === true) {
                        return true;
                    }
                }
            }//end if

            // Recursively check nested properties.
            if (($property['properties'] ?? null) !== null && is_array($property['properties']) === true) {
                if ($this->hasReferenceToSchema(properties: $property['properties'], targetSchemaId: $targetSchemaId, targetSchemaUuid: $targetSchemaUuid, targetSchemaSlug: $targetSchemaSlug) === true) {
                    return true;
                }
            }

            // Check array items for references.
            if (($property['items'] ?? null) !== null && is_array($property['items']) === true) {
                if ($this->hasReferenceToSchema(properties: [$property['items']], targetSchemaId: $targetSchemaId, targetSchemaUuid: $targetSchemaUuid, targetSchemaSlug: $targetSchemaSlug) === true) {
                    return true;
                }
            }
        }//end foreach

        return false;
    }//end hasReferenceToSchema()

    /**
     * Generate facet configuration from schema properties
     *
     * **PERFORMANCE OPTIMIZATION**: This method automatically generates facet configurations
     * from schema properties marked with 'facetable': true, eliminating the need for
     * runtime analysis during _facetable=true requests.
     *
     * Facetable fields are detected by:
     * - Properties with 'facetable': true explicitly set
     * - Common field names that are typically facetable (type, status, category)
     * - Enum properties (automatically facetable as terms)
     * - Date/datetime properties (automatically facetable as date_histogram)
     *
     * @param Schema $schema The schema to generate facets for
     *
     * @return void
     */
    private function generateFacetConfiguration(Schema $schema): void
    {
        $properties  = $schema->getProperties() ?? [];
        $facetConfig = [];

        // Add metadata facets (always available).
        $facetConfig['@self'] = [
            'register'  => ['type' => 'terms'],
            'schema'    => ['type' => 'terms'],
            'created'   => ['type' => 'date_histogram', 'interval' => 'month'],
            'updated'   => ['type' => 'date_histogram', 'interval' => 'month'],
            'published' => ['type' => 'date_histogram', 'interval' => 'month'],
            'owner'     => ['type' => 'terms'],
        ];

        // Analyze properties for facetable fields.
        foreach ($properties as $fieldName => $property) {
            if (is_array($property) === false) {
                continue;
            }

            $facetType = $this->determineFacetTypeForProperty($property, $fieldName);
            if ($facetType !== null) {
                $facetConfig[$fieldName] = ['type' => $facetType];

                // Add interval for date histograms.
                if ($facetType === 'date_histogram') {
                    $facetConfig[$fieldName]['interval'] = 'month';
                }
            }
        }

        // Store the facet configuration in the schema.
        // $facetConfig always contains at least '@self', so it's never empty.
        $schema->setFacets($facetConfig);
    }//end generateFacetConfiguration()

    /**
     * Determine the appropriate facet type for a schema property
     *
     * PERFORMANCE OPTIMIZATION**: Smart detection of facetable fields based on
     * property characteristics, names, and explicit facetable markers.
     *
     * @param array  $property  The property definition
     * @param string $fieldName The field name
     *
     * @return null|string The facet type ('terms', 'date_histogram') or null if not facetable
     *
     * @psalm-return 'date_histogram'|'terms'|null
     */
    private function determineFacetTypeForProperty(array $property, string $fieldName): string|null
    {
        // Check if explicitly marked as facetable.
        if (($property['facetable'] ?? null) !== null
            && ($property['facetable'] === true || $property['facetable'] === 'true'
            || (is_string($property['facetable']) === true && strtolower(trim($property['facetable'])) === 'true') === true) === true
        ) {
            return $this->determineFacetTypeFromProperty($property);
        }

        // Auto-detect common facetable field names.
        $commonFacetableFields = [
            'type',
            'status',
            'category',
            'tags',
            'label',
            'group',
            'department',
            'location',
            'priority',
            'state',
            'classification',
            'genre',
            'brand',
            'model',
            'version',
            'license',
            'language',
        ];

        $lowerFieldName = strtolower($fieldName);
        if (in_array($lowerFieldName, $commonFacetableFields) === true) {
            return $this->determineFacetTypeFromProperty($property);
        }

        // Auto-detect enum properties (good for faceting).
        if (($property['enum'] ?? null) !== null && is_array($property['enum']) === true && count($property['enum']) > 0) {
            return 'terms';
        }

        // Auto-detect date/datetime fields.
        $propertyType = $property['type'] ?? '';
        if (in_array($propertyType, ['date', 'datetime', 'date-time']) === true) {
            return 'date_histogram';
        }

        // Check for date-like field names.
        $dateFields = ['created', 'updated', 'modified', 'date', 'time', 'timestamp'];
        foreach ($dateFields as $dateField) {
            if (str_contains($lowerFieldName, $dateField) === true) {
                return 'date_histogram';
            }
        }

        return null;
    }//end determineFacetTypeForProperty()

    /**
     * Determine facet type from property characteristics
     *
     * @param array $property The property definition
     *
     * @return string The facet type ('terms' or 'date_histogram')
     *
     * @psalm-return 'date_histogram'|'terms'
     */
    private function determineFacetTypeFromProperty(array $property): string
    {
        $propertyType = $property['type'] ?? 'string';

        // Date/datetime properties use date_histogram.
        if (in_array($propertyType, ['date', 'datetime', 'date-time']) === true) {
            return 'date_histogram';
        }

        // Enum properties use terms.
        if (($property['enum'] ?? null) !== null && is_array($property['enum']) === true) {
            return 'terms';
        }

        // Boolean, integer, number with small ranges use terms.
        if (in_array($propertyType, ['boolean', 'integer', 'number']) === true) {
            return 'terms';
        }

        // Default to terms for other types.
        return 'terms';
    }//end determineFacetTypeFromProperty()

    /**
     * Resolve schema composition by merging referenced schemas
     *
     * This method implements JSON Schema composition patterns conforming to the specification:
     * 1. Handles 'extend' (deprecated) for backward compatibility
     * 2. Handles 'allOf' - instance must validate against ALL schemas (multiple inheritance)
     * 3. Handles 'oneOf' - instance must validate against EXACTLY ONE schema
     * 4. Handles 'anyOf' - instance must validate against AT LEAST ONE schema
     *
     * The method enforces the Liskov Substitution Principle:
     * - Extended schemas can ONLY ADD constraints, never relax them
     * - Metadata (title, description, order) can be overridden
     * - Validation rules (type, format, enum, min/max, pattern) cannot be relaxed
     *
     * @param Schema $schema  The schema to resolve
     * @param array  $visited Array of visited schema IDs to prevent circular references
     *
     * @throws \Exception If circular reference is detected or referenced schema not found
     *
     * @return Schema The resolved schema with merged properties
     */
    private function resolveSchemaExtension(Schema $schema, array $visited=[]): Schema
    {
        // Get current schema identifier for tracking.
        $currentId = $schema->getId() ?? $schema->getUuid() ?? 'unknown';

        // Check for circular references.
        if (in_array($currentId, $visited) === true) {
            throw new Exception("Circular schema composition detected: schema '{$currentId}' creates a loop");
        }

        // Add current schema to visited list.
        $visited[] = $currentId;

        // Check for composition patterns (in order of precedence).
        $allOf = $schema->getAllOf();
        $oneOf = $schema->getOneOf();
        $anyOf = $schema->getAnyOf();

        // If schema has allOf, resolve it (most common for extension/inheritance).
        if ($allOf !== null && count($allOf) > 0) {
            return $this->resolveAllOf(schema: $schema, allOf: $allOf, visited: $visited);
        }

        // If schema has oneOf, resolve it.
        if ($oneOf !== null && count($oneOf) > 0) {
            return $this->resolveOneOf(schema: $schema, oneOf: $oneOf, visited: $visited);
        }

        // If schema has anyOf, resolve it.
        if ($anyOf !== null && count($anyOf) > 0) {
            return $this->resolveAnyOf(schema: $schema, anyOf: $anyOf, visited: $visited);
        }

        // No composition - return schema as-is.
        return $schema;
    }//end resolveSchemaExtension()

    /**
     * Resolve allOf composition pattern
     *
     * Instance must validate against ALL referenced schemas.
     * This is the recommended pattern for schema extension/inheritance.
     * Properties from all schemas are merged with the child schema.
     *
     * @param Schema $schema  The child schema
     * @param array  $allOf   Array of schema identifiers to merge
     * @param array  $visited Visited schemas for circular reference detection
     *
     * @throws \Exception If referenced schema not found or circular reference detected
     *
     * @return Schema Resolved schema with all properties merged
     */
    private function resolveAllOf(Schema $schema, array $allOf, array $visited): Schema
    {
        $currentId = $schema->getId() ?? $schema->getUuid() ?? 'unknown';

        // Start with empty properties and required fields.
        $mergedProperties = [];
        $mergedRequired   = [];

        // Iterate through each referenced schema in allOf.
        foreach ($allOf as $parentRef) {
            // Skip empty or null references.
            if (empty($parentRef) === true) {
                continue;
            }

            // Check for self-reference.
            if ($parentRef === $currentId || $parentRef === $schema->getId()
                || $parentRef === $schema->getUuid() || $parentRef === $schema->getSlug()
            ) {
                throw new Exception("Schema '{$currentId}' cannot reference itself in allOf");
            }

            // Load and resolve the parent schema.
            $parentSchema = $this->loadSchema($parentRef);
            $parentSchema = $this->resolveSchemaExtension($parentSchema, $visited);

            // Merge properties from this parent.
            $mergedProperties = $this->mergeSchemaProperties(
                $mergedProperties,
                $parentSchema->getProperties()
            );

            // Merge required fields (union - must satisfy all).
            $mergedRequired = array_unique(
                array_merge($mergedRequired, $parentSchema->getRequired())
            );
        }//end foreach

        // Now merge child schema properties on top (child can add constraints).
        $childProperties  = $schema->getProperties();
        $mergedProperties = $this->mergeSchemaPropertiesWithValidation(
            $mergedProperties,
            $childProperties,
            (string) $currentId
        );

        // Merge child required fields (can only add, not remove).
        $mergedRequired = array_unique(
            array_merge($mergedRequired, $schema->getRequired())
        );

        // Create resolved schema.
        $resolvedSchema = clone $schema;
        $resolvedSchema->setProperties($mergedProperties);
        $resolvedSchema->setRequired($mergedRequired);

        return $resolvedSchema;
    }//end resolveAllOf()

    /**
     * Get property source metadata for a schema
     *
     * Returns metadata about each property indicating whether it's native (defined in this schema)
     * or inherited (from a parent schema via allOf). For inherited properties, shows the source schema.
     *
     * @param Schema $schema The schema to analyze
     *
     * @return array<string, array<string, string|null>> Property metadata keyed by property name
     *
     * @psalm-return array<string, array{source: 'native'|'inherited', inheritedFrom: string|null}>
     */
    public function getPropertySourceMetadata(Schema $schema): array
    {
        $metadata         = [];
        $nativeProperties = [];

        // Get the raw schema data from database to see what properties it actually stores.
        // This is necessary because the resolved schema has merged properties.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('properties')
                ->from('openregister_schemas')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row !== false && ($row['properties'] ?? null) !== null) {
                $nativeProperties = json_decode($row['properties'], true) ?? [];
            }
        } catch (Exception $e) {
            // If we can't get raw data, use current properties.
            $nativeProperties = [];
        }//end try

        $allProperties = $schema->getProperties();
        $allOf         = $schema->getAllOf() ?? [];

        foreach ($allProperties as $propName => $propDef) {
            $isNative = isset($nativeProperties[$propName]);

            $metadata[$propName] = [
                'source'        => $isNative ? 'native' : 'inherited',
                'inheritedFrom' => $isNative ? null : $this->findPropertySource($propName, $allOf),
            ];
        }

        return $metadata;
    }//end getPropertySourceMetadata()

    /**
     * Find which parent schema a property was inherited from
     *
     * @param string $propertyName The property name to search for
     * @param array  $parentRefs   Array of parent schema references
     *
     * @return string|null The parent schema ID/UUID/slug, or null if not found
     */
    private function findPropertySource(string $propertyName, array $parentRefs): ?string
    {
        foreach ($parentRefs as $parentRef) {
            // Skip empty or null references.
            if (empty($parentRef) === true) {
                continue;
            }

            try {
                $parentSchema = $this->loadSchema($parentRef);
                $parentSchema = $this->resolveSchemaExtension($parentSchema);

                if (isset($parentSchema->getProperties()[$propertyName]) === true) {
                    return $parentRef;
                }
            } catch (Exception $e) {
                // Parent not found, continue.
                continue;
            }
        }

        return null;
    }//end findPropertySource()

    /**
     * Resolve oneOf composition pattern
     *
     * Instance must validate against EXACTLY ONE referenced schema.
     * This pattern is used for mutually exclusive options.
     * Properties from each schema are kept separate (not merged).
     *
     * @param Schema $schema  The schema with oneOf
     * @param array  $oneOf   Array of schema identifiers
     * @param array  $visited Visited schemas for circular reference detection
     *
     * @throws \Exception If referenced schema not found
     *
     * @return Schema The schema with resolved oneOf references
     */
    private function resolveOneOf(Schema $schema, array $oneOf, array $visited): Schema
    {
        // For oneOf, we don't merge properties - each option stands alone.
        // Just validate that all referenced schemas exist and resolve them.
        $currentId = $schema->getId() ?? $schema->getUuid() ?? 'unknown';

        foreach ($oneOf as $ref) {
            if ($ref === $currentId || $ref === $schema->getId()
                || $ref === $schema->getUuid() || $ref === $schema->getSlug()
            ) {
                throw new Exception("Schema '{$currentId}' cannot reference itself in oneOf");
            }

            // Load and resolve referenced schema (validates it exists).
            $referencedSchema = $this->loadSchema($ref);
            $this->resolveSchemaExtension($referencedSchema, $visited);
        }

        // Return schema as-is (oneOf schemas are not merged).
        return $schema;
    }//end resolveOneOf()

    /**
     * Resolve anyOf composition pattern
     *
     * Instance must validate against AT LEAST ONE referenced schema.
     * This pattern provides flexible composition.
     * Properties from each schema are kept separate (not merged).
     *
     * @param Schema $schema  The schema with anyOf
     * @param array  $anyOf   Array of schema identifiers
     * @param array  $visited Visited schemas for circular reference detection
     *
     * @throws \Exception If referenced schema not found
     *
     * @return Schema The schema with resolved anyOf references
     */
    private function resolveAnyOf(Schema $schema, array $anyOf, array $visited): Schema
    {
        // For anyOf, we don't merge properties - each option stands alone.
        // Just validate that all referenced schemas exist and resolve them.
        $currentId = $schema->getId() ?? $schema->getUuid() ?? 'unknown';

        foreach ($anyOf as $ref) {
            if ($ref === $currentId || $ref === $schema->getId()
                || $ref === $schema->getUuid() || $ref === $schema->getSlug()
            ) {
                throw new Exception("Schema '{$currentId}' cannot reference itself in anyOf");
            }

            // Load and resolve referenced schema (validates it exists).
            $referencedSchema = $this->loadSchema($ref);
            $this->resolveSchemaExtension($referencedSchema, $visited);
        }

        // Return schema as-is (anyOf schemas are not merged).
        return $schema;
    }//end resolveAnyOf()

    /**
     * Load a schema by ID, UUID, or slug
     *
     * Helper method to load a schema from the database by any identifier type.
     *
     * @param string|int $identifier Schema ID, UUID, or slug
     *
     * @throws \Exception If schema not found
     *
     * @return Schema The loaded schema
     */
    private function loadSchema(string|int $identifier): Schema
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('openregister_schemas')
                ->where(
                    $qb->expr()->orX(
                        $qb->expr()->eq('id', $qb->createNamedParameter(value: $identifier, type: IQueryBuilder::PARAM_INT)),
                        $qb->expr()->eq('uuid', $qb->createNamedParameter(value: $identifier, type: IQueryBuilder::PARAM_STR)),
                        $qb->expr()->eq('slug', $qb->createNamedParameter(value: $identifier, type: IQueryBuilder::PARAM_STR))
                    )
                );

            return $this->findEntity(query: $qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new Exception("Schema '{$identifier}' not found");
        }
    }//end loadSchema()

    /**
     * Merge parent and child schema properties (without validation)
     *
     * This method performs a deep merge of schema properties where:
     * - Properties present in both parent and child: child values override parent values
     * - Properties only in parent: included in result
     * - Properties only in child: included in result
     * - For nested properties (objects), performs recursive merge
     *
     * NOTE: This method does NOT enforce Liskov Substitution Principle.
     * Use mergeSchemaPropertiesWithValidation() for extension scenarios.
     *
     * @param array $parentProperties Parent schema properties
     * @param array $childProperties  Child schema properties (overrides)
     *
     * @return array Merged properties array
     */
    private function mergeSchemaProperties(array $parentProperties, array $childProperties): array
    {
        // Start with parent properties as the base.
        $merged = $parentProperties;

        // Apply child properties on top (overriding parent where present).
        foreach ($childProperties as $propertyName => $propertyDefinition) {
            if (($merged[$propertyName] ?? null) !== null && is_array($propertyDefinition) === true && is_array($merged[$propertyName]) === true) {
                // If property exists in both and both are arrays, perform deep merge.
                $merged[$propertyName] = $this->deepMergeProperty($merged[$propertyName], $propertyDefinition);
                continue;
            }

            // Otherwise, child property completely replaces parent property.
            $merged[$propertyName] = $propertyDefinition;
        }

        return $merged;
    }//end mergeSchemaProperties()

    /**
     * Merge parent and child schema properties WITH Liskov Substitution validation
     *
     * This method enforces the Liskov Substitution Principle:
     * - Child schemas can ONLY ADD constraints, never relax them
     * - Metadata (title, description, order, icon) CAN be overridden
     * - Validation rules (type, format, enum, pattern, min/max) CANNOT be relaxed
     *
     * Examples of ALLOWED changes:
     * - Adding new properties
     * - Adding more restrictive validation (lower maxLength, higher minLength)
     * - Changing title, description, order (metadata)
     * - Removing enum values (more restrictive)
     *
     * Examples of FORBIDDEN changes:
     * - Changing property type (string to number)
     * - Relaxing validation (higher maxLength, lower minLength)
     * - Adding enum values (less restrictive)
     * - Removing required constraints
     *
     * @param array  $parentProperties Parent schema properties
     * @param array  $childProperties  Child schema properties
     * @param string $schemaId         Schema ID for error messages
     *
     * @throws \Exception If child violates Liskov Substitution Principle
     *
     * @return array Merged properties array
     */
    private function mergeSchemaPropertiesWithValidation(
        array $parentProperties,
        array $childProperties,
        string $schemaId
    ): array {
        // Start with parent properties as the base.
        $merged = $parentProperties;

        // Apply child properties on top with validation.
        foreach ($childProperties as $propertyName => $childProperty) {
            // If property doesn't exist in parent, it's new - allowed.
            if (isset($merged[$propertyName]) === false) {
                $merged[$propertyName] = $childProperty;
                continue;
            }

            $parentProperty = $merged[$propertyName];

            // If both are arrays, perform deep merge with validation.
            if (is_array($parentProperty) === false || is_array($childProperty) === false) {
                // Scalar replacement - validate it doesn't relax constraints.
                $this->validateConstraintAddition(parentProperty: $parentProperty, childProperty: $childProperty, propertyName: $propertyName, schemaId: $schemaId);
                $merged[$propertyName] = $childProperty;
                continue;
            }

            $merged[$propertyName] = $this->deepMergePropertyWithValidation(
                $parentProperty,
                $childProperty,
                $propertyName,
                $schemaId
            );
        }//end foreach

        return $merged;
    }//end mergeSchemaPropertiesWithValidation()

    /**
     * Perform deep merge of a single property definition (WITHOUT validation)
     *
     * This method recursively merges property definitions, allowing child schemas
     * to override specific aspects of a property while preserving others.
     *
     * NOTE: This method does NOT enforce Liskov Substitution Principle.
     * Use deepMergePropertyWithValidation() for extension scenarios.
     *
     * Examples:
     * - Parent has 'minLength': 5, child has 'maxLength': 100 -> both are preserved
     * - Parent has 'title': 'Name', child has 'title': 'Full Name' -> child overrides
     * - For nested objects/arrays, performs recursive merge
     *
     * @param array $parentProperty Parent property definition
     * @param array $childProperty  Child property definition (overrides)
     *
     * @return array Merged property definition
     */
    private function deepMergeProperty(array $parentProperty, array $childProperty): array
    {
        $merged = $parentProperty;

        foreach ($childProperty as $key => $value) {
            if (($merged[$key] ?? null) === null || is_array($value) === false || is_array($merged[$key]) === false) {
                // Scalar values: child overrides parent.
                $merged[$key] = $value;
                continue;
            }

            // Recursively merge nested arrays.
            // Special handling for 'properties' and 'items' which need deep merge.
            if ($key !== 'properties' && $key !== 'items') {
                // For other arrays (like enum, required at property level), child replaces parent.
                $merged[$key] = $value;
                continue;
            }

            $merged[$key] = $this->deepMergeProperty($merged[$key], $value);
        }

        return $merged;
    }//end deepMergeProperty()

    /**
     * Perform deep merge of a single property WITH Liskov Substitution validation
     *
     * This method enforces that child properties only add constraints, never relax them.
     * Metadata fields (title, description, order, icon, etc.) can be freely overridden.
     * Validation fields (type, format, enum, pattern, min/max, etc.) cannot be relaxed.
     *
     * @param array  $parentProperty Parent property definition
     * @param array  $childProperty  Child property definition
     * @param string $propertyName   Property name for error messages
     * @param string $schemaId       Schema ID for error messages
     *
     * @throws \Exception If child violates Liskov Substitution Principle
     *
     * @return array Merged property definition
     */
    private function deepMergePropertyWithValidation(
        array $parentProperty,
        array $childProperty,
        string $propertyName,
        string $schemaId
    ): array {
        // List of metadata fields that can be freely overridden.
        $metadataFields = [
            'title',
            'description',
            'order',
            'icon',
            'placeholder',
            'help',
            'example',
            'examples',
            '$comment',
            'deprecated',
            'readOnly',
            'writeOnly',
            'default',
            'x-order',
            'x-display',
            'x-tabName',
            'x-section',
            'ui:order',
            'ui:widget',
            'ui:options',
        ];

        // List of validation fields that require constraint checking.
        $validationFields = [
            'type',
            'format',
            'pattern',
            'enum',
            'const',
            'minimum',
            'maximum',
            'exclusiveMinimum',
            'exclusiveMaximum',
            'minLength',
            'maxLength',
            'minItems',
            'maxItems',
            'minProperties',
            'maxProperties',
            'multipleOf',
            'uniqueItems',
            'required',
            'additionalProperties',
            'patternProperties',
            'dependencies',
            'if',
            'then',
            'else',
        ];

        $merged = $parentProperty;

        foreach ($childProperty as $key => $childValue) {
            // If key doesn't exist in parent, it's new - allowed.
            if (isset($merged[$key]) === false) {
                $merged[$key] = $childValue;
                continue;
            }

            $parentValue = $merged[$key];

            // Metadata fields can be freely overridden.
            if (in_array($key, $metadataFields) === true) {
                $merged[$key] = $childValue;
                continue;
            }

            // Special handling for nested properties and items.
            if (($key === 'properties' || $key === 'items') === true && is_array($childValue) === true && is_array($parentValue) === true) {
                // Recursively validate nested properties.
                $mergedNested = [];
                foreach ($childValue as $nestedKey => $nestedChild) {
                    if (($parentValue[$nestedKey] ?? null) === null) {
                        // New nested property - allowed.
                        $mergedNested[$nestedKey] = $nestedChild;
                        continue;
                    }

                    $mergedNested[$nestedKey] = $this->deepMergePropertyWithValidation(
                        $parentValue[$nestedKey],
                        $nestedChild,
                        "{$propertyName}.{$key}.{$nestedKey}",
                        $schemaId
                    );
                }

                // Include parent nested properties not in child.
                foreach ($parentValue as $nestedKey => $nestedParent) {
                    if (isset($mergedNested[$nestedKey]) === false) {
                        $mergedNested[$nestedKey] = $nestedParent;
                    }
                }

                $merged[$key] = $mergedNested;
                continue;
            }//end if

            // Validation fields require constraint checking.
            if (in_array($key, $validationFields) === true) {
                $this->validateConstraintChange(parentValue: $parentValue, childValue: $childValue, constraint: $key, propertyName: $propertyName, schemaId: $schemaId);
                $merged[$key] = $childValue;
                continue;
            }

            // For other fields, perform standard merge.
            if (is_array($parentValue) === false || is_array($childValue) === false) {
                $merged[$key] = $childValue;
                continue;
            }

            $merged[$key] = $this->deepMergePropertyWithValidation(
                $parentValue,
                $childValue,
                "{$propertyName}.{$key}",
                $schemaId
            );
        }//end foreach

        return $merged;
    }//end deepMergePropertyWithValidation()

    /**
     * Validate that a constraint change does not relax validation
     *
     * Enforces Liskov Substitution Principle for constraint modifications.
     *
     * @param mixed  $parentValue  Parent constraint value
     * @param mixed  $childValue   Child constraint value
     * @param string $constraint   Constraint name
     * @param string $propertyName Property name for error messages
     * @param string $schemaId     Schema ID for error messages
     *
     * @throws \Exception If constraint is relaxed
     *
     * @return void
     */
    private function validateConstraintChange(
        mixed $parentValue,
        mixed $childValue,
        string $constraint,
        string $propertyName,
        string $schemaId
    ): void {
        // Type cannot be changed.
        if ($constraint === 'type' && $parentValue !== $childValue) {
            // Allow array of types if child is subset or equal.
            if (is_array($parentValue) === true && is_array($childValue) === true) {
                // Child must be subset of parent (more restrictive is ok).
                $diff = array_diff($childValue, $parentValue);
                if (count($diff) > 0) {
                    throw new Exception(
                        "Schema '{$schemaId}': Property '{$propertyName}' cannot change type from ".json_encode($parentValue)." to ".json_encode($childValue)." (adds types not in parent)"
                    );
                }

                return;
            }

            if (is_array($parentValue) === false && is_array($childValue) === false) {
                throw new Exception(
                    "Schema '{$schemaId}': Property '{$propertyName}' cannot change type from "."'{$parentValue}' to '{$childValue}'"
                );
            }

            throw new Exception(
                "Schema '{$schemaId}': Property '{$propertyName}' type change is not compatible"
            );
        }//end if

        // Format can only be added or made more restrictive.
        if ($constraint === 'format' && $parentValue !== null && $parentValue !== $childValue) {
            throw new Exception(
                "Schema '{$schemaId}': Property '{$propertyName}' cannot change format from "."'{$parentValue}' to '{$childValue}'"
            );
        }

        // Enum can only be made more restrictive (subset).
        if ($constraint === 'enum' && is_array($parentValue) === true && is_array($childValue) === true) {
            $diff = array_diff($childValue, $parentValue);
            if (count($diff) > 0) {
                throw new Exception(
                    "Schema '{$schemaId}': Property '{$propertyName}' enum cannot add values not in parent "."(added: ".json_encode($diff).")"
                );
            }
        }

        // Minimum constraints can only be increased (more restrictive).
        if (($constraint === 'minimum' || $constraint === 'minLength'
            || $constraint === 'minItems' || $constraint === 'minProperties') === true
            && is_numeric($parentValue) === true && is_numeric($childValue) === true
        ) {
            if ($childValue < $parentValue) {
                throw new Exception(
                    "Schema '{$schemaId}': Property '{$propertyName}' {$constraint} cannot be decreased from "."{$parentValue} to {$childValue} (relaxes constraint)"
                );
            }
        }

        // Maximum constraints can only be decreased (more restrictive).
        if (($constraint === 'maximum' || $constraint === 'maxLength'
            || $constraint === 'maxItems' || $constraint === 'maxProperties') === true
            && is_numeric($parentValue) === true && is_numeric($childValue) === true
        ) {
            if ($childValue > $parentValue) {
                throw new Exception(
                    "Schema '{$schemaId}': Property '{$propertyName}' {$constraint} cannot be increased from "."{$parentValue} to {$childValue} (relaxes constraint)"
                );
            }
        }

        // Pattern can only be added, not changed.
        if ($constraint === 'pattern' && $parentValue !== null && $parentValue !== $childValue) {
            throw new Exception(
                "Schema '{$schemaId}': Property '{$propertyName}' pattern cannot be changed from "."'{$parentValue}' to '{$childValue}'"
            );
        }
    }//end validateConstraintChange()

    /**
     * Validate that replacing a property doesn't relax constraints
     *
     * Used when entire property is replaced (not merged).
     *
     * @param mixed  $parentProperty Parent property value
     * @param mixed  $childProperty  Child property value
     * @param string $propertyName   Property name for error messages
     * @param string $schemaId       Schema ID for error messages
     *
     * @throws \Exception If constraint is relaxed
     *
     * @return void
     */
    private function validateConstraintAddition(
        mixed $parentProperty,
        mixed $childProperty,
        string $propertyName,
        string $schemaId
    ): void {
        // If parent had validation and child removes it, that's relaxing.
        if (empty($parentProperty) === false && empty($childProperty) === true) {
            throw new Exception(
                "Schema '{$schemaId}': Property '{$propertyName}' cannot remove constraints "."(parent had value, child is empty)"
            );
        }
    }//end validateConstraintAddition()

    /**
     * Extract the delta (differences) between parent schemas and child schema properties
     *
     * This method is called before saving a schema that uses composition.
     * It removes any properties that are identical to the parent(s), keeping only
     * the differences (delta) in the child schema. This ensures we only store
     * what's actually changed, making schema composition more maintainable.
     *
     * Supports:
     * - allOf: Extracts delta against all parent schemas (merged)
     * - oneOf/anyOf: No delta extraction (properties not merged)
     *
     * @param Schema $schema The schema to extract delta from
     *
     * @throws \Exception If parent schema cannot be loaded
     *
     * @return Schema The schema with only delta properties
     */
    private function extractSchemaDelta(Schema $schema): Schema
    {
        // Get composition patterns.
        $allOf = $schema->getAllOf();
        $oneOf = $schema->getOneOf();
        $anyOf = $schema->getAnyOf();

        // For oneOf and anyOf, no delta extraction (properties not merged).
        if (($oneOf !== null && count($oneOf) > 0)
            || ($anyOf !== null && count($anyOf) > 0)
        ) {
            return $schema;
        }

        // For allOf, extract delta against all parents.
        if ($allOf !== null && count($allOf) > 0) {
            return $this->extractAllOfDelta($schema, $allOf);
        }

        // No composition - return as-is.
        return $schema;
    }//end extractSchemaDelta()

    /**
     * Extract delta for allOf composition (multiple parents)
     *
     * Merges all parent schemas and extracts only the differences
     * in the child schema.
     *
     * @param Schema $schema The child schema
     * @param array  $allOf  Array of parent schema identifiers
     *
     * @throws \Exception If parent schema not found
     *
     * @return Schema Schema with only delta properties
     */
    private function extractAllOfDelta(Schema $schema, array $allOf): Schema
    {
        try {
            // Start with empty merged parent properties.
            $mergedParentProperties = [];
            $mergedParentRequired   = [];

            // Load and merge all parent schemas.
            foreach ($allOf as $parentRef) {
                // Skip empty or null references.
                if (empty($parentRef) === true) {
                    continue;
                }

                $parentSchema = $this->loadSchema($parentRef);

                // Recursively resolve parent to get its full properties.
                if ($parentSchema->getAllOf() !== null) {
                    $parentSchema = $this->resolveSchemaExtension($parentSchema);
                }

                // Merge this parent's properties into the accumulated parent properties.
                $mergedParentProperties = $this->mergeSchemaProperties(
                    $mergedParentProperties,
                    $parentSchema->getProperties()
                    );

                    // Merge required fields.
                    $mergedParentRequired = array_unique(
                    array_merge($mergedParentRequired, $parentSchema->getRequired())
                    );
            }//end foreach

            // Extract only the properties that differ from merged parents.
            $deltaProperties = $this->extractPropertyDelta(
                $mergedParentProperties,
                $schema->getProperties()
            );

            // Extract only the required fields that differ from merged parents.
            $deltaRequired = array_diff(
                $schema->getRequired(),
                $mergedParentRequired
            );

            // Update the schema with delta only.
            $schema->setProperties($deltaProperties);
            $schema->setRequired(array_values($deltaRequired));
            // Re-index array.
            return $schema;
        } catch (Exception $e) {
            throw new Exception("Cannot extract allOf delta: ".$e->getMessage());
        }//end try
    }//end extractAllOfDelta()

    /**
     * Extract properties that differ from parent
     *
     * This method compares child properties with parent properties and returns
     * only the properties that are new or different.
     *
     * @param array $parentProperties Parent schema properties
     * @param array $childProperties  Child schema properties
     *
     * @return array Properties that differ from parent (delta)
     */
    private function extractPropertyDelta(array $parentProperties, array $childProperties): array
    {
        $delta = [];

        foreach ($childProperties as $propertyName => $childProperty) {
            // If property doesn't exist in parent, it's new - include in delta.
            if (isset($parentProperties[$propertyName]) === false) {
                $delta[$propertyName] = $childProperty;
                continue;
            }

            // If property exists in parent, check if it's different.
            $parentProperty = $parentProperties[$propertyName];

            // Deep comparison: if properties are different, include in delta.
            if ($this->arePropertiesDifferent($parentProperty, $childProperty) === true) {
                // For objects with nested properties, extract nested delta.
                if (is_array($childProperty) === false || is_array($parentProperty) === false) {
                    $delta[$propertyName] = $childProperty;
                    continue;
                }

                $delta[$propertyName] = $this->extractNestedPropertyDelta($parentProperty, $childProperty);
            }

            // If properties are identical, don't include in delta.
        }//end foreach

        return $delta;
    }//end extractPropertyDelta()

    /**
     * Check if two property definitions are different
     *
     * Performs deep comparison of property definitions to determine if they differ.
     *
     * @param mixed $parentProperty Parent property definition
     * @param mixed $childProperty  Child property definition
     *
     * @return bool True if properties are different
     */
    private function arePropertiesDifferent($parentProperty, $childProperty): bool
    {
        // Use JSON encoding for deep comparison.
        // This handles arrays, nested objects, and scalar values uniformly.
        return json_encode($parentProperty) !== json_encode($childProperty);
    }//end arePropertiesDifferent()

    /**
     * Extract nested property delta for object properties
     *
     * When a property is an object with nested properties, extract only
     * the nested properties that differ from the parent.
     *
     * @param array $parentProperty Parent property definition
     * @param array $childProperty  Child property definition
     *
     * @return array Property definition with only delta fields
     */
    private function extractNestedPropertyDelta(array $parentProperty, array $childProperty): array
    {
        $delta = [];

        foreach ($childProperty as $key => $value) {
            if (isset($parentProperty[$key]) === false) {
                // New field in child.
                $delta[$key] = $value;
            } else if ($this->arePropertiesDifferent($parentProperty[$key], $value) === true) {
                // Changed field.
                if ($key !== 'properties' || is_array($value) === false || is_array($parentProperty[$key]) === false) {
                    $delta[$key] = $value;
                    continue;
                }

                // Recursively extract delta for nested properties.
                $delta[$key] = $this->extractPropertyDelta($parentProperty[$key], $value);
            }

            // If field is identical, don't include in delta.
        }

        return $delta;
    }//end extractNestedPropertyDelta()

    /**
     * Find schemas that compose with a given schema
     *
     * Returns an array of schema UUIDs for schemas that reference the given schema
     * in their allOf, oneOf, or anyOf composition patterns.
     *
     * @param int|string $schemaIdentifier The ID, UUID, or slug of the schema
     *
     * @return array Array of schema UUIDs that compose with this schema
     *
     * @psalm-return list{0?: mixed,...}
     */
    public function findExtendedBy(int|string $schemaIdentifier): array
    {
        // First, get the target schema to know all its identifiers.
        try {
            $targetSchema = $this->find(id: $schemaIdentifier);
        } catch (Exception $e) {
            // If schema not found, register: return empty array.
            return [];
        }

        $targetId   = (string) $targetSchema->getId();
        $targetUuid = $targetSchema->getUuid();
        $targetSlug = $targetSchema->getSlug();

        // Build query to find schemas that reference this schema in composition.
        $qb = $this->db->getQueryBuilder();
        $qb->select('uuid')
            ->from($this->getTableName());

        // Add conditions for all possible ways to reference the schema.
        $orConditions = [];

        // Check in allOf field (JSON array).
        if ($targetId !== '') {
            $orConditions[] = $qb->expr()->like('all_of', $qb->createNamedParameter('%"'.$targetId.'"%'));
        }

        if ($targetUuid !== null && $targetUuid !== '') {
            $orConditions[] = $qb->expr()->like('all_of', $qb->createNamedParameter('%"'.$targetUuid.'"%'));
        }

        if ($targetSlug !== null && $targetSlug !== '') {
            $orConditions[] = $qb->expr()->like('all_of', $qb->createNamedParameter('%"'.$targetSlug.'"%'));
        }

        // Check in oneOf field (JSON array).
        if ($targetId !== '') {
            $orConditions[] = $qb->expr()->like('one_of', $qb->createNamedParameter('%"'.$targetId.'"%'));
        }

        if ($targetUuid !== null && $targetUuid !== '') {
            $orConditions[] = $qb->expr()->like('one_of', $qb->createNamedParameter('%"'.$targetUuid.'"%'));
        }

        if ($targetSlug !== null && $targetSlug !== '') {
            $orConditions[] = $qb->expr()->like('one_of', $qb->createNamedParameter('%"'.$targetSlug.'"%'));
        }

        // Check in anyOf field (JSON array).
        // Note: $targetId is cast to (string), so it can never be null, only empty string.
        if ($targetId !== '') {
            $orConditions[] = $qb->expr()->like('any_of', $qb->createNamedParameter('%"'.$targetId.'"%'));
        }

        if ($targetUuid !== null && $targetUuid !== '') {
            $orConditions[] = $qb->expr()->like('any_of', $qb->createNamedParameter('%"'.$targetUuid.'"%'));
        }

        if ($targetSlug !== null && $targetSlug !== '') {
            $orConditions[] = $qb->expr()->like('any_of', $qb->createNamedParameter('%"'.$targetSlug.'"%'));
        }

        if (empty($orConditions) === true) {
            return [];
        }

        $qb->where($qb->expr()->orX(...$orConditions));

        $result = $qb->executeQuery();
        $uuids  = [];

        while (($row = $result->fetch()) !== false) {
            if (($row['uuid'] ?? null) !== null) {
                $uuids[] = $row['uuid'];
            }
        }

        $result->closeCursor();

        return $uuids;
    }//end findExtendedBy()
}//end class
