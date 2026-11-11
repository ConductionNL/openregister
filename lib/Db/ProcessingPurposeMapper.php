<?php

/**
 * Mapper for the administered purpose list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Reads and writes administered purposes.
 *
 * @template-extends QBMapper<ProcessingPurpose>
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
class ProcessingPurposeMapper extends QBMapper implements ProcessingPurposeMapperInterface {
	use SelectByColumnTrait;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_processing_purposes',
			entityClass: ProcessingPurpose::class
		);
	}//end __construct()

	/**
	 * Find by primary key.
	 *
	 * @param int $id Primary key.
	 *
	 * @return ProcessingPurpose The purpose.
	 *
	 * @throws DoesNotExistException When no row matches the id.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function find(int $id): ProcessingPurpose {
		return $this->findEntity(query: $this->selectWhere(column: new LinkColumn(name: 'id', value: $id)));
	}//end find()

	/**
	 * Find by the code a caller names on a request.
	 *
	 * @param string $code The purpose code.
	 *
	 * @return ProcessingPurpose|null Null when no row matches.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function findByCode(string $code): ?ProcessingPurpose {
		$found = $this->findOneByFilledColumn(name: 'code', value: $code);
		if ($found instanceof ProcessingPurpose) {
			return $found;
		}

		return null;
	}//end findByCode()

	/**
	 * Find by uuid.
	 *
	 * @param string $uuid The purpose uuid.
	 *
	 * @return ProcessingPurpose|null Null when no row matches.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function findByUuid(string $uuid): ?ProcessingPurpose {
		$found = $this->findOneByFilledColumn(name: 'uuid', value: $uuid);
		if ($found instanceof ProcessingPurpose) {
			return $found;
		}

		return null;
	}//end findByUuid()

	/**
	 * Resolve a reference that may be a code or a uuid.
	 *
	 * Code first, exactly as {@see VerwerkingsactiviteitMapper::resolveReference()},
	 * so an administrator may write either form wherever a purpose is named.
	 *
	 * @param string $reference The reference (code or uuid).
	 *
	 * @return ProcessingPurpose|null Null when nothing matches.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function resolveReference(string $reference): ?ProcessingPurpose {
		if ($reference === '') {
			return null;
		}

		$byCode = $this->findByCode(code: $reference);
		if ($byCode !== null) {
			return $byCode;
		}

		return $this->findByUuid(uuid: $reference);
	}//end resolveReference()

	/**
	 * List purposes, newest first, optionally filtered.
	 *
	 * @param string|null $status Optional lifecycle filter.
	 * @param string|null $organisationId Optional organisation filter.
	 *
	 * @return ProcessingPurpose[] The matching purposes.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function findAll(?string $status = null, ?string $organisationId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('code', 'ASC');

		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		if ($organisationId !== null && $organisationId !== '') {
			$qb->andWhere($qb->expr()->eq('organisation_id', $qb->createNamedParameter($organisationId)));
		}

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * Insert a purpose, filling the uuid and the timestamps.
	 *
	 * @param ProcessingPurpose $entity The purpose to insert.
	 *
	 * @return ProcessingPurpose The persisted purpose.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function insert($entity): ProcessingPurpose {
		(new NewRowStamps())->onInsert(entity: $entity);

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Update a purpose, moving its change time.
	 *
	 * @param ProcessingPurpose $entity The purpose to update.
	 *
	 * @return ProcessingPurpose The persisted purpose.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function update($entity): ProcessingPurpose {
		$entity->setUpdated(new DateTime());

		return parent::update(entity: $entity);
	}//end update()
}//end class
