<?php

/**
 * Reads what one user personally holds: the views they own and the objects they watch.
 *
 * Read straight from the tables by owner, because the mappers' list reads
 * answer what a signed-in caller may see (public views of others, the
 * active organisation), and a user export is what the user HOLDS, read from
 * the command line where nobody is signed in.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\UserMigration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\UserMigration;

use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The owned views and the watches of one user.
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
 */
class PersonalStateReader {

	/**
	 * The saved views table.
	 *
	 * @var string
	 */
	public const VIEWS_TABLE = 'openregister_views';

	/**
	 * The table of calendar feed tokens.
	 *
	 * @var string
	 */
	public const FEED_TOKENS_TABLE = 'openregister_calendar_feeds';

	/**
	 * The table of timeline entries.
	 *
	 * @var string
	 */
	public const TIMELINE_TABLE = 'openregister_timeline_entries';

	/**
	 * Nextcloud's comments table, where OpenRegister notes live.
	 *
	 * @var string
	 */
	public const COMMENTS_TABLE = 'comments';

	/**
	 * The comments object type OpenRegister notes carry.
	 *
	 * @var string
	 */
	public const NOTES_OBJECT_TYPE = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * The views this user owns, oldest first.
	 *
	 * @param string $userId The user.
	 *
	 * @return list<View>
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
	 */
	public function ownedViews(string $userId): array {
		$views = [];
		foreach ($this->rowsOf(table: self::VIEWS_TABLE, column: 'owner', userId: $userId) as $row) {
			$views[] = View::fromRow($row);
		}

		return $views;
	}//end ownedViews()

	/**
	 * The objects this user watches (a favourite is a watch), oldest first.
	 *
	 * @param string $userId The user.
	 *
	 * @return list<Watcher>
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
	 */
	public function watches(string $userId): array {
		$watches = [];
		foreach ($this->rowsOf(table: WatcherMapperInterface::TABLE, column: 'user_id', userId: $userId) as $row) {
			$watches[] = Watcher::fromRow($row);
		}

		return $watches;
	}//end watches()

	/**
	 * The calendar feed tokens the user created, revoked ones included.
	 *
	 * @param string $userId The user.
	 *
	 * @return list<CalendarFeedToken>
	 *
	 * @spec openspec/changes/platform-user-migrator/tasks.md#task-1.3
	 */
	public function feedTokens(string $userId): array {
		$tokens = [];
		foreach ($this->rowsOf(table: self::FEED_TOKENS_TABLE, column: 'user_id', userId: $userId) as $row) {
			$tokens[] = CalendarFeedToken::fromRow($row);
		}

		return $tokens;
	}//end feedTokens()

	/**
	 * The timeline entries the user wrote.
	 *
	 * @param string $userId The user.
	 *
	 * @return list<TimelineEntry>
	 *
	 * @spec openspec/changes/platform-user-migrator/tasks.md#task-1.4
	 */
	public function timelineEntries(string $userId): array {
		$entries = [];
		foreach ($this->rowsOf(table: self::TIMELINE_TABLE, column: 'author', userId: $userId) as $row) {
			$entries[] = TimelineEntry::fromRow($row);
		}

		return $entries;
	}//end timelineEntries()

	/**
	 * The notes the user wrote on OpenRegister objects: their comments of the notes object type.
	 *
	 * @param string $userId The user.
	 *
	 * @return list<array{objectUuid: string, message: string, createdAt: string|null}>
	 *
	 * @spec openspec/changes/platform-user-migrator/tasks.md#task-1.4
	 */
	public function notes(string $userId): array {
		if ($userId === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('object_id', 'message', 'creation_timestamp')
			->from(self::COMMENTS_TABLE)
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter(self::NOTES_OBJECT_TYPE, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('actor_type', $qb->createNamedParameter('users', IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$notes = [];
		while (($row = $result->fetch()) !== false) {
			$notes[] = [
				'objectUuid' => (string)$row['object_id'],
				'message' => (string)$row['message'],
				'createdAt' => self::stringOrNull(value: ($row['creation_timestamp'] ?? null)),
			];
		}

		$result->closeCursor();

		return $notes;
	}//end notes()

	/**
	 * A stored value as a string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function stringOrNull(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end stringOrNull()

	/**
	 * The rows of a table that belong to the user, by id.
	 *
	 * @param string $table  The table.
	 * @param string $column The owner column.
	 * @param string $userId The user.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(string $table, string $column, string $userId): array {
		if ($userId === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = $row;
		}

		$result->closeCursor();

		return $rows;
	}//end rowsOf()
}//end class
