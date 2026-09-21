<?php

/**
 * Mapper for ExportProfile entities.
 *
 * Owner-scoped by surface: read scoping (own rows versus admin-all) is enforced
 * by `ExportProfileService` and `ExportProfilesController`, not here. Backed by
 * `openregister_export_profiles`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ExportProfileMapper
 *
 * @template-extends QBMapper<ExportProfile>
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */
class ExportProfileMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_export_profiles', entityClass: ExportProfile::class);
	}//end __construct()

	/**
	 * Find a profile by id.
	 *
	 * @param int $id The profile id.
	 *
	 * @return ExportProfile The profile.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When more than one row matches.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function find(int $id): ExportProfile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find a profile by its stable public identifier.
	 *
	 * @param string $uuid The profile uuid.
	 *
	 * @return ExportProfile The profile.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When more than one row matches.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function findByUuid(string $uuid): ExportProfile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * Find every profile owned by a user.
	 *
	 * @param string $owner The owning Nextcloud user id.
	 *
	 * @return ExportProfile[] The profiles.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function findByOwner(string $owner): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByOwner()

	/**
	 * Find every profile (admin listing).
	 *
	 * @return ExportProfile[] The profiles.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findAll()
}//end class
