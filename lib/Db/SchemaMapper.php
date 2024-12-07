<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * The SchemaMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class SchemaMapper extends QBMapper
{
	/**
	 * Constructor for the SchemaMapper
	 *
	 * @param IDBConnection $db The database connection
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openregister_schemas');
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
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
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
	 * Creates a schema from an array
	 *
	 * @param array $object The object to create
	 * @return Schema The created schema
	 */
	public function createFromArray(array $object): Schema
	{
		$schema = new Schema();
		$schema->hydrate(object: $object);

		// Set uuid if not provided
		if ($schema->getUuid() === null) {
			$schema->setUuid(Uuid::v4());
		}

		return $this->insert(entity: $schema);
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
		$obj = $this->find($id);
		$obj->hydrate($object);

		// Set or update the version
		if (isset($object['version']) === false) {
			$version = explode('.', $obj->getVersion());
			$version[2] = (int)$version[2] + 1;
			$obj->setVersion(implode('.', $version));
		}

		return $this->update($obj);
	}

	/**
	 * Count total number of schemas
	 * @return int Total number of schemas
	 */
	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*) as count'))
		   ->from('openregister_schemas');

		$result = $qb->executeQuery();
		$count = $result->fetch();
		$result->closeCursor();

		return (int)$count['count'];
	}

	/**
	 * Get statistics about field types used across schemas
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @param int|null $schemaId Optional schema filter
	 * @return array Distribution of field types across schemas
	 */
	public function getFieldTypeStats(\DateTime $from, \DateTime $to, ?int $schemaId = null): array {
		$schemas = $schemaId !== null ? [$this->find($schemaId)] : $this->findAll();
		$fieldTypes = [];
		$total = 0;

		foreach ($schemas as $schema) {
			// Check if schema was active in the date range
			if ($schema->getCreated() > $to || ($schema->getUpdated() && $schema->getUpdated() < $from)) {
				continue;
			}

			$properties = $schema->getProperties();
			if (!is_array($properties)) {
				continue;
			}

			foreach ($properties as $field => $config) {
				$type = ucfirst($config['type'] ?? 'Unknown');
				if (!isset($fieldTypes[$type])) {
					$fieldTypes[$type] = 0;
				}
				$fieldTypes[$type]++;
				$total++;
			}
		}

		// Format for pie chart
		$stats = [];
		foreach ($fieldTypes as $type => $count) {
			$stats[] = [
				'name' => $type,
				'value' => $count,
				'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
			];
		}

		return $stats;
	}

	/**
	 * Get field usage statistics including formats
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @param int|null $schemaId Optional schema filter
	 * @return array Field usage and format statistics across schemas
	 */
	public function getFieldUsageStats(\DateTime $from, \DateTime $to, ?int $schemaId = null): array {
		$schemas = $schemaId !== null ? [$this->find($schemaId)] : $this->findAll();
		$fieldUsage = [];
		$formatUsage = [];
		$totalSchemas = 0;
		$totalFields = 0;

		foreach ($schemas as $schema) {
			// Check if schema was active in the date range
			if ($schema->getCreated() > $to || ($schema->getUpdated() && $schema->getUpdated() < $from)) {
				continue;
			}

			$totalSchemas++;
			$properties = $schema->getProperties() ?? [];

			foreach ($properties as $field => $config) {
				$totalFields++;
				
				// Track field type
				$type = $config['type'] ?? 'unknown';
				if (!isset($fieldUsage[$type])) {
					$fieldUsage[$type] = [
						'count' => 0,
						'formats' => [],
					];
				}
				$fieldUsage[$type]['count']++;

				// Track format if specified
				if (isset($config['format'])) {
					$format = $config['format'];
					if (!isset($fieldUsage[$type]['formats'][$format])) {
						$fieldUsage[$type]['formats'][$format] = 0;
					}
					$fieldUsage[$type]['formats'][$format]++;

					// Track overall format usage
					if (!isset($formatUsage[$format])) {
						$formatUsage[$format] = 0;
					}
					$formatUsage[$format]++;
				}
			}
		}

		// Calculate percentages and format the stats
		$stats = [
			'types' => [],
			'formats' => [],
			'total_fields' => $totalFields,
			'total_schemas' => $totalSchemas,
		];

		// Format type statistics
		foreach ($fieldUsage as $type => $usage) {
			$stats['types'][$type] = [
				'count' => $usage['count'],
				'percentage' => $totalFields > 0 ? round(($usage['count'] / $totalFields) * 100, 2) : 0,
				'formats' => [],
			];

			// Format the format statistics for each type
			foreach ($usage['formats'] as $format => $formatCount) {
				$stats['types'][$type]['formats'][$format] = [
					'count' => $formatCount,
					'percentage' => $usage['count'] > 0 ? round(($formatCount / $usage['count']) * 100, 2) : 0,
				];
			}
		}

		// Format overall format statistics
		foreach ($formatUsage as $format => $count) {
			$stats['formats'][$format] = [
				'count' => $count,
				'percentage' => $totalFields > 0 ? round(($count / $totalFields) * 100, 2) : 0,
			];
		}

		return $stats;
	}

	/**
	 * Get schema complexity metrics
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @param int|null $schemaId Optional schema filter
	 * @return array Complexity scores for each schema
	 */
	public function getComplexityStats(\DateTime $from, \DateTime $to, ?int $schemaId = null): array {
		$schemas = $schemaId !== null ? [$this->find($schemaId)] : $this->findAll();
		$stats = [];

		foreach ($schemas as $schema) {
			// Check if schema was active in the date range
			if ($schema->getCreated() > $to || ($schema->getUpdated() && $schema->getUpdated() < $from)) {
				continue;
			}

			$properties = $schema->getProperties() ?? [];
			$requiredFields = 0;
			$maxDepth = 0;

			// Calculate metrics
			foreach ($properties as $field => $config) {
				if (is_array($config) && ($config['required'] ?? false)) {
					$requiredFields++;
				}
				$depth = $this->calculateFieldDepth($config);
				$maxDepth = max($maxDepth, $depth);
			}

			// Calculate complexity score
			$complexityScore = (
				(count($properties) * 1) +    // Base fields weight
				($requiredFields * 1.5) +     // Required fields weight
				($maxDepth * 2)               // Nesting depth weight
			);

			$stats[] = [
				'name' => $schema->getTitle() ?? 'Unnamed Schema',
				'value' => round($complexityScore, 2),
				'fields' => count($properties),
				'required' => $requiredFields,
				'depth' => $maxDepth,
			];
		}

		return $stats;
	}

	/**
	 * Calculate the nesting depth of a field
	 * 
	 * @param array|mixed $field The field configuration
	 * @return int The nesting depth
	 */
	private function calculateFieldDepth($field): int {
		if (!is_array($field)) {
			return 0;
		}

		$depth = 0;
		if (isset($field['properties']) && is_array($field['properties'])) {
			$depth = 1;
			foreach ($field['properties'] as $subField) {
				$depth = max($depth, 1 + $this->calculateFieldDepth($subField));
			}
		}
		return $depth;
	}

	/**
	 * Get schema version distribution
	 * 
	 * @param \DateTime $from Start date
	 * @param \DateTime $to End date
	 * @param int|null $schemaId Optional schema filter
	 * @return array Distribution of schema versions
	 */
	public function getVersionDistribution(\DateTime $from, \DateTime $to, ?int $schemaId = null): array {
		$schemas = $schemaId !== null ? [$this->find($schemaId)] : $this->findAll();
		$versions = [];
		$total = 0;

		foreach ($schemas as $schema) {
			// Check if schema was active in the date range
			if ($schema->getCreated() > $to || ($schema->getUpdated() && $schema->getUpdated() < $from)) {
				continue;
			}

			$total++;
			$version = $schema->getVersion();
			if (!isset($versions[$version])) {
				$versions[$version] = [
					'count' => 0,
					'schemas' => [],
				];
			}
			$versions[$version]['count']++;
			$versions[$version]['schemas'][] = $schema->getTitle();
		}

		// Calculate percentages and sort by version
		$stats = [
			'versions' => [],
			'total_schemas' => $total,
		];

		foreach ($versions as $version => $data) {
			$stats['versions'][$version] = [
				'count' => $data['count'],
				'percentage' => $total > 0 ? round(($data['count'] / $total) * 100, 2) : 0,
				'schemas' => $data['schemas'],
			];
		}

		ksort($stats['versions']);
		return $stats;
	}
}
