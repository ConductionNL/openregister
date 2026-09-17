<?php

/**
 * Mapper for ConfigurationValue entities.
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
 * Class ConfigurationValueMapper
 *
 * @template-extends QBMapper<ConfigurationValue>
 */
class ConfigurationValueMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_config_values',
			entityClass: ConfigurationValue::class
		);

	}//end __construct()

	/**
	 * The value at one address, when one has been recorded.
	 *
	 * @param string      $layer     The layer.
	 * @param string|null $layerRef  The layer reference, null at instance level.
	 * @param string      $configKey The configuration key.
	 *
	 * @return ConfigurationValue|null The value row, or null when there is none.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAtAddress(string $layer, ?string $layerRef, string $configKey): ?ConfigurationValue {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('layer', $qb->createNamedParameter($layer)))
			->andWhere($qb->expr()->eq('config_key', $qb->createNamedParameter($configKey)))
			->setMaxResults(1);

		$layerRefClause = $qb->expr()->isNull('layer_ref');
		if ($layerRef !== null) {
			$layerRefClause = $qb->expr()->eq('layer_ref', $qb->createNamedParameter($layerRef));
		}

		$qb->andWhere($layerRefClause);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $exception) {
			return null;
		}

	}//end findAtAddress()

	/**
	 * Every recorded value for one key, across layers.
	 *
	 * @param string $configKey The configuration key.
	 *
	 * @return ConfigurationValue[] The value rows.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findByKey(string $configKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('config_key', $qb->createNamedParameter($configKey)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);

	}//end findByKey()

	/**
	 * Create a value row, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The value fields.
	 *
	 * @return ConfigurationValue The persisted value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function createFromArray(array $data): ConfigurationValue {
		$value = new ConfigurationValue();
		$value->hydrate($data);

		if ($value->getUuid() === null) {
			$value->setUuid(Uuid::v4()->toRfc4122());
		}

		$now = new DateTime();
		if ($value->getCreated() === null) {
			$value->setCreated($now);
		}

		$value->setUpdated($now);

		return $this->insert(entity: $value);

	}//end createFromArray()

	/**
	 * Persist a value row, refreshing the updated timestamp.
	 *
	 * @param ConfigurationValue $value The value to persist.
	 *
	 * @return ConfigurationValue The updated value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function save(ConfigurationValue $value): ConfigurationValue {
		$value->setUpdated(new DateTime());

		return $this->update(entity: $value);

	}//end save()

	/**
	 * Remove the value row at one address.
	 *
	 * @param ConfigurationValue $value The value to remove.
	 *
	 * @return ConfigurationValue The removed value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function remove(ConfigurationValue $value): ConfigurationValue {
		return $this->delete(entity: $value);

	}//end remove()
}//end class
