<?php

/**
 * Mapper for ConfigurationBinding entities.
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
 * Class ConfigurationBindingMapper
 *
 * @template-extends QBMapper<ConfigurationBinding>
 */
class ConfigurationBindingMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_config_bindings',
			entityClass: ConfigurationBinding::class
		);

	}//end __construct()

	/**
	 * The bundle one subject follows, when it follows one.
	 *
	 * @param string $subject The subject.
	 *
	 * @return ConfigurationBinding|null The binding, or null when the subject follows no bundle.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findBySubject(string $subject): ?ConfigurationBinding {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('subject', $qb->createNamedParameter($subject)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $exception) {
			return null;
		}

	}//end findBySubject()

	/**
	 * Every subject following one bundle.
	 *
	 * @param string $bundle The bundle name.
	 *
	 * @return ConfigurationBinding[] The bindings.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findByBundle(string $bundle): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bundle', $qb->createNamedParameter($bundle)))
			->orderBy('subject', 'ASC');

		return $this->findEntities(query: $qb);

	}//end findByBundle()

	/**
	 * Every binding, newest bundle first by name.
	 *
	 * @return ConfigurationBinding[] The bindings.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function findAllBindings(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('bundle', 'ASC')
			->addOrderBy('subject', 'ASC');

		return $this->findEntities(query: $qb);

	}//end findAllBindings()

	/**
	 * Create a binding, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The binding fields.
	 *
	 * @return ConfigurationBinding The persisted binding.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function createFromArray(array $data): ConfigurationBinding {
		$binding = new ConfigurationBinding();
		$binding->hydrate($data);

		if ($binding->getUuid() === null) {
			$binding->setUuid(Uuid::v4()->toRfc4122());
		}

		$now = new DateTime();
		if ($binding->getCreated() === null) {
			$binding->setCreated($now);
		}

		$binding->setUpdated($now);

		return $this->insert(entity: $binding);

	}//end createFromArray()

	/**
	 * Persist a binding, refreshing the updated timestamp.
	 *
	 * @param ConfigurationBinding $binding The binding to persist.
	 *
	 * @return ConfigurationBinding The updated binding.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function save(ConfigurationBinding $binding): ConfigurationBinding {
		$binding->setUpdated(new DateTime());

		return $this->update(entity: $binding);

	}//end save()

	/**
	 * Remove a binding.
	 *
	 * @param ConfigurationBinding $binding The binding to remove.
	 *
	 * @return ConfigurationBinding The removed binding.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function remove(ConfigurationBinding $binding): ConfigurationBinding {
		return $this->delete(entity: $binding);

	}//end remove()
}//end class
