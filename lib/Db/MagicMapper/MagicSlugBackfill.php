<?php

/**
 * MagicSlugBackfill: one page of the slug backfill.
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

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Read one page of rows without a slug as the backfill planner reads them.
 *
 * A row that cannot be converted into an object is left out of `rows` and
 * logged with its uuid, but still counts in `fetched` and moves `lastId`: the
 * caller pages on what the database returned, so one unreadable row neither
 * ends the scan early nor is read again.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
final class MagicSlugBackfill {
	/**
	 * Constructor.
	 *
	 * @param MagicSlugTable                     $table   The table.
	 * @param Closure(array): (ObjectEntity|null) $convert Row to object.
	 * @param LoggerInterface                    $logger  The logger.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function __construct(
		private readonly MagicSlugTable $table,
		private readonly Closure $convert,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The page after an `_id`.
	 *
	 * @param int $afterId  Last `_id` of the previous page (0 for the first).
	 * @param int $pageSize Rows per page.
	 *
	 * @return array{
	 *     rows: list<array{id: int, uuid: string, slug: string|null, created: string|null, object: array}>,
	 *     fetched: int,
	 *     lastId: int
	 * } The convertible rows, how many rows the query returned, and the `_id` of the last one (`$afterId` when none).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function page(int $afterId, int $pageSize): array {
		$page = ['rows' => [], 'fetched' => 0, 'lastId' => $afterId];
		foreach ($this->table->backfillRows(afterId: $afterId, pageSize: $pageSize) as $row) {
			$page['fetched']++;
			$page['lastId'] = (int)$row['_id'];
			$entry = $this->entry(row: $row);
			if ($entry !== null) {
				$page['rows'][] = $entry;
			}
		}

		return $page;
	}//end page()

	/**
	 * One row as the planner reads it, or null (logged) when it cannot be converted.
	 *
	 * @param array<string, mixed> $row The table row.
	 *
	 * @return array{id: int, uuid: string, slug: string|null, created: string|null, object: array}|null
	 */
	private function entry(array $row): ?array {
		$entity = ($this->convert)($row);
		if ($entity === null) {
			$this->logSkipped(row: $row);
			return null;
		}

		return [
			'id' => (int)$row['_id'],
			'uuid' => (string)$row['_uuid'],
			'slug' => $this->slug(row: $row),
			'created' => $this->created(row: $row),
			'object' => $entity->getObject() ?? [],
		];
	}//end entry()

	/**
	 * Log a row left out of the page.
	 *
	 * @param array<string, mixed> $row The table row.
	 *
	 * @return void
	 */
	private function logSkipped(array $row): void {
		$this->logger->warning(
			sprintf(
				'[ObjectSlug] Backfill skipped object %s in table %s: the row could not be converted into an object',
				(string)($row['_uuid'] ?? '?'),
				$this->table->name()
			),
			['app' => 'openregister', 'path' => 'backfill']
		);
	}//end logSkipped()

	/**
	 * The stored slug of a row.
	 *
	 * @param array<string, mixed> $row The table row.
	 *
	 * @return string|null
	 */
	private function slug(array $row): ?string {
		$slug = ($row['_slug'] ?? null);
		if ($slug === null || $slug === '') {
			return null;
		}

		return (string)$slug;
	}//end slug()

	/**
	 * The creation time of a row.
	 *
	 * @param array<string, mixed> $row The table row.
	 *
	 * @return string|null
	 */
	private function created(array $row): ?string {
		$created = ($row['_created'] ?? null);
		if ($created === null) {
			return null;
		}

		return (string)$created;
	}//end created()
}//end class
