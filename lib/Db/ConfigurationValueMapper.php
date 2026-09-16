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

		if ($layerRef === null) {
			$qb->andWhere($qb->expr()->isNull('layer_ref'));
		} else {
			$qb->andWhere($qb->expr()->eq('layer_ref', $qb->createNamedParameter($layerRef)));
		}

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
	 * Every recorded value at one layer address, optionally under one prefix.
	 *
	 * Below the instance layer the rows ARE the values, so this is the read a
	 * bundle's contents and a subject's overrides come from. At the instance
	 * layer the rows are only the provenance, and reading them as values would
	 * report a key app config no longer holds: callers wanting instance values
	 * read app config through ConfigurationValueStore instead.
	 *
	 * @param string      $layer    The layer.
	 * @param string|null $layerRef The layer reference, null at instance level.
	 * @param string|null $prefix   Only keys starting with this, when given.
	 *
	 * @return ConfigurationValue[] The value rows, by key.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAtLayer(string $layer, ?string $layerRef, ?string $prefix = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('layer', $qb->createNamedParameter($layer)))
			->orderBy('config_key', 'ASC');

		if ($layerRef === null) {
			$qb->andWhere($qb->expr()->isNull('layer_ref'));
		} else {
			$qb->andWhere($qb->expr()->eq('layer_ref', $qb->createNamedParameter($layerRef)));
		}

		if ($prefix !== null && $prefix !== '') {
			$qb->andWhere(
				$qb->expr()->like(
					'config_key',
					$qb->createNamedParameter($this->db->escapeLikeParameter($prefix).'%')
				)
			);
		}

		return $this->findEntities(query: $qb);

	}//end findAtLayer()

	/**
	 * Every recorded value at one layer, across every reference.
	 *
	 * Its own method rather than a null reference on findAtLayer(). There,
	 * null means the instance address, the same way it does everywhere else in
	 * this capability, so reusing it for "any reference" would give one
	 * spelling two meanings and the caller asking for all bundles would get
	 * the rows that belong to no bundle at all.
	 *
	 * @param string $layer The layer.
	 *
	 * @return ConfigurationValue[] The value rows.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAllAtLayer(string $layer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('layer', $qb->createNamedParameter($layer)))
			->orderBy('layer_ref', 'ASC')
			->addOrderBy('config_key', 'ASC');

		return $this->findEntities(query: $qb);

	}//end findAllAtLayer()

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
