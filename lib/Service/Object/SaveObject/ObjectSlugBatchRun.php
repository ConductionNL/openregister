<?php

/**
 * OpenRegister ObjectSlugBatchRun
 *
 * The slug state one bulk save group decides against, row after row (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Closure;

/**
 * Case-folded slug holders and stored slugs of one group, extended by every decision.
 *
 * Keyed case-folded: on a case-insensitive collation the query returns `hello`
 * for the candidate `Hello`, and the single path (which compares in SQL) sees
 * that as taken too.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */
final class ObjectSlugBatchRun {

	/**
	 * Case-folded slug => uuids (or `batch-<index>` markers) holding it.
	 *
	 * @var array<string, list<string>>
	 */
	private array $holders = [];

	/**
	 * Uuid => the slug the object holds.
	 *
	 * @var array<string, string|null>
	 */
	private array $stored;

	/**
	 * Constructor.
	 *
	 * @param ObjectSlugScope                                                               $scope Register and schema (managed).
	 * @param array{holders: array<string, list<string>>, stored: array<string, string|null>} $found What the database holds.
	 * @param Closure                                                                       $fold  fn(string $slug): string — a
	 *                                                                                             slug as the database compares it.
	 */
	public function __construct(
		public readonly ObjectSlugScope $scope,
		array $found,
		private readonly Closure $fold,
	) {
		$this->stored = $found['stored'];
		foreach ($found['holders'] as $slug => $holderUuids) {
			$this->holders[$this->key(slug: (string)$slug)] = $holderUuids;
		}
	}//end __construct()

	/**
	 * The key a slug is held under.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string The case-folded key.
	 */
	private function key(string $slug): string {
		return ($this->fold)($slug);
	}//end key()

	/**
	 * The slug an object already holds.
	 *
	 * @param string|null $uuid The object, null when the row has no uuid.
	 *
	 * @return string|null The stored slug, or null when there is none.
	 */
	public function storedSlug(string|null $uuid): string|null {
		if ($uuid === null) {
			return null;
		}

		return ($this->stored[$uuid] ?? null);
	}//end storedSlug()

	/**
	 * The first holder of a slug other than the object asking.
	 *
	 * @param string      $slug The slug.
	 * @param string|null $uuid The object asking.
	 *
	 * @return string|null The holder, or null when the slug is free for this object.
	 */
	public function otherHolder(string $slug, string|null $uuid): string|null {
		foreach ($this->holders[$this->key(slug: $slug)] ?? [] as $holder) {
			if ($holder !== $uuid) {
				return $holder;
			}
		}

		return null;
	}//end otherHolder()

	/**
	 * Record a decided slug, so later rows of the batch see it as taken.
	 *
	 * A row with a uuid also gets the slug as its stored one, so a later row for
	 * the same object finds it frozen, as consecutive single saves would. A row
	 * without a uuid holds it under its `batch-<index>` marker.
	 *
	 * @param array{uuid: string|null, batchHolder: string} $row  The row.
	 * @param string|null                                   $slug The decision.
	 *
	 * @return void
	 */
	public function claim(array $row, string|null $slug): void {
		if ($slug === null) {
			return;
		}

		if ($row['uuid'] === null) {
			$this->holders[$this->key(slug: $slug)][] = $row['batchHolder'];
			return;
		}

		$this->holders[$this->key(slug: $slug)][] = $row['uuid'];
		$this->stored[$row['uuid']] = $slug;
	}//end claim()
}//end class
