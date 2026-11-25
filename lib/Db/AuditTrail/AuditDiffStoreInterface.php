<?php

/**
 * AuditDiffStoreInterface: one record's stored audit diffs, read and rewritten by an anonymisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\AuditTrail
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

namespace OCA\OpenRegister\Db\AuditTrail;

use OCA\OpenRegister\Db\AuditTrail;

/**
 * The stored diffs of one record's audit trail, and the entry that records an act on them.
 *
 * Narrow on purpose: the one place outside the audit writers that rewrites a
 * stored diff is the anonymisation, and it needs these three things only.
 */
interface AuditDiffStoreInterface {

	/**
	 * The stored diffs of one record, oldest first, keyed by audit row id.
	 *
	 * Retention tombstones are left out: their payload is already gone and their
	 * stored hash is what the chain carries across them.
	 *
	 * @param string $objectUuid The record.
	 *
	 * @return array<int, array<string, mixed>> The diffs, by row id.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function diffsOfObject(string $objectUuid): array;

	/**
	 * Replace one row's stored diff.
	 *
	 * The row's hash no longer matches afterwards; the caller re-seals the chain.
	 *
	 * @param int                  $id      The audit row id.
	 * @param array<string, mixed> $changed The diff to store.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function rewriteDiff(int $id, array $changed): void;

	/**
	 * Insert the entry that records an act on the trail.
	 *
	 * @param AuditTrail $entry The entry, not yet inserted.
	 *
	 * @return AuditTrail The inserted entry, carrying its id.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function recordAct(AuditTrail $entry): AuditTrail;
}//end interface
