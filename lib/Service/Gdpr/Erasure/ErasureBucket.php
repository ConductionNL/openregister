<?php

/**
 * ErasureBucket — the closed vocabulary an erasure preview answers in.
 *
 * A preview that reports one number ("we will erase 12 things") cannot be used
 * to answer a data subject, because the interesting half is what the erasure
 * will NOT touch and why. Three buckets and four count kinds are the whole
 * vocabulary, named here once so the preview, the run and the tests all read
 * the same words.
 *
 * A member outside this list is a bucket nobody can honour, which is why the
 * list is closed rather than free strings on an array key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

/**
 * The buckets, count kinds and grounds an erasure preview speaks in.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */
final class ErasureBucket {
	/**
	 * The object itself goes: destroyed through the recorded destruction.
	 *
	 * @var string
	 */
	public const ERASABLE = 'erasable';

	/**
	 * The object stays, the subject's values in it do not.
	 *
	 * @var string
	 */
	public const PSEUDONYMISED = 'pseudonymised';

	/**
	 * Nothing happens to it, and the preview says under which ground.
	 *
	 * @var string
	 */
	public const PROTECTED = 'protected';

	/**
	 * Every bucket, in the order a person reads them.
	 *
	 * @var array<int, string>
	 */
	public const BUCKETS = [
		self::ERASABLE,
		self::PSEUDONYMISED,
		self::PROTECTED,
	];

	/**
	 * Count kind: the objects themselves.
	 *
	 * @var string
	 */
	public const OBJECTS = 'objects';

	/**
	 * Count kind: files in the objects' bound folders.
	 *
	 * @var string
	 */
	public const FILES = 'files';

	/**
	 * Count kind: timeline rows drawn beside the objects.
	 *
	 * @var string
	 */
	public const TIMELINE = 'timelineEntries';

	/**
	 * Count kind: party records naming the subject inside the objects.
	 *
	 * @var string
	 */
	public const PARTIES = 'partyRecords';

	/**
	 * Every count kind the preview reports, per bucket.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = [
		self::OBJECTS,
		self::FILES,
		self::TIMELINE,
		self::PARTIES,
	];

	/**
	 * Ground: the retention rules could not be read, so the record was left
	 * alone and named.
	 *
	 * The archival guard already answers `SCHEMA_UNRESOLVED` when it can
	 * resolve the question and the answer is "no schema". This ground is the
	 * other unresolvable: the guard itself could not answer at all. Both count
	 * as protected, per D-2, because erasing on an unknown is the one failure
	 * that cannot be undone.
	 *
	 * @var string
	 */
	public const GROUND_UNRESOLVABLE = 'HOLD_UNRESOLVABLE';

	/**
	 * Ground: the object sits in an immutable archival status.
	 *
	 * @var string
	 */
	public const GROUND_IMMUTABLE = 'ARCHIVAL_STATUS_IMMUTABLE';

	/**
	 * Ground a whole-object erasure is downgraded to a pseudonymisation under:
	 * the record also holds another person's data.
	 *
	 * @var string
	 */
	public const GROUND_SHARED_RECORD = 'SHARED_RECORD';

	/**
	 * A zeroed count block, one entry per bucket per kind.
	 *
	 * @return array<string, array<string, int>> Bucket → kind → zero.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public static function emptyCounts(): array {
		$counts = [];
		foreach (self::BUCKETS as $bucket) {
			foreach (self::KINDS as $kind) {
				$counts[$bucket][$kind] = 0;
			}
		}

		return $counts;
	}//end emptyCounts()

	/**
	 * Whether a string names a bucket this vocabulary knows.
	 *
	 * @param string|null $bucket The candidate bucket.
	 *
	 * @return bool True when the bucket is a member.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public static function isMember(?string $bucket): bool {
		return in_array(needle: (string)$bucket, haystack: self::BUCKETS, strict: true);
	}//end isMember()
}//end class
