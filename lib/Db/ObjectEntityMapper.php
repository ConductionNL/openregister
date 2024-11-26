<?php

namespace OCA\OpenRegister\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\IDatabaseJsonService;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * The ObjectEntityMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class ObjectEntityMapper extends QBMapper
{
	private IDatabaseJsonService $databaseJsonService;
	private SchemaMapper $schemaMapper;
	private RegisterMapper $registerMapper;

	public const MAIN_FILTERS = ['register', 'schema', 'uuid', 'created', 'updated'];

	/**
	 * Constructor for the ObjectEntityMapper
	 *
	 * @param IDBConnection $db The database connection
	 * @param MySQLJsonService $mySQLJsonService The MySQL JSON service
	 * @param SchemaMapper $schemaMapper The schema mapper
	 * @param RegisterMapper $registerMapper The register mapper
	 */
	public function __construct(IDBConnection $db, MySQLJsonService $mySQLJsonService, SchemaMapper $schemaMapper, RegisterMapper $registerMapper)
	{
		parent::__construct($db, 'openregister_objects');

		if ($db->getDatabasePlatform() instanceof MySQLPlatform === true) {
			$this->databaseJsonService = $mySQLJsonService;
		}
		$this->schemaMapper = $schemaMapper;
		$this->registerMapper = $registerMapper;
	}

	/**
	 * Find an object by ID or UUID
	 *
	 * @param int|string $idOrUuid The ID or UUID of the object to find
	 * @return ObjectEntity The ObjectEntity
	 */
	public function find($idOrUuid): ObjectEntity
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('id', $qb->createNamedParameter($idOrUuid, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('uuid', $qb->createNamedParameter($idOrUuid, IQueryBuilder::PARAM_STR))
				)
			);

