<?php
/**
 * OpenRegister Configuration Mapper
 *
 * This file contains the ConfigurationMapper class for database operations on configurations.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Ruben Linde <ruben@nextcloud.com>
 * @copyright Copyright (c) 2024, Ruben Linde (https://github.com/rubenlinde)
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/cloud-py-api/openregister
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ConfigurationMapper
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Configuration>
 *
 * @psalm-suppress MissingTemplateParam
 */
class ConfigurationMapper extends QBMapper {
    /**
     * ConfigurationMapper constructor.
     *
     * @param IDBConnection $db Database connection instance
     */
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'openregister_configurations', Configuration::class);
    }

    /**
     * Find a configuration by its ID
     *
     * @param int $id Configuration ID
     *
     * @return Configuration The configuration entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Configuration {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Find configurations by type
     *
     * @param string $type Configuration type
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     *
     * @return Configuration[] Array of configuration entities
     */
    public function findByType(string $type, int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find configurations by owner
     *
     * @param string $owner   Owner identifier
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     *
     * @return Configuration[] Array of configuration entities
     */
    public function findByOwner(string $owner, int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Insert a new configuration
     *
     * @param Configuration $entity Configuration entity to insert
     *
     * @return Configuration The inserted configuration with updated ID
     */
    public function insert(Entity $entity): Entity {
        if ($entity instanceof Configuration) {
            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }

        return parent::insert($entity);
    }

    /**
     * Update an existing configuration
     *
     * @param Configuration $entity Configuration entity to update
     *
     * @return Configuration The updated configuration
     */
    public function update(Entity $entity): Entity {
        if ($entity instanceof Configuration) {
            $entity->setUpdated(new DateTime());
        }

        return parent::update($entity);
    }

    /**
     * Delete a configuration
     *
     * @param Configuration $entity Configuration entity to delete
     *
     * @return Configuration The deleted configuration
     */
    public function delete(Entity $entity): Entity {
        return parent::delete($entity);
    }

    /**
     * Create a configuration from an array
     *
     * @param array $data The configuration data
     *
     * @return Configuration The created configuration
     */
    public function createFromArray(array $data): Configuration {
        $config = new Configuration();
        $config->setTitle($data['title']);
        $config->setDescription($data['description'] ?? '');
        $config->setType($data['type']);
        $config->setData($data['data'] ?? []);
        $config->setOwner($data['owner'] ?? null);

        return $this->insert($config);
    }

    /**
     * Update a configuration from an array
     *
     * @param int   $id   The configuration ID
     * @param array $data The configuration data
     *
     * @throws DoesNotExistException If the configuration is not found
     * @return Configuration The updated configuration
     */
    public function updateFromArray(int $id, array $data): Configuration {
        $config = $this->find($id);
        
        if (isset($data['title'])) {
            $config->setTitle($data['title']);
        }
        if (isset($data['description'])) {
            $config->setDescription($data['description']);
        }
        if (isset($data['type'])) {
            $config->setType($data['type']);
        }
        if (isset($data['data'])) {
            $config->setData($data['data']);
        }
        if (isset($data['owner'])) {
            $config->setOwner($data['owner']);
        }

        return $this->update($config);
    }

    /**
     * Count configurations by type
     *
     * @param string $type Configuration type
     *
     * @return int Number of configurations
     */
    public function countByType(string $type): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $count = $result->fetchOne();
        $result->closeCursor();

        return (int)$count;
    }

    /**
     * Count configurations by owner
     *
     * @param string $owner Owner ID
     *
     * @return int Number of configurations
     */
    public function countByOwner(string $owner): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $count = $result->fetchOne();
        $result->closeCursor();

        return (int)$count;
    }
} 