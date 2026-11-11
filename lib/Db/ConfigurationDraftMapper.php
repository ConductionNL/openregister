<?php

/**
 * Mapper for ConfigurationDraft entities.
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
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Class ConfigurationDraftMapper
 *
 * @template-extends QBMapper<ConfigurationDraft>
 */
class ConfigurationDraftMapper extends QBMapper implements ConfigurationDraftMapperInterface {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_config_drafts',
			entityClass: ConfigurationDraft::class
		);

	}//end __construct()

	/**
	 * Every pending value in one set, in the order they were written.
	 *
	 * @param string $setUuid The set uuid.
	 *
	 * @return ConfigurationDraft[] The drafts.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findBySet(string $setUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('set_uuid', $qb->createNamedParameter($setUuid)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);

	}//end findBySet()

	/**
	 * The pending value one set holds for one address, when it holds one.
	 *
	 * @param string      $setUuid   The set uuid.
	 * @param string      $layer     The layer.
	 * @param string|null $layerRef  The layer reference, null at instance level.
	 * @param string      $configKey The configuration key.
	 *
	 * @return ConfigurationDraft|null The draft, or null when the set holds none.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAtAddress(
		string $setUuid,
		string $layer,
		?string $layerRef,
		string $configKey
	): ?ConfigurationDraft {
		$address = new ConfigurationAddress(layer: $layer, layerRef: $layerRef, configKey: $configKey);
		return $this->findAt(setUuid: $setUuid, address: $address);
	}//end findAtAddress()

	/**
	 * The pending value one set holds for one address, when it holds one.
	 *
	 * @param string               $setUuid The set uuid.
	 * @param ConfigurationAddress $address The layer, layer reference and configuration key.
	 *
	 * @return ConfigurationDraft|null The draft, or null when the set holds none.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAt(string $setUuid, ConfigurationAddress $address): ?ConfigurationDraft {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('set_uuid', $qb->createNamedParameter($setUuid)))
			->andWhere($qb->expr()->eq('layer', $qb->createNamedParameter($address->layer)))
			->andWhere($qb->expr()->eq('config_key', $qb->createNamedParameter($address->configKey)))
			->setMaxResults(1);
		$this->whereLayerRef(qb: $qb, layerRef: $address->layerRef);

		return $this->findOneOrNull(qb: $qb);

	}//end findAt()

	/**
	 * Match the layer reference; a null reference matches the rows without one.
	 *
	 * @param IQueryBuilder $qb       The query.
	 * @param string|null   $layerRef The layer reference.
	 *
	 * @return void
	 */
	private function whereLayerRef(IQueryBuilder $qb, ?string $layerRef): void {
		$layerRefClause = $qb->expr()->isNull('layer_ref');
		if ($layerRef !== null) {
			$layerRefClause = $qb->expr()->eq('layer_ref', $qb->createNamedParameter($layerRef));
		}

		$qb->andWhere($layerRefClause);

	}//end whereLayerRef()

	/**
	 * The one draft the query selects, or null when there is none.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return ConfigurationDraft|null The draft.
	 */
	private function findOneOrNull(IQueryBuilder $qb): ?ConfigurationDraft {
		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $exception) {
			return null;
		}

	}//end findOneOrNull()

	/**
	 * Find a draft by its uuid.
	 *
	 * @param string $uuid The draft uuid.
	 *
	 * @return ConfigurationDraft The draft.
	 *
	 * @throws DoesNotExistException When no such draft exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findByUuid(string $uuid): ConfigurationDraft {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);

	}//end findByUuid()

	/**
	 * Create a draft row, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The draft values.
	 *
	 * @return ConfigurationDraft The persisted draft.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function createFromArray(array $data): ConfigurationDraft {
		$draft = new ConfigurationDraft();
		$draft->hydrate($data);

		if ($draft->getUuid() === null) {
			$draft->setUuid(Uuid::v4()->toRfc4122());
		}

		$now = new DateTime();
		if ($draft->getCreated() === null) {
			$draft->setCreated($now);
		}

		$draft->setUpdated($now);

		return $this->insert(entity: $draft);

	}//end createFromArray()

	/**
	 * Persist a draft, refreshing the updated timestamp.
	 *
	 * @param ConfigurationDraft $draft The draft to persist.
	 *
	 * @return ConfigurationDraft The updated draft.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function save(ConfigurationDraft $draft): ConfigurationDraft {
		$draft->setUpdated(new DateTime());

		return $this->update(entity: $draft);

	}//end save()
}//end class
