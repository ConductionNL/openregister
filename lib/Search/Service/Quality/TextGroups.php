<?php

/**
 * OpenRegister TextGroups
 *
 * Groups files whose text signatures are near-identical.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Quality\TextSignature;

/**
 * Finds candidate pairs by locality-sensitive banding, confirms them by
 * estimated Jaccard similarity and joins the confirmed pairs into groups.
 *
 * Banding: the 128 MinHash values are cut into 32 bands of 4 rows, and two
 * files are a candidate pair only when one whole band agrees. Only candidate
 * pairs are compared, never all pairs. A pair at 0.9 similarity shares a band
 * with near certainty (1 - (1 - 0.9^4)^32); a pair at 0.3 almost never does.
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
 */
final class TextGroups {

	/**
	 * The number of bands a signature is cut into.
	 *
	 * @var int
	 */
	public const BANDS = 32;

	/**
	 * The number of signature values in a band.
	 *
	 * @var int
	 */
	public const ROWS = 4;

	/**
	 * The similarity at or above which two files are grouped, by default.
	 *
	 * @var float
	 */
	public const DEFAULT_THRESHOLD = 0.9;

	/**
	 * The lowest threshold accepted.
	 *
	 * @var float
	 */
	public const MIN_THRESHOLD = 0.5;

	/**
	 * The highest threshold accepted.
	 *
	 * @var float
	 */
	public const MAX_THRESHOLD = 1.0;

	/**
	 * How many pairs the last group() call compared.
	 *
	 * @var int
	 */
	private int $comparisons = 0;

	/**
	 * Constructor.
	 *
	 * @param TextSignature $signatures The signature arithmetic (the similarity estimate).
	 */
	public function __construct(private readonly TextSignature $signatures = new TextSignature()) {
	}//end __construct()

	/**
	 * A threshold, refused when it lies outside 0.5 to 1.
	 *
	 * @param float $threshold The threshold.
	 *
	 * @return float The threshold.
	 *
	 * @throws InvalidArgumentException When it lies outside the range.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function checkedThreshold(float $threshold): float {
		if (is_nan($threshold) === true || $threshold < self::MIN_THRESHOLD || $threshold > self::MAX_THRESHOLD) {
			throw new InvalidArgumentException('The text similarity threshold must lie between 0.5 and 1, got '.$threshold);
		}

		return $threshold;
	}//end checkedThreshold()

	/**
	 * Group the files whose signatures are at least the threshold alike.
	 *
	 * The representative of a group is its earliest created file (the lowest
	 * file id on a tie or without a date). Every member, the representative
	 * included, carries its estimated similarity to the representative and
	 * whether its normalised text is identical to the representative's.
	 * Files in no pair are in no group. Groups are ordered by representative.
	 *
	 * @param array<int, array{signature: list<int>, textSha256: string, created: DateTimeInterface|null}> $files     The signed files, keyed by file id.
	 * @param float                                                                                         $threshold The threshold, 0.5 to 1.
	 *
	 * @return list<array{representative: int, members: list<array{fileId: int, similarity: float, identicalText: bool}>}> The groups.
	 *
	 * @throws InvalidArgumentException When the threshold lies outside 0.5 to 1.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function group(array $files, float $threshold): array {
		$this->checkedThreshold(threshold: $threshold);
		$this->comparisons = 0;

		$parents = [];
		foreach ($this->candidatePairs(files: $files) as [$first, $second]) {
			$this->comparisons++;
			if ($this->signatures->estimate(first: $files[$first]['signature'], second: $files[$second]['signature']) >= $threshold) {
				self::union(parents: $parents, first: $first, second: $second);
			}
		}

		$clusters = [];
		foreach (array_keys($parents) as $fileId) {
			$clusters[self::find(parents: $parents, fileId: $fileId)][] = $fileId;
		}

		$groups = array_map(fn (array $members): array => $this->describe(files: $files, members: $members), array_values($clusters));
		usort($groups, static fn (array $left, array $right): int => $left['representative'] <=> $right['representative']);

		return $groups;
	}//end group()

	/**
	 * How many pairs the last group() call compared.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function comparisons(): int {
		return $this->comparisons;
	}//end comparisons()

	/**
	 * The distinct pairs that share at least one whole band, lower file id first.
	 *
	 * @param array<int, array{signature: list<int>, textSha256: string, created: DateTimeInterface|null}> $files The signed files.
	 *
	 * @return list<array{0: int, 1: int}> The pairs.
	 */
	private function candidatePairs(array $files): array {
		$buckets = [];
		foreach ($files as $fileId => $file) {
			foreach (self::bandKeys(signature: $file['signature']) as $key) {
				$buckets[$key][] = (int)$fileId;
			}
		}

		$pairs = [];
		foreach ($buckets as $members) {
			sort($members);
			$count = count($members);
			for ($i = 0; $i < $count; $i++) {
				for ($j = $i + 1; $j < $count; $j++) {
					$pairs[$members[$i].':'.$members[$j]] = [$members[$i], $members[$j]];
				}
			}
		}

		return array_values($pairs);
	}//end candidatePairs()

