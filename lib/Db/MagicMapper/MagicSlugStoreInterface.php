<?php

/**
 * MagicSlugStoreInterface: Reads and writes the slug column of a magic table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;

/**
 * Reads and writes the slug column of a magic table.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicSlugStoreInterface {
	/**
	 * The live objects whose slug starts with a prefix, as slug => uuid pairs.
	 *
	 * For the seed import to find an object stored under a suffixed slug (`<slug>-<14 digits>`);
	 * the caller keeps the rows that really are a suffixed variant. No RBAC or multitenancy
	 * filter, as for every slug uniqueness query.
	 *
	 * @param RegisterSchema $table  The register and schema whose table to use.
	 * @param string         $prefix The slug prefix, taken literally.
	 * @param int            $limit  Most rows to return.
	 *
	 * @return list<array{slug: string, uuid: string}> The matches.
	 */
	public function findSlugsByPrefix(RegisterSchema $table, string $prefix, int $limit=50): array;

	/**
	 * The slug an object keeps when a move or migration takes it to another register or schema (#4037).
	 *
	 * Resolved lazily, as on restore: the slug handler needs this mapper.
	 *
	 * @param ObjectEntity   $entity The object being moved (still carrying its uuid).
	 * @param RegisterSchema $table  The target register and schema.
	 *
	 * @return string|null The slug to store at the target.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function slugForMove(ObjectEntity $entity, RegisterSchema $table): ?string;

	/**
	 * Return the uuids of live objects that hold a slug in one register+schema table.
	 *
	 * No RBAC or multitenancy filter: uniqueness is a property of the data, so a
	 * caller who cannot see the holder must still not take its slug. Soft-deleted
	 * rows hold no slug, as on the read path.
	 *
	 * @param RegisterSchema $table       The register and schema whose table to use.
	 * @param string         $slug        The slug to look for.
	 * @param string|null $excludeUuid The object being saved, which never collides with itself.
	 *
	 * @return list<string> The holders' uuids (at most one: enough to know the slug is taken).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function findSlugHolders(RegisterSchema $table, string $slug, ?string $excludeUuid=null): array;

	/**
	 * Load the slug state a bulk save or a backfill decides against, in two queries.
	 *
	 * `holders` maps each requested slug to the live uuids holding it; `stored`
	 * maps each requested uuid to the slug its live row holds now (null when the
	 * row has none, absent when the row does not exist yet). The IN lists are
	 * split into chunks of 1,000 values. No access filter, as findSlugHolders().
	 *
	 * @param RegisterSchema $table The register and schema whose table to use.
	 * @param list<string>   $slugs Candidate slugs.
	 * @param list<string>   $uuids Uuids of the rows being decided.
	 *
	 * @return array{holders: array<string, list<string>>, stored: array<string, string|null>}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function findSlugState(RegisterSchema $table, array $slugs, array $uuids): array;

	/**
	 * List every slug held by more than one live object in a register+schema table.
	 *
	 * Slugs are compared case-folded; the report is keyed by the lower-cased slug.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array<string, list<string>> Slug => holder uuids, oldest first.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function findDuplicateSlugs(Register $register, Schema $schema): array;

	/**
	 * Every slug held by a live object in a register+schema table, with its holders.
	 *
	 * Only `_uuid` and `_slug` are read, so this stays cheap on large tables.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array<string, list<string>> Slug => holder uuids.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function findAllSlugs(Register $register, Schema $schema): array;

	/**
	 * Page over the live rows of a register+schema table for the slug backfill.
	 *
	 * Keyset-paginated on `_id` (stable while the run writes). Returns the uuid,
	 * the stored slug, `_created` for the oldest-first ordering the caller applies,
	 * and the business data the slug is built from.
	 *
	 * A row that cannot be converted into an object is left out of `rows` and
	 * logged with its uuid, but still counts in `fetched` and moves `lastId`: the
	 * caller pages on what the database returned, so one unreadable row neither
	 * ends the scan early nor is read again.
	 *
	 * @param Register $register   The register.
	 * @param Schema   $schema     The schema.
	 * @param int      $afterId    Last `_id` of the previous page (0 for the first).
	 * @param int      $pageSize   Rows per page.
	 *
	 * @return array{
	 *     rows: list<array{id: int, uuid: string, slug: string|null, created: string|null, object: array}>,
	 *     fetched: int,
	 *     lastId: int
	 * } The convertible rows, how many rows the query returned, and the `_id` of the last one (`$afterId` when none).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function findSlugBackfillPage(Register $register, Schema $schema, int $afterId, int $pageSize): array;

	/**
	 * Write one object's slug column directly, for the slug backfill.
	 *
	 * No version bump, audit-trail entry or object event: the slug is derived
	 * metadata, the same as openregister:backfill-system-owner (design D11).
	 *
	 * Only when the row still has no slug (null or empty), so a save that set
	 * the slug while the backfill was planning is not undone.
	 *
	 * @param RegisterSchema $table The register and schema whose table to use.
	 * @param string         $uuid  The object.
	 * @param string         $slug  The new slug.
	 *
	 * @return int Rows updated: 0 when the row changed in the meantime.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function updateSlugColumn(RegisterSchema $table, string $uuid, string $slug): int;
}//end interface
