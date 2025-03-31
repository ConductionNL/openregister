<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;

/**
 * The RegisterMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class RegisterMapper extends QBMapper
{

    private $schemaMapper;

    private $eventDispatcher;


    /**
     * Constructor for RegisterMapper
     *
     * @param IDBConnection    $db              The database connection
     * @param SchemaMapper     $schemaMapper    The schema mapper
     * @param IEventDispatcher $eventDispatcher The event dispatcher
     */
    public function __construct(
        IDBConnection $db,
        SchemaMapper $schemaMapper,
        IEventDispatcher $eventDispatcher
    ) {
        parent::__construct($db, 'openregister_registers');
        $this->schemaMapper    = $schemaMapper;
        $this->eventDispatcher = $eventDispatcher;

    }//end __construct()


    /**
     * Find a register by its ID
     *
     * @param  int $id The ID of the register to find
     * @return Register The found register
     */
    public function find(string | int $id): Register
    {
        $qb = $this->db->getQueryBuilder();

        // Build the query
        $qb->select('*')
            ->from('openregister_registers')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
                )
            );

        // Execute the query and return the result
        return $this->findEntity(query: $qb);

    }//end find()


    /**
     * Find all registers with optional filtering and searching
     *
     * @param  int|null   $limit            Maximum number of results to return
     * @param  int|null   $offset           Number of results to skip
     * @param  array|null $filters          Associative array of filters
     * @param  array|null $searchConditions Array of search conditions
     * @param  array|null $searchParams     Array of search parameters
     * @return array Array of found registers
     */
    public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[], ?array $searchConditions=[], ?array $searchParams=[]): array
    {
        $qb = $this->db->getQueryBuilder();

        // Build the base query
        $qb->select('*')
            ->from('openregister_registers')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // Apply filters
        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        // Apply search conditions
        if (!empty($searchConditions)) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Execute the query and return the results
        return $this->findEntities(query: $qb);

    }//end findAll()


    /**
     * @inheritdoc
     */
    public function insert(Entity $entity): Entity
    {
        $entity = parent::insert($entity);

        // Dispatch creation event
        $this->eventDispatcher->dispatchTyped(new RegisterCreatedEvent($entity));

        return $entity;

    }//end insert()


    /**
     * Ensures that a register object has a UUID and a slug.
     *
     * @param  Register $register The register object to clean
     * @return void
     */
    private function cleanObject(Register $register): void
    {
        // Check if UUID is set, if not, generate a new one
        if ($register->getUuid() === null) {
            $register->setUuid(Uuid::v4());
        }

        // Ensure the object has a slug
        if (empty($register->getSlug()) === true) {
            // Convert to lowercase and replace spaces with dashes
            $slug = strtolower(trim($register->getTitle()));
            // Assuming title is used for slug
            // Remove special characters
            $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
            // Remove multiple dashes
            $slug = preg_replace('/-+/', '-', $slug);
            // Remove leading/trailing dashes
            $slug = trim($slug, '-');

            $register->setSlug($slug);
        }

        // Ensure the object has a version
        if ($register->getVersion() === null) {
            $register->setVersion('1.0.0');
        } else {
            // Split the version into major, minor, and patch
            $versionParts = explode('.', $register->getVersion());
            // Increment the patch version
            $versionParts[2] = ((int) $versionParts[2] + 1);
            // Reassemble the version string
            $newVersion = implode('.', $versionParts);
            $register->setVersion($newVersion);
        }

    }//end cleanObject()


    /**
     * Create a new register from an array of data
     *
     * @param  array $object The data to create the register from
     * @return Register The created register
     */
    public function createFromArray(array $object): Register
    {
        $register = new Register();
        $register->hydrate(object: $object);

        // Clean the register object to ensure UUID, slug, and version are set
        $this->cleanObject($register);

        $register = $this->insert(entity: $register);

        return $register;

    }//end createFromArray()


    /**
     * @inheritdoc
     */
    public function update(Entity $entity): Entity
    {
        $oldSchema = $this->find($entity->getId());

        // Clean the register object to ensure UUID, slug, and version are set
        $this->cleanObject($entity);

        $entity = parent::update($entity);

        // Dispatch update event
        $this->eventDispatcher->dispatchTyped(new RegisterUpdatedEvent($entity, $oldSchema));

        return $entity;

    }//end update()


    /**
     * Update an existing register from an array of data
     *
     * @param  int   $id     The ID of the register to update
     * @param  array $object The new data for the register
     * @return Register The updated register
     */
    public function updateFromArray(int $id, array $object): Register
    {
        $newRegister = $this->find($id);
        $newRegister->hydrate($object);

        // Clean the register object to ensure UUID, slug, and version are set
        $this->cleanObject($newRegister);

        $newRegister = $this->update($newRegister);

        return $newRegister;

    }//end updateFromArray()


    /**
     * Delete a register
     *
     * @param  Register $entity The register to delete
     * @return Register The deleted register
     */
    public function delete(Entity $entity): Register
    {
        $result = parent::delete($entity);

        // Dispatch deletion event
        $this->eventDispatcher->dispatchTyped(
            new RegisterDeletedEvent($entity)
        );

        return $result;

    }//end delete()


    /**
     * Get all schemas associated with a register
     *
     * @param  int $registerId The ID of the register
     * @return array Array of schemas
     */
    public function getSchemasByRegisterId(int $registerId): array
    {
        $register  = $this->find($registerId);
        $schemaIds = $register->getSchemas();

        $schemas = [];

        // Fetch each schema by its ID
        foreach ($schemaIds as $schemaId) {
            $schemas[] = $this->schemaMapper->find((int) $schemaId);
        }

        return $schemas;

    }//end getSchemasByRegisterId()


    /**
     * Check if a register has a schema with a specific title
     *
     * @param  int    $registerId  The ID of the register
     * @param  string $schemaTitle The title of the schema to look for
     * @return Schema|bool The schema if found, false otherwise
     */
    public function hasSchemaWithTitle(int $registerId, string $schemaTitle): (Schema | bool)
    {
        $schemas = $this->getSchemasByRegisterId($registerId);

        // Check each schema for a matching title
        foreach ($schemas as $schema) {
            if ($schema->getTitle() === $schemaTitle) {
                return $schema;
            }
        }

        return false;

    }//end hasSchemaWithTitle()


}//end class
