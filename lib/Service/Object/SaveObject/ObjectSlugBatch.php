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
		$plan = $this->wanted(rows: $rows, scope: $scope);
		$run = new ObjectSlugBatchRun(scope: $scope, found: $this->state(plan: $plan, scope: $scope), fold: $this->fold(...));

		$decisions = [];
		foreach ($plan as $index => $row) {
			$decisions[$index] = $this->decideRow(row: $row, run: $run);
		}

		return $decisions;
	}//end decide()

	/**
	 * Holders of every wanted slug and the stored slugs of every row's object, in one query.
	 *
	 * Suffixed variants go into the same query: the single path checks a
	 * suffixed slug against the database too (D5, BC6).
	 *
	 * @param array<int|string, array{uuid: ?string, candidate: ?string, suffixed: ?string}> $plan  The planned rows.
	 * @param ObjectSlugScope                                                              $scope Register and schema.
	 *
	 * @return array{holders: array<string, list<string>>, stored: array<string, string|null>}
	 */
	private function state(array $plan, ObjectSlugScope $scope): array {
		$candidates = array_filter(array_map(static fn (array $row): mixed => $row['candidate'], $plan));
		$suffixed = array_filter(array_map(static fn (array $row): mixed => $row['suffixed'], $plan), static fn (mixed $slug): bool => $slug !== null);
		$uuids = array_filter(array_map(static fn (array $row): mixed => $row['uuid'], $plan), static fn (mixed $uuid): bool => $uuid !== null);

		return $this->store->state(
			scope: $scope,
			slugs: array_values(array_unique(array_merge($candidates, array_values($suffixed)))),
			uuids: array_values($uuids)
		);
	}//end state()

	/**
	 * Every row's uuid, wanted slug and suffixed variant.
	 *
	 * One stamp for the whole batch, so the suffixed slugs queried are exactly
	 * the ones decide() would write.
	 *
	 * @param array<int|string, array{uuid: ?string, clientSlug: ?string, data: array}> $rows  The rows.
	 * @param ObjectSlugScope                                                           $scope Register and schema.
	 *
	 * @return array<int|string, array{uuid: ?string, clientSlug: ?string, candidate: ?string, suffixed: ?string, batchHolder: string}>
	 */
	private function wanted(array $rows, ObjectSlugScope $scope): array {
		$stamp = $this->batchStamp(scope: $scope);
		$plan = [];
		foreach ($rows as $index => $row) {
			$candidate = $this->handler->candidate(objectData: $row['data'], schema: $scope->schema, clientSlug: $row['clientSlug']);
			$plan[$index] = [
				'uuid' => $this->nonEmpty(uuid: $row['uuid']),
				'clientSlug' => $row['clientSlug'],
				'candidate' => $candidate,
				'suffixed' => $this->suffixedOf(candidate: $candidate, stamp: $stamp),
				'batchHolder' => ('batch-' . $index),
			];
		}

		return $plan;
	}//end wanted()

	/**
	 * The stamp of the batch's suffixed slugs, or null when the schema has no suffix.
	 *
	 * @param ObjectSlugScope $scope Register and schema.
	 *
	 * @return string|null The `YmdHis` stamp, or null.
	 */
	private function batchStamp(ObjectSlugScope $scope): string|null {
		$suffixOn = $scope->suffixOn();
		$stamp = $this->handler->currentStamp();
		if ($suffixOn === false) {
			return null;
		}

		return $stamp;
	}//end batchStamp()

	/**
	 * The suffixed variant of a candidate, or null without a candidate or a suffix.
	 *
	 * @param string|null $candidate The wanted slug.
	 * @param string|null $stamp     The batch stamp, null when the schema has no suffix.
	 *
	 * @return string|null The suffixed slug, or null.
	 */
	private function suffixedOf(string|null $candidate, string|null $stamp): string|null {
		if ($candidate === null || $stamp === null) {
			return null;
		}

		return $this->handler->withSuffix(slug: $candidate, stamp: $stamp);
	}//end suffixedOf()

	/**
	 * A row's uuid, or null when it has none (an empty uuid counts as none).
	 *
	 * @param string|null $uuid The uuid as sent.
	 *
	 * @return string|null The uuid, or null.
	 */
	private function nonEmpty(string|null $uuid): string|null {
		if ($uuid === null || $uuid === '') {
			return null;
		}

		return $uuid;
	}//end nonEmpty()

	/**
	 * Decide one row against the state the earlier rows left, and claim the outcome.
	 *
	 * @param array{uuid: ?string, clientSlug: ?string, candidate: ?string, suffixed: ?string, batchHolder: string} $row The
	 *        planned row: its uuid (when it has one), what the client sent, its wanted slug and the suffixed variant.
	 * @param ObjectSlugBatchRun                                                                                 $run The
	 *        group's holders and stored slugs, extended by this decision.
	 *
	 * @return string|null The slug to write, or null.
	 */
	private function decideRow(array $row, ObjectSlugBatchRun $run): string|null {
		$frozen = $this->frozenSlug(row: $row, run: $run);
		if ($frozen !== null || $row['candidate'] === null) {
			return $frozen;
		}

		$decided = $this->handler->decide(
			want: new ObjectSlugWant(slug: $row['candidate'], scope: $run->scope, objectUuid: (string)($row['uuid'] ?? '?'), path: 'bulk'),
			holderOf: static fn (string $slug): string|null => $run->otherHolder(slug: $slug, uuid: $row['uuid']),
			suffixed: $row['suffixed']
		);
		$run->claim(row: $row, slug: $decided);

		return $decided;
	}//end decideRow()

	/**
	 * The slug a row's object already holds, logging a different client slug.
	 *
	 * @param array{uuid: ?string, clientSlug: ?string} $row The row.
	 * @param ObjectSlugBatchRun                       $run The group's stored slugs.
	 *
	 * @return string|null The frozen slug, or null when the object has none.
	 */
	private function frozenSlug(array $row, ObjectSlugBatchRun $run): string|null {
		$storedSlug = $run->storedSlug(uuid: $row['uuid']);
		if ($storedSlug === null) {
			return null;
		}

		if ($this->handler->differsFromStored(clientSlug: $row['clientSlug'], stored: $storedSlug) === true) {
			$this->log->ignoredClientSlug(
				want: new ObjectSlugWant(slug: $storedSlug, scope: $run->scope, objectUuid: (string)$row['uuid'], path: 'bulk'),
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