		return $this->findEntity($qb);
	}

	/**
	 * Find an object by UUID
	 *
	 * @param string $uuid The UUID of the object to find
	 * @return ObjectEntity The object
	 */
	public function findByUuid(Register $register, Schema $schema, string $uuid): ObjectEntity|null
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->where(
				$qb->expr()->eq('uuid', $qb->createNamedParameter($uuid))
			)
			->andWhere(
				$qb->expr()->eq('register', $qb->createNamedParameter($register->getId()))
			)
			->andWhere(
				$qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId()))
			);

		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find objects by register and schema
	 *
	 * @param string $register The register to find objects for
	 * @param string $schema The schema to find objects for
	 * @return array An array of ObjectEntitys
	 */
	public function findByRegisterAndSchema(string $register, string $schema): ObjectEntity
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->where(
				$qb->expr()->eq('register', $qb->createNamedParameter($register))
			)
			->andWhere(
				$qb->expr()->eq('schema', $qb->createNamedParameter($schema))
			);

		return $this->findEntities(query: $qb);
	}

	/**
	 * Counts all objects
	 *
	 * @param array|null $filters The filters to apply
	 * @param string|null $search The search string to apply
	 * @return int The number of objects
	 */
	public function countAll(?array $filters = [], ?string $search = null): int
	{
		$qb = $this->db->getQueryBuilder();

		$qb->selectAlias(select: $qb->createFunction(call: 'count(id)'), alias: 'count')
			->from(from: 'openregister_objects');
		foreach ($filters as $filter => $value) {
			if ($value === 'IS NOT NULL' && in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->isNotNull($filter));
			} elseif ($value === 'IS NULL' && in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->isNull($filter));
			} else if (in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
			}
		}

		$qb = $this->databaseJsonService->filterJson($qb, $filters);
		$qb = $this->databaseJsonService->searchJson($qb, $search);

		$result = $qb->executeQuery();

		return $result->fetchAll()[0]['count'];
	}

	/**
	 * Find all ObjectEntitys
	 *
	 * @param int $limit The number of objects to return
	 * @param int $offset The offset of the objects to return
	 * @param array $filters The filters to apply to the objects
	 * @param array $searchConditions The search conditions to apply to the objects
	 * @param array $searchParams The search parameters to apply to the objects
	 * @return array An array of ObjectEntitys
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [], array $sort = [], ?string $search = null): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_objects')
			->setMaxResults($limit)
			->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
			if ($value === 'IS NOT NULL' && in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->isNotNull($filter));
			} elseif ($value === 'IS NULL' && in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->isNull($filter));
			} else if (in_array(needle: $filter, haystack: self::MAIN_FILTERS) === true) {
				$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
			}
        }

        if (!empty($searchConditions)) {
            $qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

		$qb = $this->databaseJsonService->filterJson(builder: $qb, filters: $filters);
		$qb = $this->databaseJsonService->searchJson(builder: $qb, search: $search);
		$qb = $this->databaseJsonService->orderJson(builder: $qb, order: $sort);

//		var_dump($qb->getSQL());

		return $this->findEntities(query: $qb);
	}

	/**
	 * Creates an object from an array
	 *
	 * @param array $object The object to create
	 * @return ObjectEntity The created object
	 */
	public function createFromArray(array $object): ObjectEntity
	{
		$obj = new ObjectEntity();
		$obj->hydrate(object: $object);
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}
		return $this->insert($obj);
	}

	/**
	 * Updates an object from an array
	 *
	 * @param int $id The id of the object to update
	 * @param array $object The object to update
	 * @return ObjectEntity The updated object
	 */
	public function updateFromArray(int $id, array $object): ObjectEntity
	{
		$obj = $this->find($id);
		$obj->hydrate($object);

		// Set or update the version
		$version = explode('.', $obj->getVersion());
		$version[2] = (int)$version[2] + 1;
		$obj->setVersion(implode('.', $version));

		return $this->update($obj);
	}

	/**
	 * Gets the facets for the objects
	 *
	 * @param array $filters The filters to apply
	 * @param string|null $search The search string to apply
	 * @return array The facets
	 */
	public function getFacets(array $filters = [], ?string $search = null)
	{
		if (key_exists(key: 'register', array: $filters) === true) {
			$register = $filters['register'];
		}
		if (key_exists(key: 'schema', array: $filters) === true) {
			$schema = $filters['schema'];
		}

		$fields = [];
		if (isset($filters['_queries'])) {
			$fields = $filters['_queries'];
		}

		unset(
			$filters['_fields'],
			$filters['register'],
			$filters['schema'],
			$filters['created'],
			$filters['updated'],
			$filters['uuid']
		);

		return $this->databaseJsonService->getAggregations(
			builder: $this->db->getQueryBuilder(),
			fields: $fields,
			register: $register,
			schema: $schema,
			filters: $filters,
			search: $search
		);
	}

	/**
	 * Get register growth over time (objects per register)
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @return array Daily object counts per register
	 */
	public function getRegisterGrowth(\DateTime $from, \DateTime $to): array {
		$qb = $this->db->getQueryBuilder();
		
		// First, get all registers that have objects
		$registers = $this->registerMapper->findAll();
		$registerData = [];
		
		// Initialize data structure for all registers
		foreach ($registers as $register) {
			$registerData[$register->getId()] = [
				'name' => $register->getTitle() ?? 'Unknown Register',
				'data' => [],
			];
			
			// Initialize all dates with zero
			$period = new \DatePeriod(
				$from,
				new \DateInterval('P1D'),
				$to->modify('+1 day')
			);
			
			foreach ($period as $date) {
				$dateStr = $date->format('Y-m-d');
				$registerData[$register->getId()]['data'][$dateStr] = 0;
			}
		}

		// Get actual data
		$qb->select(
				'register',
				$qb->createFunction('DATE(created) as date'),
				$qb->createFunction('COUNT(*) as count')
			)
			->from('openregister_objects')
			->where($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))))
			->groupBy('date', 'register')
			->orderBy('date', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		// Fill in actual counts and maintain running totals
		foreach ($registerData as $registerId => &$register) {
			$runningTotal = 0;
			foreach ($register['data'] as $date => &$count) {
				// Find count for this date and register
				foreach ($rows as $row) {
					if ($row['register'] == $registerId && $row['date'] == $date) {
						$runningTotal += (int)$row['count'];
					}
				}
				$count = $runningTotal;
			}
		}

		return array_values($registerData);
	}

	/**
	 * Get schema distribution over time (objects per schema)
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @return array Schema distribution data with running totals
	 */
	public function getSchemaDistribution(\DateTime $from, \DateTime $to): array {
		$qb = $this->db->getQueryBuilder();
		
		// First, get all schemas that have objects
		$schemas = $this->schemaMapper->findAll();
		$schemaData = [];
		
		// Initialize data structure for all schemas
		foreach ($schemas as $schema) {
			$schemaData[$schema->getId()] = [
				'name' => $schema->getTitle() ?? 'Unknown Schema',
				'data' => [],
			];
			
			// Initialize all dates with zero
			$period = new \DatePeriod(
				$from,
				new \DateInterval('P1D'),
				$to->modify('+1 day')
			);
			
			foreach ($period as $date) {
				$dateStr = $date->format('Y-m-d');
				$schemaData[$schema->getId()]['data'][$dateStr] = 0;
			}
		}

		// Get actual data
		$qb->select(
				'schema',
				$qb->createFunction('DATE(created) as date'),
				$qb->createFunction('COUNT(*) as count')
			)
			->from('openregister_objects')
			->where($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))))
			->groupBy('date', 'schema')
			->orderBy('date', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		// Fill in actual counts and maintain running totals
		foreach ($schemaData as $schemaId => &$schema) {
			$runningTotal = 0;
			foreach ($schema['data'] as $date => &$count) {
				// Find count for this date and schema
				foreach ($rows as $row) {
					if ($row['schema'] == $schemaId && $row['date'] == $date) {
						$runningTotal += (int)$row['count'];
					}
				}
				$count = $runningTotal;
			}
		}

		return array_values($schemaData);
	}

	/**
	 * Get validation error statistics over time
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @param int|null $schemaId Optional schema ID filter
	 * @param int|null $registerId Optional register ID filter
	 * @return array Validation statistics per day
	 */
	public function getValidationStats(\DateTime $from, \DateTime $to, ?int $schemaId = null, ?int $registerId = null): array {
		$qb = $this->db->getQueryBuilder();
		
		$qb->select(
				$qb->createFunction('DATE(created) as date'),
				$qb->createFunction('COUNT(*) as total_count'),
				$qb->createFunction('COUNT(CASE WHEN JSON_CONTAINS_PATH(object, \'one\', \'$.validation_errors\') = 1 THEN 1 END) as error_count')
			)
			->from('openregister_objects')
			->where($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));

		// Add optional filters
		if ($schemaId !== null) {
			$qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId)));
		}
		if ($registerId !== null) {
			$qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId)));
		}

		$qb->groupBy('date')
		   ->orderBy('date', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		// Initialize data structure
		$stats = [
			'daily' => [
				'total' => [],
				'errors' => [],
				'error_rate' => [],
			],
			'totals' => [
				'total_objects' => 0,
				'objects_with_errors' => 0,
				'error_rate' => 0,
			],
		];

		// Process daily stats
		foreach ($rows as $row) {
			$date = $row['date'];
			$totalCount = (int)$row['total_count'];
			$errorCount = (int)$row['error_count'];
			
			$stats['daily']['total'][$date] = $totalCount;
			$stats['daily']['errors'][$date] = $errorCount;
			$stats['daily']['error_rate'][$date] = $totalCount > 0 
				? round(($errorCount / $totalCount) * 100, 2)
				: 0;

			// Update totals
			$stats['totals']['total_objects'] += $totalCount;
			$stats['totals']['objects_with_errors'] += $errorCount;
		}

		// Calculate overall error rate
		$stats['totals']['error_rate'] = $stats['totals']['total_objects'] > 0
			? round(($stats['totals']['objects_with_errors'] / $stats['totals']['total_objects']) * 100, 2)
			: 0;

		// Fill in missing dates with zeros
		$period = new \DatePeriod(
			$from,
			new \DateInterval('P1D'),
			$to->modify('+1 day')
		);

		foreach ($period as $date) {
			$dateStr = $date->format('Y-m-d');
			if (!isset($stats['daily']['total'][$dateStr])) {
				$stats['daily']['total'][$dateStr] = 0;
				$stats['daily']['errors'][$dateStr] = 0;
				$stats['daily']['error_rate'][$dateStr] = 0;
			}
		}

		return $stats;
	}

	/**
	 * Get field completeness statistics
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @param int|null $schemaId Optional schema ID filter
	 * @param int|null $registerId Optional register ID filter
	 * @return array Completeness statistics
	 */
	public function getCompletenessStats(\DateTime $from, \DateTime $to, ?int $schemaId = null, ?int $registerId = null): array {
		$qb = $this->db->getQueryBuilder();
		
		// Get all schemas to analyze their required fields
		$schemas = $schemaId !== null 
			? [$this->schemaMapper->find($schemaId)]
			: $this->schemaMapper->findAll();
		$schemaFields = [];
		
		// Collect optional fields for each schema
		foreach ($schemas as $schema) {
			// Properties is already an array since it's defined as json type in Schema entity
			$properties = $schema->getProperties();
			
			// Skip if properties is not an array
			if (!is_array($properties)) {
				continue;
			}

			$optionalFields = [];
			foreach ($properties as $fieldName => $fieldConfig) {
				if (is_array($fieldConfig) && !($fieldConfig['required'] ?? false)) {
					$optionalFields[] = $fieldName;
				}
			}

			$schemaFields[$schema->getId()] = [
				'name' => $schema->getTitle() ?? 'Unknown Schema',
				'optional_fields' => $optionalFields,
			];
		}

		// Initialize stats structure
		$stats = [
			'daily' => [],
			'schemas' => [],
			'totals' => [
				'total_objects' => 0,
				'completeness_rate' => 0,
			],
		];

		// Analyze completeness for each schema
		foreach ($schemaFields as $schemaId => $schema) {
			if (empty($schema['optional_fields'])) {
				continue;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->select(
					$qb->createFunction('DATE(created) as date'),
					'object'
				)
				->from('openregister_objects')
				->where($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId)))
				->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
				->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));

			if ($registerId !== null) {
				$qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId)));
			}

			$qb->orderBy('date', 'ASC');

			$result = $qb->executeQuery();
			$objects = $result->fetchAll();
			$result->closeCursor();

			$schemaStats = [
				'name' => $schema['name'],
				'daily' => [],
				'total_objects' => count($objects),
				'optional_fields' => count($schema['optional_fields']),
				'average_completeness' => 0,
			];

			$dailyObjects = [];
			foreach ($objects as $object) {
				$date = $object['date'];
				if (!isset($dailyObjects[$date])) {
					$dailyObjects[$date] = [];
				}
				
				// Object is already an array since it's defined as json type in ObjectEntity
				$objectData = $object['object'];
				if (is_array($objectData)) {
					$dailyObjects[$date][] = $objectData;
				}
			}

			// Calculate daily completeness
			foreach ($dailyObjects as $date => $dateObjects) {
				$totalFields = count($schema['optional_fields']) * count($dateObjects);
				$filledFields = 0;

				foreach ($dateObjects as $obj) {
					if (!is_array($obj)) {
						continue;
					}
					foreach ($schema['optional_fields'] as $field) {
						if (isset($obj[$field]) && $obj[$field] !== null && $obj[$field] !== '') {
							$filledFields++;
						}
					}
				}

				$schemaStats['daily'][$date] = [
					'total_objects' => count($dateObjects),
					'completeness_rate' => $totalFields > 0 
						? round(($filledFields / $totalFields) * 100, 2)
						: 0,
				];
			}

			// Calculate average completeness for schema
			if (!empty($schemaStats['daily'])) {
				$totalCompleteness = array_sum(array_column($schemaStats['daily'], 'completeness_rate'));
				$schemaStats['average_completeness'] = round($totalCompleteness / count($schemaStats['daily']), 2);
			}

			$stats['schemas'][$schemaId] = $schemaStats;
			$stats['totals']['total_objects'] += $schemaStats['total_objects'];
		}

		// Calculate overall completeness rate
		if (!empty($stats['schemas'])) {
			$totalSchemaCompleteness = array_sum(array_column($stats['schemas'], 'average_completeness'));
			$stats['totals']['completeness_rate'] = round($totalSchemaCompleteness / count($stats['schemas']), 2);
		}

		return $stats;
	}

	/**
	 * Get revision statistics per object
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @param int|null $schemaId Optional schema ID filter
	 * @param int|null $registerId Optional register ID filter
	 * @return array Revision statistics
	 */
	public function getRevisionStats(\DateTime $from, \DateTime $to, ?int $schemaId = null, ?int $registerId = null): array {
		$qb = $this->db->getQueryBuilder();
		
		$qb->select(
				$qb->createFunction('DATE(created) as date'),
				'schema',
				$qb->createFunction('COUNT(*) as total_objects'),
				$qb->createFunction('AVG(CAST(SUBSTRING_INDEX(version, \'.\', -1) AS UNSIGNED)) as avg_revisions')
			)
			->from('openregister_objects')
			->where($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));

		// Add optional filters
		if ($schemaId !== null) {
			$qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId)));
		}
		if ($registerId !== null) {
			$qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId)));
		}

		$qb->groupBy('date', 'schema')
		   ->orderBy('date', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		// Get schema names
		$schemas = $this->schemaMapper->findAll();
		$schemaNames = [];
		foreach ($schemas as $schema) {
			$schemaNames[$schema->getId()] = $schema->getTitle();
		}

		// Initialize stats structure
		$stats = [
			'daily' => [
				'objects' => [],
				'avg_revisions' => [],
				'per_schema' => [],
			],
			'totals' => [
				'total_objects' => 0,
				'avg_revisions' => 0,
				'per_schema' => [],
			],
		];

		// Process daily stats
		$schemaStats = [];
		foreach ($rows as $row) {
			$date = $row['date'];
			$schemaId = $row['schema'];
			$schemaName = $schemaNames[$schemaId] ?? 'Unknown Schema';
			$totalObjects = (int)$row['total_objects'];
			$avgRevisions = round((float)$row['avg_revisions'], 2);
			
			// Initialize schema in stats if not exists
			if (!isset($stats['daily']['per_schema'][$schemaName])) {
				$stats['daily']['per_schema'][$schemaName] = [];
				$schemaStats[$schemaName] = [
					'total_objects' => 0,
					'total_revisions' => 0,
				];
			}

			// Update daily stats
			$stats['daily']['per_schema'][$schemaName][$date] = $avgRevisions;
			
			// Update schema totals
			$schemaStats[$schemaName]['total_objects'] += $totalObjects;
			$schemaStats[$schemaName]['total_revisions'] += ($totalObjects * $avgRevisions);
			
			// Update overall totals
			$stats['totals']['total_objects'] += $totalObjects;
		}

		// Calculate average revisions per schema
		foreach ($schemaStats as $schemaName => $schemaStat) {
			$stats['totals']['per_schema'][$schemaName] = 
				$schemaStat['total_objects'] > 0 
					? round($schemaStat['total_revisions'] / $schemaStat['total_objects'], 2)
					: 0;
		}

		// Calculate overall average revisions
		$stats['totals']['avg_revisions'] = 
			$stats['totals']['total_objects'] > 0
				? round(array_sum(array_column($schemaStats, 'total_revisions')) / $stats['totals']['total_objects'], 2)
				: 0;

		// Fill in missing dates with zeros
		$period = new \DatePeriod(
			$from,
			new \DateInterval('P1D'),
			$to->modify('+1 day')
		);

		foreach ($period as $date) {
			$dateStr = $date->format('Y-m-d');
			foreach ($stats['daily']['per_schema'] as $schemaName => $schemaDates) {
				if (!isset($schemaDates[$dateStr])) {
					$stats['daily']['per_schema'][$schemaName][$dateStr] = 0;
				}
			}
		}

		return $stats;
	}
}
