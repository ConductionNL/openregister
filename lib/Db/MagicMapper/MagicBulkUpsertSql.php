<?php

/**
 * OpenRegister MagicBulkUpsertSql
 *
 * The per-column pieces of the bulk upsert statement that depend on the
 * platform and on managed object slugs (#4037), kept out of MagicBulkHandler so
 * they can be asserted without a database.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;

/**
 * Builds the `ON DUPLICATE KEY UPDATE` / `ON CONFLICT DO UPDATE` assignment of one column.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */
class MagicBulkUpsertSql {

	/**
	 * Whether a schema has opted into managed object slugs (`objectSlugField`).
	 *
	 * Mirrors ObjectSlugHandler::isManaged(); the bulk writer only needs the fact.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True when `objectSlugField` is a non-empty string.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public static function hasManagedSlugs(Schema $schema): bool {
		$field = ($schema->getConfiguration() ?? [])['objectSlugField'] ?? null;
		return is_string($field) === true && trim($field) !== '';
	}//end hasManagedSlugs()

	/**
	 * The value of a row's `_slug` column.
	 *
	 * For a managed schema the slug decision is final, including an explicit
	 * null: falling back to a top-level `slug` property would undo a collision
	 * (#4037). Any other schema keeps the legacy fallback.
	 *
	 * @param array<string, mixed> $selfData    The object's `@self` metadata.
	 * @param array<string, mixed> $object      The raw object payload.
	 * @param bool                 $managedSlug Whether the schema has managed slugs.
	 *
	 * @return mixed The slug, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A fact about the schema, not a mode of the method.
	 */
	public static function rowSlug(array $selfData, array $object, bool $managedSlug): mixed {
		if ($managedSlug === true && array_key_exists('slug', $selfData) === true) {
			return $selfData['slug'];
		}

		return ($selfData['slug'] ?? $object['slug'] ?? null);
	}//end rowSlug()

	/**
	 * The assignment that updates one column when the row already exists.
	 *
	 * With managed slugs the `_slug` assignment keeps a stored slug and only
	 * fills an empty one (a stored slug is frozen, #4037): the slug decision
	 * already keeps it, and this guard also covers a row whose slug was written
	 * between that decision and the upsert.
	 *
	 * @param string $column        The column name.
	 * @param bool   $isPostgres    True for `ON CONFLICT`, false for MySQL/MariaDB.
	 * @param string $fullTableName The table name, qualifying the stored value on PostgreSQL.
	 * @param bool   $freezeSlug    Whether the schema has managed slugs.
	 *
	 * @return string The assignment, e.g. "`_title` = VALUES(`_title`)".
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Platform and schema facts the one statement varies on.
	 */
	public static function updateClause(string $column, bool $isPostgres, string $fullTableName, bool $freezeSlug): string {
		if ($column === '_updated') {
			return self::touchClause(isPostgres: $isPostgres);
		}

		if ($column === '_slug' && $freezeSlug === true) {
			return self::frozenSlugClause(isPostgres: $isPostgres, fullTableName: $fullTableName);
		}

		if ($isPostgres === true) {
			return "\"{$column}\" = EXCLUDED.\"{$column}\"";
		}

		return "`{$column}` = VALUES(`{$column}`)";
	}//end updateClause()

	/**
	 * The assignment that stamps `_updated` with the time of the write.
	 *
	 * @param bool $isPostgres True for `ON CONFLICT`, false for MySQL/MariaDB.
	 *
	 * @return string The assignment.
	 */
	private static function touchClause(bool $isPostgres): string {
		if ($isPostgres === true) {
			return '"_updated" = NOW()';
		}

		return '`_updated` = NOW()';
	}//end touchClause()

	/**
	 * The `_slug` assignment that keeps a stored slug and only fills an empty one.
	 *
	 * @param bool   $isPostgres    True for `ON CONFLICT`, false for MySQL/MariaDB.
	 * @param string $fullTableName The table name, qualifying the stored value on PostgreSQL.
	 *
	 * @return string The assignment.
	 */
	private static function frozenSlugClause(bool $isPostgres, string $fullTableName): string {
		if ($isPostgres === true) {
			return "\"_slug\" = COALESCE(NULLIF(\"{$fullTableName}\".\"_slug\", ''), EXCLUDED.\"_slug\")";
		}

		return '`_slug` = COALESCE(NULLIF(`_slug`, \'\'), VALUES(`_slug`))';
	}//end frozenSlugClause()
}//end class
