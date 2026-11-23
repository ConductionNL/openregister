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

use DateTime;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Kernel\Contracts\RecentLensInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
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
	 * The app whose user values hold the notification preferences.
	 *
	 * @var string
	 */
	public const PREFERENCES_APP = 'openregister';

	/**
	 * The user-value key prefixes of a user's own notification preferences:
	 * the global value and the value pinned to a scope. Group defaults are the
	 * group's, stored in app config, and never part of a user's export.
	 *
	 * @var list<string>
	 */
	public const PREFERENCE_PREFIXES = ['notification_pref/', 'notif_pref_s/'];

	/**
	 * The longest user-value key Nextcloud stores.
	 *
	 * @var int
	 */
	public const MAX_PREFERENCE_KEY_LENGTH = 64;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection            $db          The database.
	 * @param IConfig|null             $config      The user values holding the notification preferences.
	 * @param RecentLensInterface|null $readHistory The read history behind "recently opened".
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ?IConfig $config = null,
		private readonly ?RecentLensInterface $readHistory = null,
	) {
	}//end __construct()

	/**
	 * The objects the user opened most recently, newest first: uuid and moment, never the object.
	 *
	 * Read from the audit trail as the `_recent` lens reads it; an audit trail
	 * that is off or unreadable answers none.
	 *
	 * @param string $userId The user.
	 *
	 * @return list<array{objectUuid: string, openedAt: string}>
	 *
	 * @spec openspec/changes/platform-user-migrator/tasks.md#task-1.2
	 */
	public function recents(string $userId): array {
		if ($userId === '' || $this->readHistory === null) {
			return [];
		}

		$lens = $this->readHistory->resolveRecentLens(userId: $userId);
		if (($lens['available'] ?? false) !== true) {
			return [];
		}

		$recents = [];
		foreach (($lens['views'] ?? []) as $uuid => $openedAt) {
			$recents[] = ['objectUuid' => (string)$uuid, 'openedAt' => (string)$openedAt];
		}

		return $recents;
	}//end recents()

	/**
	 * The user's own notification preferences: each stored override, global or scoped, by its key.
	 *
	 * A value that is not a JSON object or list is not a preference and is left out.
	 *
	 * @param string $userId The user.
	 *
	 * @return list<array{key: string, value: array<mixed>}>
	 *
	 * @spec openspec/changes/platform-user-migrator/tasks.md#task-1.2
	 */
	public function notificationPreferences(string $userId): array {
		if ($userId === '' || $this->config === null) {
			return [];
		}

		$preferences = [];
		foreach ($this->config->getUserKeys($userId, self::PREFERENCES_APP) as $key) {
			if (self::isPreferenceKey(key: (string)$key) === false) {
				continue;
			}

			$value = json_decode($this->config->getUserValue($userId, self::PREFERENCES_APP, (string)$key, ''), true);
			if (is_array($value) === false) {
				continue;
			}

			$preferences[] = ['key' => (string)$key, 'value' => $value];
		}

		return $preferences;
	}//end notificationPreferences()

	/**
	 * Whether a user-value key is one of a user's own notification preferences.
	 *
	 * @param string $key The key.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/platform-user-migrator/tasks.md#task-1.2
	 */
	public static function isPreferenceKey(string $key): bool {
		if (strlen($key) > self::MAX_PREFERENCE_KEY_LENGTH) {
			return false;
		}

		foreach (self::PREFERENCE_PREFIXES as $prefix) {
			if (strlen($key) > strlen($prefix) && str_starts_with($key, $prefix) === true) {
				return true;
			}
		}

		return false;
	}//end isPreferenceKey()

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
	 * The calendar feed tokens the user created, revoked ones included: their details, never their value.
	 *
	 * @param string $userId The user.
	 *
	 * @return list<array{label: string|null, scopeType: string|null, scopeId: string|null, createdAt: DateTime|null, expiresAt: DateTime|null, revokedAt: DateTime|null, lastReadAt: DateTime|null}>
	 *
	 * @spec openspec/changes/platform-user-migrator/tasks.md#task-1.3
	 */
	public function feedTokens(string $userId): array {
		$tokens = [];
		foreach ($this->rowsOf(table: self::FEED_TOKENS_TABLE, column: 'user_id', userId: $userId) as $row) {
			$tokens[] = [
				'label' => self::stringOrNull(value: ($row['label'] ?? null)),
				'scopeType' => self::stringOrNull(value: ($row['scope_type'] ?? null)),
				'scopeId' => self::stringOrNull(value: ($row['scope_id'] ?? null)),
				'createdAt' => self::momentOrNull(value: ($row['created_at'] ?? null)),
				'expiresAt' => self::momentOrNull(value: ($row['expires_at'] ?? null)),
				'revokedAt' => self::momentOrNull(value: ($row['revoked_at'] ?? null)),
				'lastReadAt' => self::momentOrNull(value: ($row['last_read_at'] ?? null)),
			];
		}

		return $tokens;
	}//end feedTokens()

	/**
	 * The timeline entries the user wrote.
	 *
	 * @param string $userId The user.
	 *
	 * @return list<array{objectUuid: string|null, register: string|null, schema: string|null, kind: string|null, visibility: string, message: string|null, created: DateTime|null}>
	 *
	 * @spec openspec/changes/platform-user-migrator/tasks.md#task-1.4
	 */
	public function timelineEntries(string $userId): array {
		$entries = [];
		foreach ($this->rowsOf(table: self::TIMELINE_TABLE, column: 'author', userId: $userId) as $row) {
			$entries[] = [
				'objectUuid' => self::stringOrNull(value: ($row['object_uuid'] ?? null)),
				'register' => self::stringOrNull(value: ($row['register'] ?? null)),
				'schema' => self::stringOrNull(value: ($row['schema'] ?? null)),
				'kind' => self::stringOrNull(value: ($row['kind'] ?? null)),
				'visibility' => (self::stringOrNull(value: ($row['visibility'] ?? null)) ?? 'internal'),
				'message' => self::stringOrNull(value: ($row['message'] ?? null)),
				'created' => self::momentOrNull(value: ($row['created'] ?? null)),
			];
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
	 * A stored moment as a date, or null, read as a datetime column of an entity is.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTime|null
	 */
	private static function momentOrNull(mixed $value): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		if ($value instanceof DateTime) {
			return $value;
		}

		return new DateTime((string)$value);
	}//end momentOrNull()

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
