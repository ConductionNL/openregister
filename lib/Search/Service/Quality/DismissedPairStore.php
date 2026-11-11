<?php

/**
 * OpenRegister DismissedPairStore
 *
 * Reads and writes the `dismissed-pair` register: the pairs a person reviewed
 * and ruled not the same.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
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

namespace OCA\OpenRegister\Search\Service\Quality;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * The store behind "we already looked at these two, they are different people".
 *
 * DELIBERATELY IGNORANT OF SCORING. It knows uuids, actors, reasons and an
 * opaque fingerprint string; it never computes one. That is what keeps it out
 * of a dependency cycle with {@see DuplicateDetectionService}, which has to be
 * able to ask this store what to exclude while remaining the only thing that
 * knows how a fingerprint is made.
 *
 * A dismissal is a ROW, not a flag on one of the two objects. A flag would be
 * lost the moment either object was merged away, which is exactly when the
 * pair is most likely to come back around.
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
 */
class DismissedPairStore {

	/**
	 * Slug of the OR-owned dismissed-pair register.
	 *
	 * @var string
	 */
	public const REGISTER = 'dismissed-pair';

	/**
	 * Slug of the OR-owned dismissed-pair schema.
	 *
	 * @var string
	 */
	public const SCHEMA = 'dismissedPair';

	/**
	 * Upper bound on dismissals loaded per register/schema.
	 *
	 * @var int
	 */
	private const MAX_DISMISSALS = 5000;

	/**
	 * Wire collaborators.
	 *
	 * @param ObjectOperationsInterface $objectService Object read/write path (RBAC + tenant scoped, audited).
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Put two uuids into the canonical order a pair is stored in.
	 *
	 * A pair reviewed from either direction has to land on one row, so the
	 * order is the uuids' own string order and not the order the reviewer
	 * happened to open them in.
	 *
	 * @param string $first One uuid.
	 * @param string $second The other uuid.
	 *
	 * @return array{0: string, 1: string} The pair, ordered.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public static function canonical(string $first, string $second): array {
		$pair = [$first, $second];
		sort($pair, SORT_STRING);

		return [$pair[0], $pair[1]];
	}//end canonical()

	/**
	 * The key a pair is looked up by.
	 *
	 * @param string $first One uuid.
	 * @param string $second The other uuid.
	 *
	 * @return string The canonical key.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public static function key(string $first, string $second): string {
		[$a, $b] = self::canonical(first: $first, second: $second);

		return $a . '|' . $b;
	}//end key()

	/**
	 * The active dismissals for a register/schema, keyed by canonical pair key.
	 *
	 * A failed lookup answers an empty map, and the caller then offers every
	 * pair. That is the right way round: a dismissal store that is briefly
	 * unavailable should show a reviewer a pair they have already seen, never
	 * hide one they have not.
	 *
	 * @param string $registerSlug Register the pairs were reviewed in.
	 * @param string $schemaSlug Schema the pairs were reviewed under.
	 *
	 * @return array<string, array<string, mixed>> Dismissal rows by pair key.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function activeFor(string $registerSlug, string $schemaSlug): array {
		try {
			$objects = $this->objectService->findAll(
				[
					'filters' => $this->scopeFilters(registerSlug: $registerSlug, schemaSlug: $schemaSlug) + ['active' => true],
					'limit' => self::MAX_DISMISSALS,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('[DismissedPairStore] dismissal lookup failed: ' . $e->getMessage());
			return [];
		}

		return $this->rowsByPairKey(objects: $objects);
	}//end activeFor()

	/**
	 * The filters that scope a lookup to the dismissals of one register/schema.
	 *
	 * @param string $registerSlug Register the pairs were reviewed in.
	 * @param string $schemaSlug   Schema the pairs were reviewed under.
	 *
	 * @return array<string, string>
	 */
	private function scopeFilters(string $registerSlug, string $schemaSlug): array {
		return [
			'register' => self::REGISTER,
			'schema' => self::SCHEMA,
			'registerSlug' => $registerSlug,
			'schemaSlug' => $schemaSlug,
		];
	}//end scopeFilters()

	/**
	 * Key the dismissal rows by canonical pair; rows without both uuids are skipped.
	 *
	 * @param array<int, mixed> $objects The stored dismissal objects.
	 *
	 * @return array<string, array<string, mixed>> Dismissal rows (with the row's uuid as `id`) by pair key.
	 */
	private function rowsByPairKey(array $objects): array {
		$rows = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity === false) {
				continue;
			}

			$data = ($object->getObject() ?? []);
			$a = (string)($data['objectA'] ?? '');
			$b = (string)($data['objectB'] ?? '');
			if ($a === '' || $b === '') {
				continue;
			}

