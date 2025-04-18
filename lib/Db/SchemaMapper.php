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
use OCA\OpenRegister\Service\SchemaPropertyValidator;

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
     * @var SchemaPropertyValidator
     */
    private $validator;

    /**
     * Constructor for the SchemaMapper
     *
     * @param IDBConnection          $db              The database connection
     * @param IEventDispatcher       $eventDispatcher The event dispatcher
     * @param SchemaPropertyValidator $validator       The schema property validator
     */
    public function __construct(
        IDBConnection $db,
        IEventDispatcher $eventDispatcher,
        SchemaPropertyValidator $validator
    ) {
        parent::__construct($db, 'openregister_schemas');
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }


    /**
     * Finds a schema by id
     *
     * @param int|string $id The id of the schema
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the schema does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple schemas are found
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return Schema The schema
     */
    public function find(string | int $id): Schema
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_schemas')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
                )
            );

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
                // Catch all exceptions but do nothing
            }
        }

        return $result;

    }//end findMultiple()


    /**
     * Finds all schemas
     *
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions The search conditions to apply
     * @param array|null $searchParams     The search parameters to apply
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array The schemas
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[]
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

    }//end cleanObject()


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

        // Update version if not set in the object array.
        if (empty($object['version']) === true) {
            // Split the version into major, minor, and patch.
            $version = explode('.', $schema->getVersion());
            // Increment the patch version.
            if (isset($version[2]) === true) {
                $version[2]        = ((int) $version[2] + 1);
                // Reassemble the version string.
                $object['version'] = implode('.', $version);
            }
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
        $result = parent::delete($schema);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(
            new SchemaDeletedEvent($schema)
        );

        return $result;

    }//end delete()


}//end class
