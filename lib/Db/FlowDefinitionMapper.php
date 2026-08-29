<?php

/**
 * Reads and writes pinned flow definitions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * Mapper for pinned flow definitions.
 *
 * @template-extends QBMapper<FlowDefinition>
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowDefinitionMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_defs', entityClass: FlowDefinition::class);

	}//end __construct()

	/**
	 * Find a definition by its hash.
	 *
	 * @param string $hash The canonical hash.
	 *
	 * @return FlowDefinition|null The definition, or null when unknown.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function findByHash(string $hash): ?FlowDefinition {
		if (trim($hash) === '') {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('hash', $qb->createNamedParameter($hash)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (Throwable $e) {
			return null;
		}

	}//end findByHash()

	/**
	 * Store a definition under its hash, or return the one already there.
	 *
	 * 🔑 THE INSERT CAN LOSE A RACE AND THAT IS FINE. Two workers pinning the
	 * same unedited flow at the same moment both miss on the read and both
	 * insert; the unique index rejects one. Because the hash IS the content,
	 * the row the loser then reads back is byte-identical to the one it tried
	 * to write — so re-reading is a complete recovery, not a compromise.
	 *
	 * @param string $hash       The canonical hash.
	 * @param string $definition The canonical definition JSON.
	 * @param string|null $flowUuid The originating flow, for provenance.
	 *
	 * @return FlowDefinition|null The stored definition, or null when it could not be stored.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function store(string $hash, string $definition, ?string $flowUuid = null): ?FlowDefinition {
		$existing = $this->findByHash(hash: $hash);
		if ($existing !== null) {
			return $existing;
		}

		$entity = new FlowDefinition();
		$entity->setHash($hash);
		$entity->setDefinition($definition);
		$entity->setFlowUuid($flowUuid);
		$entity->setCreated(new DateTime());

		try {
			return $this->insert(entity: $entity);
		} catch (Throwable $e) {
			// Lost the race, or the write failed. Re-read: on a race this
			// returns the winner's identical row; on a real failure it
			// returns null and the caller leaves the run unpinned rather
			// than failing the run outright.
			return $this->findByHash(hash: $hash);
		}

	}//end store()
}//end class
