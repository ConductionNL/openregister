<?php
/**
 * OpenRegister Register Mapper
 *
 * This file contains the class for handling register mapper related operations
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

use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * The RegisterMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class RegisterMapper extends QBMapper
{

    /**
     * The schema mapper instance
     *
     * @var SchemaMapper
     */
    private $schemaMapper;

    /**
     * The event dispatcher instance
     *
     * @var IEventDispatcher
     */
    private $eventDispatcher;


    /**
     * Constructor for RegisterMapper
     *
     * @param IDBConnection    $db              The database connection
     * @param SchemaMapper     $schemaMapper    The schema mapper
     * @param IEventDispatcher $eventDispatcher The event dispatcher
     *
     * @return void
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
     * @param int|string $id The ID of the register to find
     *
     * @return Register The found register
     */
    public function find(string | int $id): Register
    {
        $qb = $this->db->getQueryBuilder();

        // Build the query.
        $qb->select('*')
            ->from('openregister_registers')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
                )
            );

        // Execute the query and return the result.
        return $this->findEntity(query: $qb);

    }//end find()


    /**
     * Find all registers
     *
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions Array of search conditions
     * @param array|null $searchParams     Array of search parameters
     *
     * @return array Array of found registers
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[]
    ): array {
        $qb = $this->db->getQueryBuilder();

        // Build the base query.
        $qb->select('*')
            ->from('openregister_registers')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // Apply filters.
        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        // Apply search conditions.
        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Execute the query and return the results.
        return $this->findEntities(query: $qb);

    }//end findAll()


    /**
     * Insert a new entity
     *
     * @param Entity $entity The entity to insert
     *
     * @return Entity The inserted entity
     */
    public function insert(Entity $entity): Entity
    {
        $entity = parent::insert($entity);

        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new RegisterCreatedEvent($entity));

        return $entity;

    }//end insert()


    /**
     * Ensures that a register object has a UUID and a slug.
     *
     * @param Register $register The register object to clean
     *
     * @return void
     */
    private function cleanObject(Register $register): void
    {
        // Check if UUID is set, if not, generate a new one.
        if ($register->getUuid() === null) {
            $register->setUuid(Uuid::v4());
        }

        // Ensure the object has a slug.
        if (empty($register->getSlug()) === true) {
            // Convert to lowercase and replace spaces with dashes.
            $slug = strtolower(trim($register->getTitle()));
            // Assuming title is used for slug.
            // Remove special characters.
            $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
            // Remove multiple dashes.
            $slug = preg_replace('/-+/', '-', $slug);
            // Remove leading/trailing dashes.
            $slug = trim($slug, '-');

            $register->setSlug($slug);
        }

        // Ensure the object has a version.
        if ($register->getVersion() === null) {
            $register->setVersion('0.0.1');
        }

    }//end cleanObject()


    /**
     * Create a new register from an array of data
     *
     * @param array $object The data to create the register from
     *
     * @return Register The created register
     */
    public function createFromArray(array $object): Register
    {
        $register = new Register();
        $register->hydrate(object: $object);

        // Clean the register object to ensure UUID, slug, and version are set.
        $this->cleanObject($register);

        $register = $this->insert(entity: $register);

        return $register;

    }//end createFromArray()


    /**
     * Update an entity
     *
     * @param Entity $entity The entity to update
     *
     * @return Entity The updated entity
     */
    public function update(Entity $entity): Entity
    {
        $oldSchema = $this->find($entity->getId());

        // Clean the register object to ensure UUID, slug, and version are set.
        $this->cleanObject($entity);

        $entity = parent::update($entity);

        // Dispatch update event.
        $this->eventDispatcher->dispatchTyped(new RegisterUpdatedEvent($entity, $oldSchema));

        return $entity;

    }//end update()


    /**
     * Update an existing register from an array of data
     *
     * @param int   $id     The ID of the register to update
     * @param array $object The new data for the register
     *
     * @return Register The updated register
     */
    public function updateFromArray(int $id, array $object): Register
    {
        $register = $this->find($id);

        // Update version if not set in the object array.
        if (empty($object['version']) === true) {
            // Split the version into major, minor, and patch.
            $version = explode('.', $register->getVersion());
            // Increment the patch version.
            if (isset($version[2]) === true) {
                $version[2]        = ((int) $version[2] + 1);
                // Reassemble the version string.
                $object['version'] = implode('.', $version);
            }
        }

        $register->hydrate($object);

        // Clean the register object to ensure UUID, slug, and version are set.
        $this->cleanObject($register);

        $register = $this->update($register);

        return $register;

    }//end updateFromArray()


    /**
     * Delete a register
     *
     * @param Register $entity The register to delete
     *
     * @return Register The deleted register
     */
    public function delete(Entity $entity): Register
    {
        $result = parent::delete($entity);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(
            new RegisterDeletedEvent($entity)
        );

        return $result;

    }//end delete()


    /**
     * Get all schemas associated with a register
     *
     * @param int $registerId The ID of the register
     *
     * @return array Array of schemas
     */
    public function getSchemasByRegisterId(int $registerId): array
    {
        $register  = $this->find($registerId);
        $schemaIds = $register->getSchemas();

        $schemas = [];

        // Fetch each schema by its ID.
        foreach ($schemaIds as $schemaId) {
            $schemas[] = $this->schemaMapper->find((int) $schemaId);
        }

        return $schemas;

    }//end getSchemasByRegisterId()


    /**
     * Check if a register has a schema with a specific title
     *
     * @param int    $registerId  The ID of the register
     * @param string $schemaTitle The title of the schema to look for
     *
     * @return Schema|null The schema if found, null otherwise
     */
    public function hasSchemaWithTitle(int $registerId, string $schemaTitle): ?Schema
    {
        $schemas = $this->getSchemasByRegisterId($registerId);

        // Check each schema for a matching title.
        foreach ($schemas as $schema) {
            if ($schema->getTitle() === $schemaTitle) {
                return $schema;
            }
        }

        return null;

    }//end hasSchemaWithTitle()


}//end class
