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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The bulk side of the slug rules: two queries per group, then each row in batch order.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */
class ObjectSlugBatch {

	/**
	 * Constructor.
	 *
	 * @param ObjectSlugHandler $handler     The single-save rules this batch reuses.
	 * @param MagicMapper       $magicMapper Slug state queries.
	 * @param ObjectSlugLog     $log         Receives the warnings.
	 */
	public function __construct(
		private readonly ObjectSlugHandler $handler,
		private readonly MagicMapper $magicMapper,
		private readonly ObjectSlugLog $log,
	) {
	}//end __construct()

	/**
	 * Decide the slugs of a group, in batch order.
	 *
	 * @param array<int|string, array{uuid: ?string, clientSlug: ?string, data: array}> $rows     The group's rows.
	 * @param Register                                                                  $register The register.
	 * @param Schema                                                                    $schema   The schema (managed).
	 *
	 * @return array<int|string, string|null> Row index => slug.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function decide(array $rows, Register $register, Schema $schema): array {
		$wanted = $this->wanted(rows: $rows, schema: $schema);

		// Suffixed variants go into the same query: the single path checks a
		// suffixed slug against the database too (D5, BC6).
		$state = $this->magicMapper->findSlugState(
			register: $register,
			schema: $schema,
			slugs: array_values(array_unique(array_merge(array_filter($wanted['candidates']), array_values($wanted['suffixed'])))),
			uuids: array_values($wanted['uuids'])
		);
		// Keyed case-folded: on a case-insensitive collation the query returns
		// `hello` for the candidate `Hello`, and the single path (which compares in
		// SQL) sees that as taken too.
		$holders = [];
		foreach ($state['holders'] as $slug => $holderUuids) {
			$holders[$this->fold(slug: (string)$slug)] = $holderUuids;
		}

		$stored = $state['stored'];
		$decisions = [];
		foreach ($rows as $index => $row) {
			$decisions[$index] = $this->decideRow(
				row: $row,
				uuid: ($wanted['uuids'][$index] ?? null),
				candidate: ($wanted['candidates'][$index] ?? null),
				suffixed: ($wanted['suffixed'][$index] ?? null),
				register: $register,
				schema: $schema,
				holders: $holders,
				stored: $stored
			);
			if ($decisions[$index] !== null && ($wanted['uuids'][$index] ?? null) === null) {
				// A row without a uuid still takes the slug for the rest of the batch.
				$holders[$this->fold(slug: $decisions[$index])][] = ('batch-' . $index);
			}
		}

		return $decisions;
	}//end decide()

	/**
	 * Candidates, suffixed variants and uuids of every row.
	 *
	 * @param array<int|string, array{uuid: ?string, clientSlug: ?string, data: array}> $rows   The rows.
	 * @param Schema                                                                    $schema The schema.
	 *
	 * @return array{candidates: array<int|string, string|null>, suffixed: array<int|string, string>, uuids: array<int|string, string>}
	 */
	private function wanted(array $rows, Schema $schema): array {
		$suffixOn = ((($schema->getConfiguration() ?? [])['objectSlugSuffix'] ?? false) === true);
		// One stamp for the whole batch, so the suffixed slugs queried are
		// exactly the ones decide() would write.
		$stamp = $this->handler->currentStamp();
		$candidates = [];
		$suffixed = [];
		$uuids = [];
		foreach ($rows as $index => $row) {
			$candidates[$index] = $this->handler->candidate(
				objectData: $row['data'],
				schema: $schema,
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
	 * @param array{uuid: ?string, clientSlug: ?string, data: array} $row       The row.
	 * @param string|null                                           $uuid      Its uuid, when it has one.
	 * @param string|null                                           $candidate Its wanted slug.
	 * @param string|null                                           $suffixed  Its suffixed variant, when the suffix is on.
	 * @param Register                                              $register  The register.
	 * @param Schema                                                $schema    The schema.
	 * @param array<string, list<string>>                           $holders   Case-folded slug => holders, extended by this decision.
	 * @param array<string, string|null>                            $stored    uuid => stored slug, extended by this decision.
	 *
	 * @return string|null The slug to write, or null.
	 */
	private function decideRow(
		array $row,
		?string $uuid,
		?string $candidate,
		?string $suffixed,
		Register $register,
		Schema $schema,
		array &$holders,
		array &$stored,
	): ?string {
		$frozen = $this->frozenSlug(row: $row, uuid: $uuid, register: $register, schema: $schema, stored: $stored);
		if ($frozen !== null || $candidate === null) {
			return $frozen;
		}

		$decided = $this->handler->decide(
			candidate: $candidate,
			register: $register,
			schema: $schema,
			objectUuid: (string)($uuid ?? '?'),
			holderOf: function (string $slug) use (&$holders, $uuid): ?string {
				foreach ($holders[$this->fold(slug: $slug)] ?? [] as $holder) {
					if ($holder !== $uuid) {
						return $holder;
					}
				}

				return null;
			},
			path: 'bulk',
			suffixed: $suffixed
		);
		if ($decided !== null && $uuid !== null) {
			// Later rows of this batch see the slug as taken, and a later row
			// for the same object finds it frozen, as consecutive single saves would.
			$holders[$this->fold(slug: $decided)][] = $uuid;
			$stored[$uuid] = $decided;
		}

		return $decided;
	}//end decideRow()

	/**
	 * The slug a row's object already holds, logging a different client slug.
	 *
	 * @param array{uuid: ?string, clientSlug: ?string, data: array} $row      The row.
	 * @param string|null                                           $uuid     Its uuid.
	 * @param Register                                              $register The register.
	 * @param Schema                                                $schema   The schema.
	 * @param array<string, string|null>                            $stored   uuid => stored slug.
	 *
	 * @return string|null The frozen slug, or null when the object has none.
	 */
	private function frozenSlug(array $row, ?string $uuid, Register $register, Schema $schema, array $stored): ?string {
		if ($uuid === null || ($stored[$uuid] ?? null) === null) {
			return null;
		}

		$storedSlug = $stored[$uuid];
		$clientSlug = $row['clientSlug'];
		if ($this->handler->differsFromStored(clientSlug: $clientSlug, stored: $storedSlug) === true) {
			$this->log->ignoredClientSlug(
				register: $register,
				schema: $schema,
				objectUuid: $uuid,
				sent: $clientSlug,
				frozen: $storedSlug,
				path: 'bulk'
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
	 */
	public function fold(string $slug): string {
		return mb_strtolower(trim($slug));
	}//end fold()
}//end class
