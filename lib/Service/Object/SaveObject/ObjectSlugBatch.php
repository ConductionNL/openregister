<?php

/**
 * OpenRegister ObjectSlugBatch
 *
 * Decides the slugs of one register+schema group of a bulk save (#4037), with
 * the same rules ObjectSlugHandler applies to a single save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
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

/**
 * The bulk side of the slug rules: two queries per group, then each row in batch order.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */
class ObjectSlugBatch {

	/**
	 * Constructor.
	 *
	 * @param ObjectSlugHandler $handler The single-save rules this batch reuses.
	 * @param ObjectSlugStore   $store   Slug state queries.
	 * @param ObjectSlugLog     $log     Receives the warnings.
	 */
	public function __construct(
		private readonly ObjectSlugHandler $handler,
		private readonly ObjectSlugStore $store,
		private readonly ObjectSlugLog $log,
	) {
	}//end __construct()

	/**
	 * Decide the slugs of a group, in batch order.
	 *
	 * @param array<int|string, array{uuid: ?string, clientSlug: ?string, data: array}> $rows  The group's rows.
	 * @param ObjectSlugScope                                                           $scope Register and schema (managed).
	 *
	 * @return array<int|string, string|null> Row index => slug.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function decide(array $rows, ObjectSlugScope $scope): array {
		$wanted = $this->wanted(rows: $rows, scope: $scope);

		// Suffixed variants go into the same query: the single path checks a
		// suffixed slug against the database too (D5, BC6).
		$found = $this->store->state(
			scope: $scope,
			slugs: array_values(array_unique(array_merge(array_filter($wanted['candidates']), array_values($wanted['suffixed'])))),
			uuids: array_values($wanted['uuids'])
		);
		// Keyed case-folded: on a case-insensitive collation the query returns
		// `hello` for the candidate `Hello`, and the single path (which compares in
		// SQL) sees that as taken too.
		$state = ['holders' => [], 'stored' => $found['stored']];
		foreach ($found['holders'] as $slug => $holderUuids) {
			$state['holders'][$this->fold(slug: (string)$slug)] = $holderUuids;
		}

		$decisions = [];
		foreach ($rows as $index => $row) {
			$uuid = ($wanted['uuids'][$index] ?? null);
			$decisions[$index] = $this->decideRow(
				row: [
					'uuid' => $uuid,
					'clientSlug' => $row['clientSlug'],
					'candidate' => ($wanted['candidates'][$index] ?? null),
					'suffixed' => ($wanted['suffixed'][$index] ?? null),
				],
				scope: $scope,
				state: $state
			);
			if ($decisions[$index] !== null && $uuid === null) {
				// A row without a uuid still takes the slug for the rest of the batch.
				$state['holders'][$this->fold(slug: $decisions[$index])][] = ('batch-' . $index);
			}
		}

		return $decisions;
	}//end decide()

	/**
	 * Candidates, suffixed variants and uuids of every row.
	 *
	 * @param array<int|string, array{uuid: ?string, clientSlug: ?string, data: array}> $rows  The rows.
	 * @param ObjectSlugScope                                                           $scope Register and schema.
	 *
	 * @return array{candidates: array<int|string, string|null>, suffixed: array<int|string, string>, uuids: array<int|string, string>}
	 */
	private function wanted(array $rows, ObjectSlugScope $scope): array {
		$suffixOn = $scope->suffixOn();
		// One stamp for the whole batch, so the suffixed slugs queried are
		// exactly the ones decide() would write.
		$stamp = $this->handler->currentStamp();
		$candidates = [];
		$suffixed = [];
		$uuids = [];
		foreach ($rows as $index => $row) {
			$candidates[$index] = $this->handler->candidate(
				objectData: $row['data'],
				schema: $scope->schema,
				clientSlug: $row['clientSlug']
			);
			if ($suffixOn === true && $candidates[$index] !== null) {
				$suffixed[$index] = $this->handler->withSuffix(slug: $candidates[$index], stamp: $stamp);
			}

			if ($row['uuid'] !== null && $row['uuid'] !== '') {
				$uuids[$index] = $row['uuid'];
			}
		}

		return ['candidates' => $candidates, 'suffixed' => $suffixed, 'uuids' => $uuids];
	}//end wanted()

	/**
	 * Decide one row against the state the earlier rows left.
	 *
	 * @param array{uuid: ?string, clientSlug: ?string, candidate: ?string, suffixed: ?string} $row   The row: its uuid
	 *        (when it has one), what the client sent, its wanted slug and the suffixed variant (when the suffix is on).
	 * @param ObjectSlugScope                                                                 $scope Register and schema.
	 * @param array{holders: array<string, list<string>>, stored: array<string, string|null>}  $state Case-folded slug =>
	 *        holders and uuid => stored slug, both extended by this decision.
	 *
	 * @return string|null The slug to write, or null.
	 */
	private function decideRow(array $row, ObjectSlugScope $scope, array &$state): ?string {
		$uuid = $row['uuid'];
		$frozen = $this->frozenSlug(row: $row, scope: $scope, stored: $state['stored']);
		if ($frozen !== null || $row['candidate'] === null) {
			return $frozen;
		}

		$decided = $this->handler->decide(
			want: new ObjectSlugWant(slug: $row['candidate'], scope: $scope, objectUuid: (string)($uuid ?? '?'), path: 'bulk'),
			holderOf: fn (string $slug): ?string => $this->otherHolder(holders: $state['holders'], slug: $slug, uuid: $uuid),
			suffixed: $row['suffixed']
		);
		if ($decided !== null && $uuid !== null) {
			// Later rows of this batch see the slug as taken, and a later row
			// for the same object finds it frozen, as consecutive single saves would.
			$state['holders'][$this->fold(slug: $decided)][] = $uuid;
			$state['stored'][$uuid] = $decided;
		}

		return $decided;
	}//end decideRow()

	/**
	 * The first holder of a slug other than the object asking.
	 *
	 * @param array<string, list<string>> $holders Case-folded slug => holders.
	 * @param string                      $slug    The slug.
	 * @param string|null                 $uuid    The object asking.
	 *
	 * @return string|null The holder, or null when the slug is free for this object.
	 */
	private function otherHolder(array $holders, string $slug, ?string $uuid): ?string {
		foreach ($holders[$this->fold(slug: $slug)] ?? [] as $holder) {
			if ($holder !== $uuid) {
				return $holder;
			}
		}

		return null;
	}//end otherHolder()

	/**
	 * The slug a row's object already holds, logging a different client slug.
	 *
	 * @param array{uuid: ?string, clientSlug: ?string} $row    The row.
	 * @param ObjectSlugScope                          $scope  Register and schema.
	 * @param array<string, string|null>               $stored uuid => stored slug.
	 *
	 * @return string|null The frozen slug, or null when the object has none.
	 */
	private function frozenSlug(array $row, ObjectSlugScope $scope, array $stored): ?string {
		$uuid = $row['uuid'];
		if ($uuid === null || ($stored[$uuid] ?? null) === null) {
			return null;
		}

		$storedSlug = $stored[$uuid];
		if ($this->handler->differsFromStored(clientSlug: $row['clientSlug'], stored: $storedSlug) === true) {
			$this->log->ignoredClientSlug(
				want: new ObjectSlugWant(slug: $storedSlug, scope: $scope, objectUuid: $uuid, path: 'bulk'),
				sent: (string)$row['clientSlug']
			);
		}

		return $storedSlug;
	}//end frozenSlug()

	/**
	 * A slug as the database compares it: trimmed and case-folded.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function fold(string $slug): string {
		return mb_strtolower(trim($slug));
	}//end fold()
}//end class