	/**
	 * The bucket key of each band of a signature.
	 *
	 * @param list<int> $signature The signature.
	 *
	 * @return list<string> One key per band, the band number included so equal values in different bands do not meet.
	 */
	private static function bandKeys(array $signature): array {
		$keys = [];
		for ($band = 0; $band < self::BANDS; $band++) {
			$rows = array_slice($signature, $band * self::ROWS, self::ROWS);
			if (count($rows) === self::ROWS) {
				$keys[] = $band.'|'.implode(',', $rows);
			}
		}

		return $keys;
	}//end bandKeys()

	/**
	 * One group: its representative and every member's similarity to it.
	 *
	 * @param array<int, array{signature: list<int>, textSha256: string, created: DateTimeInterface|null}> $files   The signed files.
	 * @param list<int>                                                                                     $members The file ids of the group.
	 *
	 * @return array{representative: int, members: list<array{fileId: int, similarity: float, identicalText: bool}>} The group.
	 */
	private function describe(array $files, array $members): array {
		usort($members, static fn (int $left, int $right): int => self::createdOrder(files: $files, left: $left, right: $right));
		$representative = $members[0];

		$described = [];
		foreach ($members as $fileId) {
			$described[] = [
				'fileId'        => $fileId,
				'similarity'    => $this->signatures->estimate(first: $files[$representative]['signature'], second: $files[$fileId]['signature']),
				'identicalText' => $files[$fileId]['textSha256'] === $files[$representative]['textSha256'],
			];
		}

		return ['representative' => $representative, 'members' => $described];
	}//end describe()

	/**
	 * Earliest created first; a file without a date after one with; then the lower file id.
	 *
	 * @param array<int, array{signature: list<int>, textSha256: string, created: DateTimeInterface|null}> $files The signed files.
	 * @param int                                                                                           $left  A file id.
	 * @param int                                                                                           $right Another file id.
	 *
	 * @return int The comparison.
	 */
	private static function createdOrder(array $files, int $left, int $right): int {
		$leftAt  = $files[$left]['created']?->getTimestamp() ?? PHP_INT_MAX;
		$rightAt = $files[$right]['created']?->getTimestamp() ?? PHP_INT_MAX;

		return [$leftAt, $left] <=> [$rightAt, $right];
	}//end createdOrder()

	/**
	 * Join the sets of two files.
	 *
	 * @param array<int, int> $parents The union-find parents, updated.
	 * @param int             $first   A file id.
	 * @param int             $second  Another file id.
	 *
	 * @return void
	 */
	private static function union(array &$parents, int $first, int $second): void {
		$parents[$first]  = $parents[$first] ?? $first;
		$parents[$second] = $parents[$second] ?? $second;
		$rootFirst  = self::find(parents: $parents, fileId: $first);
		$rootSecond = self::find(parents: $parents, fileId: $second);
		if ($rootFirst !== $rootSecond) {
			$parents[max($rootFirst, $rootSecond)] = min($rootFirst, $rootSecond);
		}
	}//end union()

	/**
	 * The root of a file's set, compressing the path on the way.
	 *
	 * @param array<int, int> $parents The union-find parents, updated.
	 * @param int             $fileId  The file id.
	 *
	 * @return int The root.
	 */
	private static function find(array &$parents, int $fileId): int {
		$root = $fileId;
		while (($parents[$root] ?? $root) !== $root) {
			$root = $parents[$root];
		}

		while ($fileId !== $root) {
			$next             = $parents[$fileId];
			$parents[$fileId] = $root;
			$fileId           = $next;
		}

		return $root;
	}//end find()
}//end class
