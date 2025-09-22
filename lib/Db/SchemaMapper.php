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
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Service\SchemaPropertyValidatorService;
use OCA\OpenRegister\Db\ObjectEntityMapper;

/**
 * The SchemaMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class SchemaMapper extends QBMapper
{

    /**
     * The event dispatcher instance
     *
     * @var IEventDispatcher
     */
    private $eventDispatcher;

    /**
     * The schema property validator instance
     *
     * @var SchemaPropertyValidatorService
     */
    private $validator;


    /**
     * Constructor for the SchemaMapper
     *
     * @param IDBConnection                  $db              The database connection
     * @param IEventDispatcher               $eventDispatcher The event dispatcher
     * @param SchemaPropertyValidatorService $validator       The schema property validator
     */
    public function __construct(
        IDBConnection $db,
        IEventDispatcher $eventDispatcher,
        SchemaPropertyValidatorService $validator
    ) {
        parent::__construct($db, 'openregister_schemas');
        $this->eventDispatcher = $eventDispatcher;
        $this->validator       = $validator;

    }//end __construct()


    /**
     * Finds a schema by id, with optional extension for statistics
     *
     * @param int|string $id     The id of the schema
     * @param array      $extend Optional array of extensions (e.g., ['@self.stats'])
     *
     * @return Schema The schema, possibly with stats
     */
    public function find(string | int $id, ?array $extend=[]): Schema
    {
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
        // Just return the entity; do not attach stats here
        return $this->findEntity(query: $qb);

    }//end find()


    /**
     * Finds multiple schemas by id
     *
     * @param array $ids The ids of the schemas
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If a schema does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple schemas are found
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @todo: refactor this into find all
     *
     * @return array The schemas
     */
    public function findMultiple(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            try {
                $result[] = $this->find($id);
            } catch (\OCP\AppFramework\Db\DoesNotExistException | \OCP\AppFramework\Db\MultipleObjectsReturnedException | \OCP\DB\Exception) {
                // Catch all exceptions but do nothing.
            }
        }

        return $result;

    }//end findMultiple()

    /**
     * Find multiple schemas by IDs using a single optimized query
     *
     * This method performs a single database query to fetch multiple schemas,
     * significantly improving performance compared to individual queries.
     *
     * @param array $ids Array of schema IDs to find
     * @return array Associative array of ID => Schema entity
     */
    public function findMultipleOptimized(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_schemas')
            ->where(
                $qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY))
            );

        $result = $qb->executeQuery();
        $schemas = [];
        
        while ($row = $result->fetch()) {
            $schema = new Schema();
            $schema = $schema->fromRow($row);
            $schemas[$row['id']] = $schema;
        }
        
        return $schemas;
    }//end findMultipleOptimized()


    /**
     * Finds all schemas, with optional extension for statistics
     *
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions The search conditions to apply
     * @param array|null $searchParams     The search parameters to apply
     * @param array      $extend           Optional array of extensions (e.g., ['@self.stats'])
     *
     * @return array The schemas, possibly with stats
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        ?array $extend=[]
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_schemas')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Just return the entities; do not attach stats here
        return $this->findEntities(query: $qb);

    }//end findAll()


    /**
     * Inserts a schema entity into the database
     *
     * @param Entity $entity The entity to insert
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return Entity The inserted entity
     */
    public function insert(Entity $entity): Entity
    {
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
        // Enforce $ref is always a string in all properties and array items
        $properties = $schema->getProperties() ?? [];
        $this->enforceRefIsStringRecursive($properties);
        $schema->setProperties($properties);

        // Check if UUID is set, if not, generate a new one.
        if ($schema->getUuid() === null) {
            $schema->setUuid(Uuid::v4());
        }

        // Ensure the object has a slug.
        if (empty($schema->getSlug()) === true) {
            // Convert to lowercase and replace spaces with dashes.
            $slug = strtolower(trim($schema->getTitle()));
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

        // If an object name field is provided, it must exist in the properties
        if (empty($objectNameField) === false && in_array($objectNameField, $propertyKeys) === false) {
            throw new \Exception("The value for objectNameField ('$objectNameField') does not exist as a property in the schema.");
        }

        // If an object description field is provided, it must exist in the properties
        if (empty($objectDescriptionField) === false && in_array($objectDescriptionField, $propertyKeys) === false) {
            throw new \Exception("The value for objectDescriptionField ('$objectDescriptionField') does not exist as a property in the schema.");
        }

        // Establish the required fields based on the properties
        // Empty the required array and rebuild it based on property requirements
        $requiredFields = [];
        foreach ($properties as $propertyKey => $property) {
            // Check if the property has a 'required' field set to true or the string 'true'
            if (isset($property['required']) === true) {
                $requiredValue = $property['required'];
                if ($requiredValue === true
                    || $requiredValue === 'true'
                    || (is_string($requiredValue) === true && strtolower(trim($requiredValue)) === 'true')
                ) {
                    $requiredFields[] = $propertyKey;
                }
            }
        }

        // Set the required fields on the schema
        $schema->setRequired($requiredFields);

        // If the object name field is empty, try to find a logical key
        if (empty($objectNameField) === true) {
            $nameKeys = [
                'name',
                'naam',
                'title',
                'titel',
            ];
            foreach ($nameKeys as $key) {
                if (in_array($key, $propertyKeys) === true) {
                    // Update the configuration array
                    $configuration['objectNameField'] = $key;
                    $schema->setConfiguration($configuration);
                    break;
                }
            }
        }

        // If the object description field is empty, try to find a logical key
        if (empty($objectDescriptionField) === true) {
            $descriptionKeys = [
                'description',
                'beschrijving',
                'omschrijving',
                'summary',
            ];
            foreach ($descriptionKeys as $key) {
                if (in_array($key, $propertyKeys) === true) {
                    // Update the configuration array
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
     * @param  array &$properties The properties array to check
     * @throws \Exception If $ref is not a string or cannot be converted
     */
    private function enforceRefIsStringRecursive(array &$properties): void
    {
        foreach ($properties as $key => &$property) {
            // If property is not an array, skip
            if (!is_array($property)) {
                continue;
            }

            // Check $ref at this level
            if (isset($property['$ref'])) {
                if (is_array($property['$ref']) && isset($property['$ref']['id'])) {
                    $property['$ref'] = $property['$ref']['id'];
                } else if (is_object($property['$ref']) && isset($property['$ref']->id)) {
                    $property['$ref'] = $property['$ref']->id;
                } else if (is_int($property['$ref'])) {
                } else if (!is_string($property['$ref']) && $property['$ref'] !== '') {
                    throw new \Exception("Schema property '$key' has a \$ref that is not a string or empty: ".print_r($property['$ref'], true));
                }
            }

            // Check array items recursively
            if (isset($property['items']) && is_array($property['items'])) {
                $this->enforceRefIsStringRecursive($property['items']);
            }

            // Check nested properties recursively
            if (isset($property['properties']) && is_array($property['properties'])) {
                $this->enforceRefIsStringRecursive($property['properties']);
            }
        }//end foreach

    }//end enforceRefIsStringRecursive()


    /**
     * Creates a schema from an array
     *
     * @param array $object The object to create
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws Exception If property validation fails
     *
     * @return Schema The created schema
     */
    public function createFromArray(array $object): Schema
    {
        $schema = new Schema();
        $schema->hydrate($object, $this->validator);

        // Clean the schema object to ensure UUID, slug, and version are set.
        $this->cleanObject($schema);

        // **PERFORMANCE OPTIMIZATION**: Generate facet configuration from schema properties
        $this->generateFacetConfiguration($schema);

        $schema = $this->insert($schema);

        return $schema;

    }//end createFromArray()


    /**
     * Updates a schema entity in the database
     *
     * @param Entity $entity The entity to update
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple entities are found
     *
     * @return Entity The updated entity
     */
    public function update(Entity $entity): Entity
    {
        $oldSchema = $this->find($entity->getId());

        // Clean the schema object to ensure UUID, slug, and version are set.
        $this->cleanObject($entity);

        // **PERFORMANCE OPTIMIZATION**: Generate facet configuration from schema properties
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
     * @throws Exception If property validation fails
     *
     * @return Schema The updated schema
     */
    public function updateFromArray(int $id, array $object): Schema
    {
        $schema = $this->find($id);

        // Set or update the version.
        if (isset($object['version']) === false) {
            $version    = explode('.', $schema->getVersion());
            $version[2] = ((int) $version[2] + 1);
            $schema->setVersion(implode('.', $version));
        }

        $schema->hydrate($object, $this->validator);

        // Clean the schema object to ensure UUID, slug, and version are set.
        $this->cleanObject($schema);

        $schema = $this->update($schema);

        return $schema;

    }//end updateFromArray()


    /**
     * Delete a schema
     *
     * @param Entity $schema The schema to delete
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return Schema The deleted schema
     */
    public function delete(Entity $schema): Schema
    {
        // Proceed with deletion directly - no need to check stats on deletion
        $result = parent::delete($schema);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(
            new SchemaDeletedEvent($schema)
        );

        return $result;

    }//end delete()


    /**
     * Get the number of registers associated with each schema
     *
     * This method returns an associative array where the key is the schema ID and the value is the number of registers that reference that schema.
     *
     * @phpstan-return array<int,int>  Associative array of schema ID => register count
     * @psalm-return   array<int,int>    Associative array of schema ID => register count
     *
     * @return array<int,int> Associative array of schema ID => register count
     */
    public function getRegisterCountPerSchema(): array
    {
        // TODO: Optimize for large datasets (current approach loads all registers into memory)
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'schemas')
            ->from('openregister_registers');
        $result = $qb->executeQuery()->fetchAll();

        $counts = [];
        foreach ($result as $row) {
            // Decode the schemas JSON array for each register
            $schemas = json_decode($row['schemas'], true) ?: [];
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

        $result   = $qb->execute();
        $mappings = [];
        while ($row = $result->fetch()) {
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

        $result   = $qb->execute();
        $mappings = [];
        while ($row = $result->fetch()) {
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
     * @return array<Schema> Array of schemas that reference the target schema
     */
    public function getRelated(Schema|int|string $schema): array
    {
        // If we received a Schema entity, get its ID, otherwise find the schema
        if ($schema instanceof Schema) {
            $targetSchemaId   = (string) $schema->getId();
            $targetSchemaUuid = $schema->getUuid();
            $targetSchemaSlug = $schema->getSlug();
        } else {
            // Find the target schema to get all its identifiers
            $targetSchema     = $this->find($schema);
            $targetSchemaId   = (string) $targetSchema->getId();
            $targetSchemaUuid = $targetSchema->getUuid();
            $targetSchemaSlug = $targetSchema->getSlug();
        }

        // Get all schemas to search through their properties
        $allSchemas     = $this->findAll();
        $relatedSchemas = [];

        foreach ($allSchemas as $currentSchema) {
            // Skip the target schema itself
            if ($currentSchema->getId() === (int) $targetSchemaId) {
                continue;
            }

            // Get the properties of the current schema
            $properties = $currentSchema->getProperties() ?? [];

            // Search for references to the target schema
            if ($this->hasReferenceToSchema($properties, $targetSchemaId, $targetSchemaUuid, $targetSchemaSlug)) {
                $relatedSchemas[] = $currentSchema;
            }
        }

        return $relatedSchemas;

    }//end getRelated()


    /**
     * Recursively check if properties contain a reference to the target schema
     *
     * This method searches through properties recursively to find $ref values
     * that match the target schema's ID, UUID, or slug.
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
            // Skip non-array properties
            if (!is_array($property)) {
                continue;
            }

            // Check if this property has a $ref that matches our target schema
            if (isset($property['$ref'])) {
                $ref = $property['$ref'];

                // Check exact matches first
                if ($ref === $targetSchemaId
                    || $ref === $targetSchemaUuid
                    || $ref === $targetSchemaSlug
                    || $ref === (int) $targetSchemaId
                ) {
                    return true;
                }

                // Check if the ref contains the target schema slug in JSON Schema format
                // Format: "#/components/schemas/slug" or "components/schemas/slug" etc.
                if (is_string($ref) && !empty($targetSchemaSlug)) {
                    if (str_contains($ref, '/schemas/'.$targetSchemaSlug)
                        || str_contains($ref, 'schemas/'.$targetSchemaSlug)
                        || str_ends_with($ref, '/'.$targetSchemaSlug)
                    ) {
                        return true;
                    }
                }

                // Check if the ref contains the target schema UUID
                if (is_string($ref) && !empty($targetSchemaUuid)) {
                    if (str_contains($ref, $targetSchemaUuid)) {
                        return true;
                    }
                }
            }//end if

            // Recursively check nested properties
            if (isset($property['properties']) && is_array($property['properties'])) {
                if ($this->hasReferenceToSchema($property['properties'], $targetSchemaId, $targetSchemaUuid, $targetSchemaSlug)) {
                    return true;
                }
            }

            // Check array items for references
            if (isset($property['items']) && is_array($property['items'])) {
                if ($this->hasReferenceToSchema([$property['items']], $targetSchemaId, $targetSchemaUuid, $targetSchemaSlug)) {
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
        $properties = $schema->getProperties() ?? [];
        $facetConfig = [];
        
        // Add metadata facets (always available)
        $facetConfig['@self'] = [
            'register' => ['type' => 'terms'],
            'schema' => ['type' => 'terms'], 
            'created' => ['type' => 'date_histogram', 'interval' => 'month'],
            'updated' => ['type' => 'date_histogram', 'interval' => 'month'],
            'published' => ['type' => 'date_histogram', 'interval' => 'month'],
            'owner' => ['type' => 'terms']
        ];
        
        // Analyze properties for facetable fields
        foreach ($properties as $fieldName => $property) {
            if (!is_array($property)) {
                continue;
            }
            
            $facetType = $this->determineFacetTypeForProperty($property, $fieldName);
            if ($facetType !== null) {
                $facetConfig[$fieldName] = ['type' => $facetType];
                
                // Add interval for date histograms
                if ($facetType === 'date_histogram') {
                    $facetConfig[$fieldName]['interval'] = 'month';
                }
            }
        }
        
        // Store the facet configuration in the schema
        if (!empty($facetConfig)) {
            $schema->setFacets($facetConfig);
        }
        
    }//end generateFacetConfiguration()


    /**
     * Determine the appropriate facet type for a schema property
     *
     * **PERFORMANCE OPTIMIZATION**: Smart detection of facetable fields based on
     * property characteristics, names, and explicit facetable markers.
     *
     * @param array  $property  The property definition
     * @param string $fieldName The field name
     *
     * @return string|null The facet type ('terms', 'date_histogram') or null if not facetable
     */
    private function determineFacetTypeForProperty(array $property, string $fieldName): ?string
    {
        // Check if explicitly marked as facetable
        if (isset($property['facetable']) && 
            ($property['facetable'] === true || $property['facetable'] === 'true' || 
             (is_string($property['facetable']) && strtolower(trim($property['facetable'])) === 'true'))
        ) {
            return $this->determineFacetTypeFromProperty($property);
        }
        
        // Auto-detect common facetable field names
        $commonFacetableFields = [
            'type', 'status', 'category', 'tags', 'label', 'group', 
            'department', 'location', 'priority', 'state', 'classification',
            'genre', 'brand', 'model', 'version', 'license', 'language'
        ];
        
        $lowerFieldName = strtolower($fieldName);
        if (in_array($lowerFieldName, $commonFacetableFields)) {
            return $this->determineFacetTypeFromProperty($property);
        }
        
        // Auto-detect enum properties (good for faceting)
        if (isset($property['enum']) && is_array($property['enum']) && count($property['enum']) > 0) {
            return 'terms';
        }
        
        // Auto-detect date/datetime fields
        $propertyType = $property['type'] ?? '';
        if (in_array($propertyType, ['date', 'datetime', 'date-time'])) {
            return 'date_histogram';
        }
        
        // Check for date-like field names
        $dateFields = ['created', 'updated', 'modified', 'date', 'time', 'timestamp'];
        foreach ($dateFields as $dateField) {
            if (str_contains($lowerFieldName, $dateField)) {
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
     */
    private function determineFacetTypeFromProperty(array $property): string
    {
        $propertyType = $property['type'] ?? 'string';
        
        // Date/datetime properties use date_histogram
        if (in_array($propertyType, ['date', 'datetime', 'date-time'])) {
            return 'date_histogram';
        }
        
        // Enum properties use terms
        if (isset($property['enum']) && is_array($property['enum'])) {
            return 'terms';
        }
        
        // Boolean, integer, number with small ranges use terms
        if (in_array($propertyType, ['boolean', 'integer', 'number'])) {
            return 'terms';
        }
        
        // Default to terms for other types
        return 'terms';
        
    }//end determineFacetTypeFromProperty()


}//end class
