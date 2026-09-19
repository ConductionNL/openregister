<?php

/**
 * Mapper for ConfigurationDeployment entities.
 *
 * The table is append-only by design (D-3), so this mapper exposes an insert
 * and reads, and NO update or delete. A rollback is written as a new row.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Class ConfigurationDeploymentMapper
 *
 * @template-extends QBMapper<ConfigurationDeployment>
 */
class ConfigurationDeploymentMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_config_deployments',
			entityClass: ConfigurationDeployment::class
		);

	}//end __construct()

	/**
	 * Find a deployment by its uuid.
	 *
	 * @param string $uuid The deployment uuid.
	 *
	 * @return ConfigurationDeployment The deployment.
	 *
	 * @throws DoesNotExistException When no such deployment exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findByUuid(string $uuid): ConfigurationDeployment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);

	}//end findByUuid()

	/**
	 * The deployment history, newest first.
	 *
	 * @param integer $limit How many rows at most.
	 *
	 * @return ConfigurationDeployment[] The deployments.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findHistory(int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);

	}//end findHistory()

	/**
	 * The rollbacks that already restore one deployment.
	 *
	 * @param string $uuid The deployment uuid they would restore.
	 *
	 * @return ConfigurationDeployment[] The rollbacks.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findRestoring(string $uuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('restores_uuid', $qb->createNamedParameter($uuid)))
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);

	}//end findRestoring()

	/**
	 * Append a deployment to the history.
	 *
	 * @param array<string, mixed> $data The deployment values.
	 *
	 * @return ConfigurationDeployment The persisted deployment.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function createFromArray(array $data): ConfigurationDeployment {
		$deployment = new ConfigurationDeployment();
		$deployment->hydrate($data);

		if ($deployment->getUuid() === null) {
			$deployment->setUuid(Uuid::v4()->toRfc4122());
		}

		if ($deployment->getState() === null) {
			$deployment->setState(ConfigurationDeployment::STATE_APPLIED);
		}

		$now = new DateTime();
		if ($deployment->getDeployedAt() === null) {
			$deployment->setDeployedAt($now);
		}

		$deployment->setCreated($now);
		$deployment->setChangeCount(count($deployment->readChanges()));

		return $this->insert(entity: $deployment);

	}//end createFromArray()
}//end class
