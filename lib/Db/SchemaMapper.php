<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;

/**
 * The SchemaMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class SchemaMapper extends QBMapper
{
	private $eventDispatcher;

	/**
	 * Constructor for the SchemaMapper
	 *
	 * @param IDBConnection $db The database connection
	 * @param IEventDispatcher $eventDispatcher The event dispatcher
	 */
	public function __construct(
		IDBConnection $db,
		IEventDispatcher $eventDispatcher
	) {
		parent::__construct($db, 'openregister_schemas');
		$this->eventDispatcher = $eventDispatcher;
	}

	/**
	 * Finds a schema by id
	 *
	 * @param int $id The id of the schema
	 * @return Schema The schema
	 */
	public function find(int $id): Schema
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
	}

	/**
	 * Finds multiple schemas by id
	 *
	 * @param array $ids The ids of the schemas
	 * @return array The schemas
	 */
	public function findMultiple(array $ids): array
	{
		$result = [];
		foreach ($ids as $id) {
			$result[] = $this->find($id);
		}

		return $result;
	}


	/**
	 * Finds all schemas
	 *
	 * @param int|null $limit The limit of the results
	 * @param int|null $offset The offset of the results
	 * @param array|null $filters The filters to apply
	 * @param array|null $searchConditions The search conditions to apply
	 * @param array|null $searchParams The search parameters to apply
	 * @return array The schemas
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_schemas')
			->setMaxResults($limit)
			->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
			if ($value === 'IS NOT NULL') {
				$qb->andWhere($qb->expr()->isNotNull($filter));
			} elseif ($value === 'IS NULL') {
				$qb->andWhere($qb->expr()->isNull($filter));
			} else {
				$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
			}
        }

        if (!empty($searchConditions)) {
            $qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

		return $this->findEntities(query: $qb);
	}

	/**
	 * @inheritdoc
	 */
	public function insert(Entity $entity): Entity
	{
		$entity = parent::insert($entity);

		// Dispatch creation event
		$this->eventDispatcher->dispatchTyped(new SchemaCreatedEvent($entity));

		return $entity;
	}

	/**
	 * Ensures that a schema object has a UUID and a slug.
	 *
	 * @param Schema $schema The schema object to clean
	 * @return void
	 */
	private function cleanObject(Schema $schema): void
	{
		// Check if UUID is set, if not, generate a new one
		if ($schema->getUuid() === null) {
			$schema->setUuid(Uuid::v4());
		}

		// Ensure the object has a slug
		if (empty($schema->getSlug()) === true) {
			// Convert to lowercase and replace spaces with dashes
			$slug = strtolower(trim($schema->getTitle())); // Assuming title is used for slug
			// Remove special characters
			$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
			// Remove multiple dashes
			$slug = preg_replace('/-+/', '-', $slug);
			// Remove leading/trailing dashes
			$slug = trim($slug, '-');

			$schema->setSlug($slug);
		}
		
		// Ensure the object has a version
		if ($schema->getVersion() === null) {
			$schema->setVersion('1.0.0');
		} else {
			// Split the version into major, minor, and patch
			$versionParts = explode('.', $schema->getVersion());
			// Increment the patch version
			$versionParts[2] = (int)$versionParts[2] + 1;
			// Reassemble the version string
			$newVersion = implode('.', $versionParts);
			$schema->setVersion($newVersion);
		}
	}

	/**
	 * Creates a schema from an array
	 *
	 * @param array $object The object to create
	 * @return Schema The created schema
	 */
	public function createFromArray(array $object): Schema
	{
		$schema = new Schema();
		$schema->hydrate(object: $object);

		// Clean the schema object to ensure UUID, slug, and version are set
		$this->cleanObject($schema);

		$schema = $this->insert(entity: $schema);

		return $schema;
	}

	/**
	 * @inheritdoc
	 */
	public function update(Entity $entity): Entity
	{
		$oldSchema = $this->find($entity->getId());

		// Clean the schema object to ensure UUID, slug, and version are set
		$this->cleanObject($entity);

		$entity = parent::update($entity);

		// Dispatch update event
		$this->eventDispatcher->dispatchTyped(new SchemaUpdatedEvent($entity, $oldSchema));

		return $entity;
	}

	/**
	 * Updates a schema from an array
	 *
	 * @param int $id The id of the schema to update
	 * @param array $object The object to update
	 * @return Schema The updated schema
	 */
	public function updateFromArray(int $id, array $object): Schema
	{
		$newSchema = $this->find($id);
		$newSchema->hydrate($object);

		// Clean the schema object to ensure UUID, slug, and version are set
		$this->cleanObject($newSchema);

		$newSchema = $this->update($newSchema);

		return $newSchema;
	}

	/**
	 * Delete a schema
	 *
	 * @param Schema $schema The schema to delete
	 * @return Schema The deleted schema
	 */
	public function delete(Entity $schema): Schema
	{
		$result = parent::delete($schema);

		// Dispatch deletion event
		$this->eventDispatcher->dispatchTyped(
			new SchemaDeletedEvent($schema)
		);

		return $result;
	}
}
