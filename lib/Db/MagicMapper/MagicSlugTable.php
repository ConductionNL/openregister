<?php

/**
 * MagicSlugTable: the slug column of one magic table.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Generator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read and fill the slugs of one register+schema table.
 *
 * No RBAC or multitenancy filter on any of these: uniqueness is a property of
 * the data, so a caller who cannot see a holder must still not take its slug.
 * Soft-deleted rows hold no slug, as on the read path.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */
final class MagicSlugTable {
	private const SLUG = '_slug';

	private const UUID = '_uuid';

	private const ID = '_id';

	private const DELETED = '_deleted';

	private const CREATED = '_created';

	/**
	 * The most values in one IN list.
	 *
	 * @var int
	 */
	private const IN_CHUNK = 1000;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db        The connection.
	 * @param string        $tableName The magic table.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function __construct(private readonly IDBConnection $db, private readonly string $tableName) {
	}//end __construct()

	/**
	 * The name of the table.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function name(): string {
		return $this->tableName;
	}//end name()

	/**
	 * The live objects whose slug starts with a prefix.
	 *
	 * @param string $prefix The prefix, taken literally.
	 * @param int    $limit  Most rows to return.
	 *
	 * @return list<array{slug: string, uuid: string}> The matches.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function byPrefix(string $prefix, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SLUG, self::UUID)
			->from($this->tableName)
			->where($qb->expr()->like(self::SLUG, $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')))
			->andWhere($qb->expr()->isNull(self::DELETED))
			->orderBy(self::ID, 'ASC')
			->setMaxResults($limit);
		$matches = [];
		foreach ($this->fetched(qb: $qb) as $row) {
			$matches[] = ['slug' => (string)$row[self::SLUG], 'uuid' => (string)$row[self::UUID]];
		}

		return $matches;
	}//end byPrefix()

	/**
	 * The uuid of a live holder of a slug.
	 *
	 * @param string      $slug        The slug.
	 * @param string|null $excludeUuid The object being saved, which never collides with itself.
	 *
	 * @return list<string> At most one uuid: enough to know the slug is taken.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function holders(string $slug, ?string $excludeUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::UUID)
			->from($this->tableName)
			->where($qb->expr()->eq(self::SLUG, $qb->createNamedParameter($slug)))
			->andWhere($qb->expr()->isNull(self::DELETED))
			->setMaxResults(1);
		if ($excludeUuid !== null) {
			$qb->andWhere($qb->expr()->neq(self::UUID, $qb->createNamedParameter($excludeUuid)));
		}

		return array_map(static fn (array $row): string => (string)$row[self::UUID], iterator_to_array($this->fetched(qb: $qb), false));
	}//end holders()

	/**
	 * The holders of slugs and the stored slug of uuids, in two queries.
	 *
	 * @param list<string> $slugs Candidate slugs.
	 * @param list<string> $uuids Uuids of the rows being decided.
	 *
	 * @return array{holders: array<string, list<string>>, stored: array<string, string|null>}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function state(array $slugs, array $uuids): array {
		$state = ['holders' => [], 'stored' => []];
		foreach ($this->liveRows(column: self::SLUG, values: $slugs) as $row) {
			$state['holders'][(string)$row[self::SLUG]][] = (string)$row[self::UUID];
		}

		foreach ($this->liveRows(column: self::UUID, values: $uuids) as $row) {
			$state['stored'][(string)$row[self::UUID]] = $this->filled(value: $row[self::SLUG]);
		}

		return $state;
	}//end state()

	/**
	 * Every slug held by more than one live object.
	 *
	 * @return array<string, list<string>> Lower-cased slug => holder uuids, oldest first.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function duplicates(): array {
		$duplicates = [];
		foreach (array_chunk($this->duplicateKeys(), self::IN_CHUNK) as $chunk) {
			foreach ($this->fetched(qb: $this->holdersOfKeys(keys: $chunk)) as $row) {
				$duplicates[mb_strtolower((string)$row[self::SLUG])][] = (string)$row[self::UUID];
			}
		}

		return $duplicates;
	}//end duplicates()

	/**
	 * Every slug held by a live object, with its holders.
	 *
	 * Only `_uuid` and `_slug` are read, so this stays cheap on large tables.
	 *
	 * @return array<string, list<string>> Slug => holder uuids.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function all(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::UUID, self::SLUG)
			->from($this->tableName)
			->where($qb->expr()->isNotNull(self::SLUG))
			->andWhere($qb->expr()->neq(self::SLUG, $qb->createNamedParameter('')))
			->andWhere($qb->expr()->isNull(self::DELETED));
		$slugs = [];
		foreach ($this->fetched(qb: $qb) as $row) {
			$slugs[(string)$row[self::SLUG]][] = (string)$row[self::UUID];
		}

		return $slugs;
	}//end all()

	/**
	 * The next page of live rows without a slug, in `_id` order.
	 *
	 * Only rows without a slug: a set slug is never rewritten by the backfill.
	 *
	 * @param int $afterId  Last `_id` of the previous page.
	 * @param int $pageSize Rows per page.
	 *
	 * @return Generator<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function backfillRows(int $afterId, int $pageSize): Generator {
		$idCol = 't.' . self::ID;
		$slugCol = 't.' . self::SLUG;
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName, 't')
			->where($qb->expr()->gt($idCol, $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('t.' . self::DELETED))
			->andWhere($qb->expr()->orX($qb->expr()->isNull($slugCol), $qb->expr()->eq($slugCol, $qb->createNamedParameter(''))))
			->orderBy($idCol, 'ASC')
			->setMaxResults($pageSize);

		return $this->fetched(qb: $qb);
	}//end backfillRows()

	/**
	 * Write one object's slug, only when the row still has none.
	 *
	 * A save that set the slug while the backfill was planning is not undone.
	 *
	 * @param string $uuid The object.
	 * @param string $slug The new slug.
	 *
	 * @return int Rows updated: 0 when the row changed in the meantime.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function fillEmpty(string $uuid, string $slug): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set(self::SLUG, $qb->createNamedParameter($slug))
			->where($qb->expr()->eq(self::UUID, $qb->createNamedParameter($uuid)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull(self::SLUG),
					$qb->expr()->eq(self::SLUG, $qb->createNamedParameter(''))
				)
			);

		return $qb->executeStatement();
	}//end fillEmpty()

	/**
	 * The live rows whose column holds one of the values, in chunks.
	 *
	 * @param string            $column The column.
	 * @param array<int, mixed> $values The values; empty ones are left out.
	 *
	 * @return Generator<int, array<string, mixed>> The rows.
	 */
	private function liveRows(string $column, array $values): Generator {
		$wanted = array_values(array_unique(array_filter($values, static fn ($value) => $value !== null && $value !== '')));
		foreach (array_chunk($wanted, self::IN_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select(self::UUID, self::SLUG)
				->from($this->tableName)
				->where($qb->expr()->in($column, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->isNull(self::DELETED));
			yield from $this->fetched(qb: $qb);
		}
	}//end liveRows()

	/**
	 * The case-folded slugs more than one live object holds.
	 *
	 * On a case-insensitive collation `Hello-World` and `hello-world` are one
	 * slug, and the save paths compare them that way.
	 *
	 * @return list<string>
	 */
	private function duplicateKeys(): array {
		$qb = $this->db->getQueryBuilder();
		$folded = $qb->func()->lower(self::SLUG);
		$qb->selectAlias($folded, 'slug_key')
			->from($this->tableName)
			->where($qb->expr()->isNotNull(self::SLUG))
			->andWhere($qb->expr()->neq(self::SLUG, $qb->createNamedParameter('')))
			->andWhere($qb->expr()->isNull(self::DELETED))
			->groupBy($folded)
			->having('COUNT(*) > 1');

		return array_map(static fn (array $row): string => (string)$row['slug_key'], iterator_to_array($this->fetched(qb: $qb), false));
	}//end duplicateKeys()

	/**
	 * The query for the live holders of case-folded slugs, oldest first.
	 *
	 * @param list<string> $keys The case-folded slugs.
	 *
	 * @return IQueryBuilder The query.
	 */
	private function holdersOfKeys(array $keys): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::UUID, self::SLUG)
			->from($this->tableName)
			->where($qb->expr()->in($qb->func()->lower(self::SLUG), $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNull(self::DELETED))
			->orderBy(self::CREATED, 'ASC')
			->addOrderBy(self::ID, 'ASC');

		return $qb;
	}//end holdersOfKeys()

	/**
	 * The rows of a query, read one by one.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return Generator<int, array<string, mixed>> The rows.
	 */
	private function fetched(IQueryBuilder $qb): Generator {
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			yield $row;
		}

		$result->closeCursor();
	}//end fetched()

	/**
	 * A stored slug, or null when empty.
	 *
	 * @param mixed $value The column value.
	 *
	 * @return string|null
	 */
	private function filled(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end filled()
}//end class
