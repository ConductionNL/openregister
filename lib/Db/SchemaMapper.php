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
        $this->eventDispatcher    = $eventDispatcher;
        $this->validator          = $validator;

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

        $properties             = ($schema->getProperties() ?? []);
        $propertyKeys           = array_keys($properties);
        $configuration          = $schema->getConfiguration() ?? [];
        $objectNameField        = $configuration['objectNameField'] ?? '';
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
                if ($requiredValue === true ||
                    $requiredValue === 'true' ||
                    (is_string($requiredValue) === true && strtolower(trim($requiredValue)) === 'true')) {
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
     * @param array &$properties The properties array to check
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
                } elseif (is_object($property['$ref']) && isset($property['$ref']->id)) {
                    $property['$ref'] = $property['$ref']->id;
                } elseif (is_int($property['$ref'])) {

                }
                elseif (!is_string($property['$ref']) && $property['$ref'] !== '') {
                    throw new \Exception("Schema property '$key' has a \$ref that is not a string or empty: " . print_r($property['$ref'], true));
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
        }
    }


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

        $result = $qb->execute();
        $mappings = [];
        while ($row = $result->fetch()) {
            $mappings[$row['id']] = $row['slug'];
        }
        return $mappings;
    }

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

        $result = $qb->execute();
        $mappings = [];
        while ($row = $result->fetch()) {
            $mappings[$row['slug']] = $row['id'];
        }
        return $mappings;
    }


}//end class
