<?php

/**
 * The preservation lens on a list: preserved objects stay out of the working views.
 *
 * An object in the preservation regime (its archival status is semi-static)
 * has left business use; the Archiefwet clock governs it. Like an archived
 * object it leaves the working views by default, and a caller asks for it
 * explicitly. The parameter follows `_archived`: absent or false is the
 * working set, `_preserved=true` is preserved objects alone, `_preserved=any`
 * is both. Anything else reads as the working set, because an unrecognised
 * lens must never silently widen what a list shows.
 *
 * The lens applies only to a schema that offers the regime
 * (`x-openregister-preserve`). On any other schema no object is in the
 * regime, so the working set is every row and the preserved lens is empty.
 *
 * The state lives in the `_tmlo` metadata column (`archiefstatus`), the field
 * the object already carries, so the clause reads that JSON member in the
 * platform's own spelling.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Which preserved objects a list shows, and the SQL clause that says so.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
 */
class PreservedListLens {

	/**
	 * The query parameter a caller sends.
	 *
	 * @var string
	 */
	public const PARAMETER = '_preserved';

	/**
	 * The working set: no preserved object. The default.
	 *
	 * @var string
	 */
	public const EXCLUDE = 'exclude';

	/**
	 * Preserved objects and nothing else.
	 *
	 * @var string
	 */
	public const ONLY = 'only';

	/**
	 * Both at once. The literal is the value callers send (`_preserved=any`).
	 *
	 * @var string
	 */
	public const ANY = 'any';

	/**
	 * The metadata column that holds the archival status.
	 *
	 * @var string
	 */
	public const COLUMN = '_tmlo';

	/**
	 * The lens a query asks for.
	 *
	 * ⚠️ `filter_var(..., FILTER_VALIDATE_BOOLEAN)` alone maps `"any"` to
	 * false, so `any` is matched as a word first, as `_archived` does.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return string One of EXCLUDE, ONLY and ANY.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
	 */
	public function modeFor(array $query): string {
		if (array_key_exists(self::PARAMETER, $query) === false) {
			return self::EXCLUDE;
		}

		$raw = $query[self::PARAMETER];
		if (is_string($raw) === true && strtolower(trim($raw)) === self::ANY) {
			return self::ANY;
		}

		if (is_scalar($raw) === true && filter_var($raw, FILTER_VALIDATE_BOOLEAN) === true) {
			return self::ONLY;
		}

		return self::EXCLUDE;
	}//end modeFor()

	/**
	 * The clause for a listing, or null when it puts no condition on the list.
	 *
	 * @param PreservedListing $listing      The mode, the schema and the table it reads.
	 * @param string           $columnPrefix The table alias with its dot, or empty for an unaliased UNION member.
	 *
	 * @return string|null The predicate, without a leading AND.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
	 */
	public function clauseFor(PreservedListing $listing, string $columnPrefix): ?string {
		if ($listing->mode === self::ANY) {
			return null;
		}

		if ($this->offered(schema: $listing->schema) === false || $listing->columnMissing === true) {
			// No object of this table is in the regime: the working set is
			// every row and the preserved lens is empty.
			if ($listing->mode === self::ONLY) {
				return '1=0';
			}

			return null;
		}

		$status = 'LOWER(' . $this->statusSql(column: $columnPrefix . self::COLUMN, platform: $listing->platform) . ')';
		$states = $this->statesSql();

		if ($listing->mode === self::ONLY) {
			return $status . ' IN (' . $states . ')';
		}

		return 'COALESCE(' . $status . ", '') NOT IN (" . $states . ')';
	}//end clauseFor()

	/**
	 * Whether the schema offers the preservation regime.
	 *
	 * @param Schema|null $schema The schema listed.
	 *
	 * @return bool
	 */
	private function offered(?Schema $schema): bool {
		return $schema !== null && $schema->isPreservationEnabled() === true;
	}//end offered()

	/**
	 * The archival status as SQL text, NULL when the column holds none.
	 *
	 * @param string $column   The column, with its prefix.
	 * @param string $platform The platform short name: postgres, mysql or sqlite.
	 *
	 * @return string
	 */
	private function statusSql(string $column, string $platform): string {
		if ($platform === 'postgres') {
			return $column . "::jsonb ->> 'archiefstatus'";
		}

		if ($platform === 'sqlite') {
			return 'CASE WHEN json_valid(' . $column . ") THEN json_extract(" . $column . ", '$.archiefstatus') END";
		}

		return 'CASE WHEN JSON_VALID(' . $column . ") THEN JSON_UNQUOTE(JSON_EXTRACT(" . $column . ", '$.archiefstatus')) END";
	}//end statusSql()

	/**
	 * The preserved archival statuses as a quoted SQL list.
	 *
	 * @return string
	 */
	private function statesSql(): string {
		return implode(
			', ',
			array_map(static fn (string $state): string => "'" . $state . "'", ObjectEntity::PRESERVED_ARCHIVAL_STATUSES)
		);
	}//end statesSql()
}//end class
