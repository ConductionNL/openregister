<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Following and favourites become one feature: a follow with a notify switch.
 *
 * `openregister_watchers` gains `notify`, and every favourite moves in as a
 * follow with `notify` false: a star was silent, so the follow it becomes is
 * quiet. When the user already followed the object, the follow wins and keeps
 * notifying (the union rule, design D-2 of `merge-follow-and-favourites`).
 *
 * The copy runs in postSchemaChange, after this step has added the column and
 * before Version1Date20261009130100 drops the favourites table. It is
 * idempotent: a rerun finds every (user, object) already present and skips it.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-favourites-became-follows-with-notifications-off
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `notify` to the follow table and moves the favourites in.
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-favourites-became-follows-with-notifications-off
 */
class Version1Date20261009130000 extends SimpleMigrationStep {

	/**
	 * The follow table, which survives.
	 */
	public const WATCHERS_TABLE = 'openregister_watchers';

	/**
	 * The favourites table, which is emptied into it.
	 */
	public const FAVOURITES_TABLE = 'openregister_favourites';

	/**
	 * How many favourites are read per page.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection Database connection, for the copy.
	 */
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}//end __construct()

	/**
	 * Add the `notify` column when it is absent.
	 *
	 * Nullable, because Nextcloud refuses a NOT NULL boolean (Oracle cannot
	 * store one); the entity reads a null as "on", so no follow written before
	 * this column existed loses its notifications.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::WATCHERS_TABLE) === false) {
			return null;
		}

		$table = $schema->getTable(self::WATCHERS_TABLE);
		if ($table->hasColumn('notify') === true) {
			return null;
		}

		$table->addColumn('notify', Types::BOOLEAN, ['notnull' => false, 'default' => true]);

		return $schema;

	}//end changeSchema()

	/**
	 * Copy every favourite in as a quiet follow, an existing follow winning.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-favourites-became-follows-with-notifications-off
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->connection->tableExists(self::FAVOURITES_TABLE) === false
			|| $this->connection->tableExists(self::WATCHERS_TABLE) === false
		) {
			return;
		}

		$moved = 0;
		$kept = 0;
		$lastId = 0;
		do {
			$page = $this->favouritesAfter(lastId: $lastId);
			foreach ($page as $row) {
				$lastId = max($lastId, (int)$row['id']);
				if ($this->follows(userId: (string)$row['user_id'], objectUuid: (string)$row['object_uuid']) === true) {
					$kept++;
					continue;
				}

				$this->insertQuietFollow(row: $row);
				$moved++;
			}

			$full = (count($page) === self::PAGE);
		} while ($full === true);

		$output->info(
			sprintf('merge-follow-and-favourites: %d favourites became quiet follows, %d were already follows', $moved, $kept)
		);

	}//end postSchemaChange()

	/**
	 * One page of favourites, by id.
	 *
	 * @param int $lastId The highest id already handled.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function favouritesAfter(int $lastId): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'user_id', 'object_uuid', 'register', 'schema', 'created')
			->from(self::FAVOURITES_TABLE)
			->where($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults(self::PAGE);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;

	}//end favouritesAfter()

	/**
	 * Whether the user already follows the object.
	 *
	 * @param string $userId The user.
	 * @param string $objectUuid The object.
	 *
	 * @return boolean True when a follow row exists.
	 */
	private function follows(string $userId, string $objectUuid): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id')
			->from(self::WATCHERS_TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$found = $result->fetch();
		$result->closeCursor();

		return ($found !== false);

	}//end follows()

	/**
	 * Write one favourite as a follow with notifications off.
	 *
	 * @param array<string, mixed> $row The favourite row.
	 *
	 * @return void
	 */
	private function insertQuietFollow(array $row): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(self::WATCHERS_TABLE)
			->values(
				[
					'user_id' => $qb->createNamedParameter((string)$row['user_id']),
					'object_uuid' => $qb->createNamedParameter((string)$row['object_uuid']),
					'register' => $qb->createNamedParameter($row['register'] ?? null),
					'schema' => $qb->createNamedParameter($row['schema'] ?? null),
					'created' => $qb->createNamedParameter($row['created']),
					'notify' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				]
			);
		$qb->executeStatement();

	}//end insertQuietFollow()
}//end class
