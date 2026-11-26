<?php

/**
 * PublishedDigestProbes: the consistency probe for published files whose bytes changed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCA\OpenRegister\Db\FileDigestMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Lists the files that are public now and no longer match the digest they were published with.
 *
 * The nightly `PublishedDigestVerifyJob` and the public download both mark the
 * open record of such a file (`FileDigestMapper::flagMismatch()`); this probe
 * reads those marks in the shape {@see ConsistencyCheckService} runs, so the
 * read-only guard covers it like every other probe.
 *
 * A changed published file has no repair the console can make on its own: the
 * file is either republished (a new record, sealing the new bytes) or
 * unpublished, and which of the two is an officer's decision. The probe names
 * that, and the repair service refuses it rather than guessing.
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */
class PublishedDigestProbes {

	/**
	 * Repair kind: none the console can make; someone decides per file.
	 */
	public const REPAIR_DECIDE_PER_FILE = 'decide-per-file';

	/**
	 * The slug of the probe.
	 */
	public const CHANGED = 'published-file-changed';

	/**
	 * The probes, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>> The probes.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
	 */
	public function probes(): array {
		return [
			self::CHANGED => [
				'title' => 'Published files that changed after publication',
				'description' => 'A file that is public now whose bytes no longer match the digest it was published with. Its public download is refused until it is republished or unpublished.',
				'repair' => 'Republish or unpublish each file; which of the two is decided per file.',
				'repairKind' => self::REPAIR_DECIDE_PER_FILE,
				'repairColumn' => 'file_id',
				'table' => FileDigestMapper::TABLE,
				'query' => self::changedQuery(...),
			],
		];

	}//end probes()

	/**
	 * Select the open records marked as no longer matching their file.
	 *
	 * @param IQueryBuilder $qb The query builder to build on.
	 *
	 * @return IQueryBuilder The query.
	 */
	private static function changedQuery(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select('id', 'file_id', 'object_uuid', 'sha256', 'mismatch_sha256', 'mismatch_at')
			->from(FileDigestMapper::TABLE)
			->where($qb->expr()->isNull('unpublished_at'))
			->andWhere($qb->expr()->isNotNull('mismatch_at'));

	}//end changedQuery()
}//end class