			$data['id'] = $object->getUuid();
			$rows[self::key(first: $a, second: $b)] = $data;
		}

		return $rows;
	}//end rowsByPairKey()

	/**
	 * Record that two objects were reviewed and are not the same.
	 *
	 * Re-dismissing a pair REPLACES its row rather than adding a second one:
	 * the question "is this pair dismissed, and against what values" has one
	 * answer, and two rows disagreeing about it is a bug waiting to be found
	 * by whichever one the scorer happened to read first.
	 *
	 * @param ReviewedPair  $pair      The two uuids and the register and schema they were reviewed under.
	 * @param PairDismissal $dismissal The compared-values fingerprint, the reason and the acting uid.
	 *
	 * @return array<string, mixed> The stored row.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function dismiss(ReviewedPair $pair, PairDismissal $dismissal): array {
		return $this->store(row: $this->dismissalRow(pair: $pair, verdict: $dismissal->verdict()), existing: $this->findRow(pair: $pair));
	}//end dismiss()

	/**
	 * The active dismissal row of a pair: the canonical pair, its scope, the verdict and when it was given.
	 *
	 * @param ReviewedPair         $pair    The two uuids and the register and schema they were reviewed under.
	 * @param array<string, mixed> $verdict The fingerprint, the reason and who dismissed it, in that order.
	 *
	 * @return array<string, mixed>
	 */
	private function dismissalRow(ReviewedPair $pair, array $verdict): array {
		[$a, $b] = self::canonical(first: $pair->first, second: $pair->second);
		$row = ['objectA' => $a, 'objectB' => $b, 'registerSlug' => $pair->registerSlug, 'schemaSlug' => $pair->schemaSlug];

		return $row + $verdict + ['dismissedAt' => (new DateTimeImmutable())->format(DATE_ATOM), 'active' => true];
	}//end dismissalRow()

	/**
	 * Save a dismissal row, over the existing one when there is one, and return it as stored with its id.
	 *
	 * @param array<string, mixed> $row      The row to save.
	 * @param ObjectEntity|null    $existing The pair's stored row, or null to create one.
	 *
	 * @return array<string, mixed>
	 */
	private function store(array $row, ?ObjectEntity $existing): array {
		$uuid = null;
		if ($existing !== null) {
			$uuid = (string)$existing->getUuid();
		}

		$saved = $this->objectService->saveObject(
			object: $row,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA),
				uuid: $uuid
			)
		);

		$stored = ($saved->getObject() ?? []);
		$stored['id'] = $saved->getUuid();

		return $stored;
	}//end store()

	/**
	 * Undo a dismissal, so the pair is offered again.
	 *
	 * The row is flipped to inactive rather than deleted, because the question
	 * a supervisor asks afterwards is "who decided these were different, and
	 * who changed their mind" — and a deleted row cannot answer the second
	 * half of it.
	 *
	 * @param ReviewedPair $pair The two uuids and the register and schema they were reviewed under.
	 * @param string $reversedBy Acting uid.
	 *
	 * @return array<string, mixed>|null The updated row, or null when no dismissal existed.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function undismiss(ReviewedPair $pair, string $reversedBy): ?array {
		$existing = $this->findRow(pair: $pair);
		if ($existing === null) {
			return null;
		}

		$row = ($existing->getObject() ?? []);
		$row['active'] = false;
		$row['reversedBy'] = $reversedBy;
		$row['reversedAt'] = (new DateTimeImmutable())->format(DATE_ATOM);

		return $this->store(row: $row, existing: $existing);
	}//end undismiss()

	/**
	 * Find the row for one canonical pair, active or not.
	 *
	 * @param ReviewedPair $pair The two uuids, in either order, and the register and schema they were reviewed under.
	 *
	 * @return ObjectEntity|null The row, or null when there is none.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	private function findRow(ReviewedPair $pair): ?ObjectEntity {
		[$first, $second] = self::canonical(first: $pair->first, second: $pair->second);
		$scope = $this->scopeFilters(registerSlug: $pair->registerSlug, schemaSlug: $pair->schemaSlug);
		try {
			$objects = $this->objectService->findAll(
				[
					'filters' => $scope + ['objectA' => $first, 'objectB' => $second],
					'limit' => 1,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('[DismissedPairStore] dismissal row lookup failed: ' . $e->getMessage());
			return null;
		}

		return $this->firstEntity(objects: $objects);
	}//end findRow()

	/**
	 * The first object entity in a lookup result.
	 *
	 * @param array<int, mixed> $objects The lookup result.
	 *
	 * @return ObjectEntity|null
	 */
	private function firstEntity(array $objects): ?ObjectEntity {
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				return $object;
			}
		}

		return null;
	}//end firstEntity()
}//end class
